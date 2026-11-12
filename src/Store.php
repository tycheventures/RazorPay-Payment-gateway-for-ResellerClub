<?php
declare(strict_types=1);

/** SQLite-backed store mapping ResellerClub transactions to Razorpay orders. */
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
    }

    public function create(array $row): void
    {
        $now = date('c');
        $row += ['created_at' => $now, 'updated_at' => $now];
        $cols = array_keys($row);
        $sql = 'INSERT INTO transactions (' . implode(',', $cols) . ') VALUES (:' . implode(',:', $cols) . ')';
        $this->db->prepare($sql)->execute($row);
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

    /** Latest still-open attempt for a ResellerClub transid (reused if the customer reloads). */
    public function findOpenByTransId(string $transId): ?array
    {
        $st = $this->db->prepare("SELECT * FROM transactions WHERE rc_transid = ? AND status = 'created' ORDER BY created_at DESC LIMIT 1");
        $st->execute([$transId]);
        return $st->fetch() ?: null;
    }

    public function update(string $token, array $fields): void
    {
        $fields['updated_at'] = date('c');
        $set = implode(',', array_map(fn($c) => "$c = :$c", array_keys($fields)));
        $fields['token'] = $token;
        $this->db->prepare("UPDATE transactions SET $set WHERE token = :token")->execute($fields);
    }

    /** Paid transactions whose customer never made it back to ResellerClub. */
    public function unreconciled(): array
    {
        return $this->db->query("SELECT * FROM transactions WHERE status = 'paid' AND rc_notified = 0 ORDER BY created_at")->fetchAll();
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
}
