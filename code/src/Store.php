<?php
declare(strict_types=1);

/**
 * SQLite-backed store mapping ResellerClub transactions to Razorpay orders.
 *
 * Statuses: created (open attempt) → paid | failed, or superseded when a newer
 * attempt replaces it. At most one 'created' attempt exists per ResellerClub
 * transid (enforced by a unique index), so two concurrent requests can't both
 * create chargeable orders.
 *
 * rc_notified = the signed result page was shown to the customer's browser.
 * rc_confirmed = an operator confirmed ResellerClub actually recorded it.
 */
final class Store
{
    private PDO $db;

    public function __construct(string $path)
    {
        $this->db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA busy_timeout = 5000');
        $this->db->exec("CREATE TABLE IF NOT EXISTS transactions (
            token               TEXT PRIMARY KEY,
            rc_transid          TEXT NOT NULL,
            rc_userid           TEXT,
            rc_usertype         TEXT,
            rc_transactiontype  TEXT,
            rc_invoiceids       TEXT,
            rc_description      TEXT,
            selling_amount      TEXT NOT NULL,
            accounting_amount   TEXT NOT NULL,
            redirect_url        TEXT NOT NULL,
            amount_minor        INTEGER NOT NULL,
            currency            TEXT NOT NULL,
            razorpay_order_id   TEXT UNIQUE,
            razorpay_payment_id TEXT,
            status              TEXT NOT NULL DEFAULT 'created',
            rc_notified         INTEGER NOT NULL DEFAULT 0,
            created_at          TEXT NOT NULL,
            updated_at          TEXT NOT NULL
        )");
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_rc_transid ON transactions (rc_transid)');
        $this->migrate();
    }

    /** Columns and indexes added after the first release; safe to run on every request. */
    private function migrate(): void
    {
        $cols = array_column($this->db->query('PRAGMA table_info(transactions)')->fetchAll(), 'name');
        foreach (['mode' => 'TEXT', 'rc_confirmed' => 'INTEGER NOT NULL DEFAULT 0', 'rc_notified_at' => 'TEXT'] as $col => $type) {
            if (!in_array($col, $cols, true)) {
                $this->db->exec("ALTER TABLE transactions ADD COLUMN $col $type");
            }
        }
        $hasIndex = (bool) $this->db->query("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = 'uniq_open_attempt'")->fetchColumn();
        if (!$hasIndex) {
            $this->db->beginTransaction();
            // Older versions could leave several open attempts per transid: keep the newest.
            $this->db->exec("UPDATE transactions SET status = 'superseded'
                WHERE status = 'created' AND rowid NOT IN (
                    SELECT MAX(rowid) FROM transactions WHERE status = 'created' GROUP BY rc_transid)");
            $this->db->exec("CREATE UNIQUE INDEX uniq_open_attempt ON transactions (rc_transid) WHERE status = 'created'");
            $this->db->commit();
        }
    }

    /**
     * Claim the single open-attempt slot for a transid. Returns false if another
     * request already holds it (the caller should reuse that attempt instead).
     */
    public function reserve(array $row): bool
    {
        $now = date('c');
        $row += ['status' => 'created', 'created_at' => $now, 'updated_at' => $now];
        $cols = array_keys($row);
        try {
            $this->db->prepare('INSERT INTO transactions (' . implode(',', $cols) . ') VALUES (:' . implode(',:', $cols) . ')')->execute($row);
            return true;
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                return false;
            }
            throw $e;
        }
    }

    /** Kept for tests and tools that insert rows directly. */
    public function create(array $row): void
    {
        if (!$this->reserve($row)) {
            throw new RuntimeException('An open attempt already exists for this transaction');
        }
    }

    public function findByToken(string $token): ?array
    {
        $st = $this->db->prepare('SELECT * FROM transactions WHERE token = ?');
        $st->execute([$token]);
        return $st->fetch() ?: null;
    }

