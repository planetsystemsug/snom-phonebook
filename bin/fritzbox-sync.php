<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/App.php';

try {
    $config = SnomPhonebook\config();
    $result = SnomPhonebook\sync_fritzbox(SnomPhonebook\db($config), $config);
    fwrite(STDOUT, "FRITZ!Box sync complete: {$result['added']} added, {$result['updated']} updated, {$result['removed']} removed\n");
} catch (Throwable $error) {
    fwrite(STDERR, "FRITZ!Box sync failed: {$error->getMessage()}\n"); exit(1);
}
