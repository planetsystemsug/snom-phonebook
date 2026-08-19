<?php
declare(strict_types=1);

namespace SnomPhonebook;

use PDO;
use RuntimeException;

const CONTACT_FIELDS = ['name', 'company', 'telephone', 'mobile', 'email'];

function config(): array
{
    $dataDir = getenv('APP_DATA_DIR') ?: dirname(__DIR__) . '/data';
    $secret = getenv('APP_SECRET') ?: '';
    if (PHP_SAPI !== 'cli' && strlen($secret) < 32) {
        throw new RuntimeException('APP_SECRET must contain at least 32 characters.');
    }
    return [
        'data_dir' => $dataDir,
        'db_path' => $dataDir . '/phonebook.sqlite',
        'secret' => $secret,
        'admin_username' => getenv('ADMIN_USERNAME') ?: 'admin',
        'admin_password_hash' => getenv('ADMIN_PASSWORD_HASH') ?: '',
        'phonebook_auth_user' => getenv('PHONEBOOK_AUTH_USER') ?: '',
        'phonebook_auth_password_hash' => getenv('PHONEBOOK_AUTH_PASSWORD_HASH') ?: '',
    ];
}

function db(array $config): PDO
{
    if (!is_dir($config['data_dir']) && !mkdir($config['data_dir'], 0700, true) && !is_dir($config['data_dir'])) {
        throw new RuntimeException('Cannot create data directory.');
    }
    $pdo = new PDO('sqlite:' . $config['db_path'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, username TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, created_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS contacts (id INTEGER PRIMARY KEY, name TEXT NOT NULL, company TEXT NOT NULL DEFAULT \'\', telephone TEXT NOT NULL DEFAULT \'\', mobile TEXT NOT NULL DEFAULT \'\', email TEXT NOT NULL DEFAULT \'\', created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    if ($config['admin_password_hash'] !== '') {
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO users (username, password_hash, created_at) VALUES (?, ?, ?)');
        $stmt->execute([$config['admin_username'], $config['admin_password_hash'], gmdate('c')]);
    }
    return $pdo;
}

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function valid_phone(string $value): bool
{
    return $value === '' || (bool) preg_match('/^[0-9+*#().\\/ -]{2,40}$/', $value);
}

function validate_contact(array $input): array
{
    $contact = [];
    foreach (CONTACT_FIELDS as $field) {
        $contact[$field] = trim((string) ($input[$field] ?? ''));
        if (strlen($contact[$field]) > 640) {
            throw new RuntimeException("$field is too long.");
        }
    }
    if ($contact['name'] === '') throw new RuntimeException('Name is required.');
    if ($contact['telephone'] === '' && $contact['mobile'] === '') throw new RuntimeException('Telephone or mobile number is required.');
    if (!valid_phone($contact['telephone']) || !valid_phone($contact['mobile'])) throw new RuntimeException('Invalid telephone number.');
    if ($contact['email'] !== '' && !filter_var($contact['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid email address.');
    return $contact;
}

function contacts(PDO $pdo, string $query = ''): array
{
    $stmt = $pdo->prepare('SELECT * FROM contacts WHERE name LIKE ? OR company LIKE ? OR telephone LIKE ? OR mobile LIKE ? OR email LIKE ? ORDER BY name COLLATE NOCASE, id');
    $needle = '%' . $query . '%';
    $stmt->execute([$needle, $needle, $needle, $needle, $needle]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function save_contact(PDO $pdo, array $input, ?int $id = null): void
{
    $contact = validate_contact($input); $now = gmdate('c');
    if ($id === null) {
        $stmt = $pdo->prepare('INSERT INTO contacts (name, company, telephone, mobile, email, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([...array_values($contact), $now, $now]);
    } else {
        $stmt = $pdo->prepare('UPDATE contacts SET name=?, company=?, telephone=?, mobile=?, email=?, updated_at=? WHERE id=?');
        $stmt->execute([...array_values($contact), $now, $id]);
    }
}

function phonebook_xml(array $contacts): string
{
    $xml = new \XMLWriter(); $xml->openMemory(); $xml->startDocument('1.0', 'UTF-8'); $xml->startElement('SnomIPPhoneDirectory');
    $xml->writeElement('Title', 'Zentrales Telefonbuch');
    foreach ($contacts as $contact) {
        $display = $contact['name'] . ($contact['company'] !== '' ? ' — ' . $contact['company'] : '');
        foreach (['telephone' => '', 'mobile' => ' (Mobil)'] as $field => $suffix) {
            if ($contact[$field] === '') continue;
            $xml->startElement('DirectoryEntry');
            $xml->writeElement('Name', $display . $suffix);
            $xml->writeElement('Telephone', $contact[$field]);
            $xml->endElement();
        }
    }
    $xml->endElement(); return $xml->outputMemory();
}

function phonebook_authorized(array $server, array $config): bool
{
    if ($config['phonebook_auth_user'] === '' && $config['phonebook_auth_password_hash'] === '') return true;
    if ($config['phonebook_auth_user'] === '' || $config['phonebook_auth_password_hash'] === '') return false;
    return isset($server['PHP_AUTH_USER'], $server['PHP_AUTH_PW'])
        && hash_equals($config['phonebook_auth_user'], (string) $server['PHP_AUTH_USER'])
        && password_verify((string) $server['PHP_AUTH_PW'], $config['phonebook_auth_password_hash']);
}

function start_session(array $config): void
{
    session_name('snom_phonebook');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')]);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function logged_in(): bool { return isset($_SESSION['user_id']); }
function csrf_ok(): bool { return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string) $_POST['csrf']); }
