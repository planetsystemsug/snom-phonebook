<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/App.php';
try { SnomPhonebook\db(SnomPhonebook\config()); http_response_code(200); echo 'ok'; } catch (Throwable) { http_response_code(503); echo 'unhealthy'; }
