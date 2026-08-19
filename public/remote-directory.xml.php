<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/App.php';
use function SnomPhonebook\{config, db, contacts, phonebook_authorized, remote_directory_xml};
$config = config();
if (!phonebook_authorized($_SERVER, $config)) {
    header('WWW-Authenticate: Basic realm="Snom remote directory", charset="UTF-8"'); http_response_code(401); exit;
}
header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');
echo remote_directory_xml(contacts(db($config)));
