<?php
/** Folder index: send the owner to the setup wizard until the gateway is configured. */
declare(strict_types=1);
require __DIR__ . '/src/helpers.php';

if (!is_file(GW_CONFIG_FILE)) {
    header('Location: setup.php', true, 302);
    exit;
}
fail_page('Not found', 'There is nothing to see here.', 404);