    public function findByOrderId(string $orderId): ?array
    {
        $st = $this->db->prepare('SELECT * FROM transactions WHERE razorpay_order_id = ?');
        $st->execute([$orderId]);
        return $st->fetch() ?: null;
    }

    /** The open attempt for a ResellerClub transid, if any. */
    public function findOpenByTransId(string $transId): ?array
    {
        $st = $this->db->prepare("SELECT * FROM transactions WHERE rc_transid = ? AND status = 'created' LIMIT 1");
        $st->execute([$transId]);
        return $st->fetch() ?: null;
    }

    /** A settled (paid) attempt for a ResellerClub transid, if any. */
    public function findPaidByTransId(string $transId): ?array
    {
        $st = $this->db->prepare("SELECT * FROM transactions WHERE rc_transid = ? AND status = 'paid' ORDER BY updated_at DESC LIMIT 1");
        $st->execute([$transId]);
        return $st->fetch() ?: null;
    }

    public function findByTransId(string $transId): array
    {
        $st = $this->db->prepare('SELECT * FROM transactions WHERE rc_transid = ? ORDER BY created_at');
        $st->execute([$transId]);
        return $st->fetchAll();
    }

    public function update(string $token, array $fields): void
    {
        $fields['updated_at'] = date('c');
        $set = implode(',', array_map(fn($c) => "$c = :$c", array_keys($fields)));
        $fields['token'] = $token;
        $this->db->prepare("UPDATE transactions SET $set WHERE token = :token")->execute($fields);
    }

    /** The signed success page was shown to the customer (not proof ResellerClub received it). */
    public function markResultShown(string $token): void
    {
        $this->update($token, ['rc_notified' => 1, 'rc_notified_at' => date('c')]);
    }

    /**
     * Paid live transactions ResellerClub may not know about:
     * - 'never_shown': the customer never reached the result page — act on these;
     * - 'unconfirmed': the result page was shown but nobody confirmed ResellerClub recorded it.
     */
    public function unreconciled(): array
    {
        // Test-mode payments move no real money, so they never need reconciling.
        $rows = $this->db->query("SELECT * FROM transactions WHERE status = 'paid' AND rc_confirmed = 0
            AND (mode IS NULL OR mode = 'live') ORDER BY created_at")->fetchAll();
        $out = ['never_shown' => [], 'unconfirmed' => []];
        foreach ($rows as $r) {
            $out[(int) $r['rc_notified'] === 1 ? 'unconfirmed' : 'never_shown'][] = $r;
        }
        return $out;
    }

    /** Operator confirms ResellerClub recorded the payment(s) for a transid. */
    public function confirm(string $transId): int
    {
        $st = $this->db->prepare("UPDATE transactions SET rc_confirmed = 1, updated_at = ? WHERE rc_transid = ? AND status = 'paid'");
        $st->execute([date('c'), $transId]);
        return $st->rowCount();
    }

    /** Move status only if currently $from — returns true if this call made the change. */
    public function transition(string $token, string $from, string $to, array $extra = []): bool
    {
        $extra['status'] = $to;
        $extra['updated_at'] = date('c');
        $set = implode(',', array_map(fn($c) => "$c = :$c", array_keys($extra)));
        $extra['token'] = $token;
        $extra['from_status'] = $from;
        $st = $this->db->prepare("UPDATE transactions SET $set WHERE token = :token AND status = :from_status");
        $st->execute($extra);
        return $st->rowCount() > 0;
    }

    /** Mark as paid from any not-yet-paid state (created, failed, superseded). */
    public function markPaid(string $token, string $paymentId): bool
    {
        $st = $this->db->prepare("UPDATE transactions SET status = 'paid', razorpay_payment_id = ?, updated_at = ?
            WHERE token = ? AND status IN ('created', 'failed', 'superseded')");
        $st->execute([$paymentId, date('c'), $token]);
        return $st->rowCount() > 0;
    }
}
