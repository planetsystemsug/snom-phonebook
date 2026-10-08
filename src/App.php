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
    $pdo->exec('CREATE TABLE IF NOT EXISTS contacts (id INTEGER PRIMARY KEY, name TEXT NOT NULL, company TEXT NOT NULL DEFAULT \'\', telephone TEXT NOT NULL DEFAULT \'\', mobile TEXT NOT NULL DEFAULT \'\', email TEXT NOT NULL DEFAULT \'\', source TEXT NOT NULL DEFAULT \'manual\', source_id TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    migrate_database($pdo);
    if ($config['admin_password_hash'] !== '') {
        // The environment is the source of truth for the bootstrap account.
        // This permits a deliberate password reset through an application
        // recreate without manipulating the SQLite database directly.
        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?) ON CONFLICT(username) DO UPDATE SET password_hash = excluded.password_hash');
        $stmt->execute([$config['admin_username'], $config['admin_password_hash'], gmdate('c')]);
    }
    return $pdo;
}

function migrate_database(PDO $pdo): void
{
    $columns = $pdo->query('PRAGMA table_info(contacts)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('source', $columns, true)) $pdo->exec("ALTER TABLE contacts ADD COLUMN source TEXT NOT NULL DEFAULT 'manual'");
    if (!in_array('source_id', $columns, true)) $pdo->exec('ALTER TABLE contacts ADD COLUMN source_id TEXT');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS contacts_external_source ON contacts(source, source_id) WHERE source_id IS NOT NULL');
}

function setting(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ?'); $stmt->execute([$key]);
    $value = $stmt->fetchColumn(); return $value === false ? $default : (string) $value;
}

function set_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute([$key, $value]);
}

function encryption_key(array $config): string
{
    if (strlen($config['secret']) < 32 || !function_exists('sodium_crypto_secretbox')) throw new RuntimeException('APP_SECRET or sodium extension is unavailable.');
    return hash('sha256', $config['secret'], true);
}

function encrypt_secret(string $plain, array $config): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, encryption_key($config)));
}

function decrypt_secret(string $encrypted, array $config): string
{
    $decoded = base64_decode($encrypted, true);
    if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new RuntimeException('Stored FRITZ!Box credential is invalid.');
    $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open(substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, encryption_key($config));
    if ($plain === false) throw new RuntimeException('Stored FRITZ!Box credential cannot be decrypted.');
    return $plain;
}

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function valid_phone(string $value): bool
{
    return $value === '' || (bool) preg_match('/^[0-9+*#().\\/ -]{2,40}$/', $value);
}

// Normalise a FRITZ!Box number to a clean international dialable string.
// German domestic numbers carry a leading trunk '0' (or '00' for the
// international access code); both are rewritten to E.164-style '+'.
function normalize_phone(string $value): string
{
    $clean = preg_replace('/[().\s\/-]/', '', $value);
    if ($clean === '') return '';
    if (str_starts_with($clean, '+')) return '+' . ltrim(substr($clean, 1), '+');
    if (str_starts_with($clean, '00')) return '+' . substr($clean, 2);
    if (str_starts_with($clean, '0')) return '+49' . substr($clean, 1);
    return $clean;
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
        $source = $pdo->prepare('SELECT source FROM contacts WHERE id = ?'); $source->execute([$id]);
        if ($source->fetchColumn() === 'fritzbox') throw new RuntimeException('FRITZ!Box contacts must be changed on the FRITZ!Box.');
        $stmt = $pdo->prepare('UPDATE contacts SET name=?, company=?, telephone=?, mobile=?, email=?, updated_at=? WHERE id=?');
        $stmt->execute([...array_values($contact), $now, $id]);
    }
}

function delete_contact(PDO $pdo, int $id): void
{
    $source = $pdo->prepare('SELECT source FROM contacts WHERE id = ?'); $source->execute([$id]);
    if ($source->fetchColumn() === 'fritzbox') throw new RuntimeException('FRITZ!Box contacts must be removed on the FRITZ!Box.');
    $stmt = $pdo->prepare('DELETE FROM contacts WHERE id = ?'); $stmt->execute([$id]);
}

function fritzbox_config(PDO $pdo, array $config): array
{
    return [
        'control_url' => setting($pdo, 'fritzbox_control_url'),
        'username' => setting($pdo, 'fritzbox_username'),
        'password' => setting($pdo, 'fritzbox_password_enc') === '' ? '' : decrypt_secret(setting($pdo, 'fritzbox_password_enc'), $config),
        'phonebook_id' => setting($pdo, 'fritzbox_phonebook_id', '0'),
    ];
}

function save_fritzbox_config(PDO $pdo, array $input, array $config): void
{
    $url = trim((string) ($input['fritzbox_control_url'] ?? ''));
    $username = trim((string) ($input['fritzbox_username'] ?? ''));
    $phonebookId = trim((string) ($input['fritzbox_phonebook_id'] ?? '0'));
    $password = (string) ($input['fritzbox_password'] ?? '');
    $parts = parse_url($url);
    if ($url === '' || !is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ($parts['host'] ?? '') === '') throw new RuntimeException('Invalid FRITZ!Box control URL.');
    if ($username === '' || !ctype_digit($phonebookId)) throw new RuntimeException('FRITZ!Box username and numeric phonebook ID are required.');
    set_setting($pdo, 'fritzbox_control_url', $url); set_setting($pdo, 'fritzbox_username', $username); set_setting($pdo, 'fritzbox_phonebook_id', $phonebookId);
    if ($password !== '') set_setting($pdo, 'fritzbox_password_enc', encrypt_secret($password, $config));
    if (setting($pdo, 'fritzbox_password_enc') === '') throw new RuntimeException('Enter the FRITZ!Box password at least once.');
}

function fritzbox_http(string $url, string $username, string $password, array $headers = [], ?string $body = null): string
{
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is unavailable.');
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPAUTH => CURLAUTH_BASIC | CURLAUTH_DIGEST, CURLOPT_USERPWD => "$username:$password",
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    if ($body !== null) { curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, $body); }
    $response = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $error = curl_error($curl); curl_close($curl);
    if (!is_string($response) || $status < 200 || $status >= 300) {
        $detail = $error !== '' ? $error : fritzbox_fault($response);
        throw new RuntimeException('FRITZ!Box request failed (HTTP ' . $status . ')' . ($detail === '' ? '.' : ': ' . $detail));
    }
    return $response;
}

function fritzbox_fault(mixed $response): string
{
    if (!is_string($response) || $response === '') return '';
    $document = new \DOMDocument();
    if (@$document->loadXML($response, LIBXML_NONET)) {
        $xpath = new \DOMXPath($document);
        // AVM answers every rejected TR-064 call with HTTP 500 and a SOAP fault whose
        // <faultstring> is only the generic "UPnPError". The actionable reason lives in
        // the UPnP detail block: <errorCode> plus <errorDescription>. Surface both so a
        // wrong phonebook ID (713), bad arguments (402) or missing rights are visible.
        $code = trim((string) $xpath->evaluate('string(//*[local-name()="errorCode"][1])'));
        $detail = trim((string) $xpath->evaluate('string(//*[local-name()="errorDescription"][1])'));
        if ($detail === '') {
            // Plain SOAP fault without a UPnP detail block.
            $detail = trim((string) $xpath->evaluate('string(//*[local-name()="faultstring"][1])'));
        }
        if ($code !== '' && $detail !== '') $message = "$code: $detail";
        elseif ($code !== '') $message = "UPnP error code $code";
        else $message = $detail;
        if ($message !== '') return substr(preg_replace('/\s+/', ' ', $message) ?: '', 0, 240);
    }
    return 'The FRITZ!Box did not provide a readable SOAP fault.';
}

function fritzbox_soap(string $controlUrl, string $username, string $password, string $action, array $arguments): string
{
    $xml = new \XMLWriter(); $xml->openMemory(); $xml->startDocument('1.0', 'UTF-8');
    $xml->startElementNs('s', 'Envelope', 'http://schemas.xmlsoap.org/soap/envelope/'); $xml->startElementNs('s', 'Body', null);
    $xml->startElementNs('u', $action, 'urn:dslforum-org:service:X_AVM-DE_OnTel:1');
    foreach ($arguments as $key => $value) $xml->writeElement($key, (string) $value);
    $xml->endElement(); $xml->endElement(); $xml->endElement();
    return fritzbox_http($controlUrl, $username, $password, ['Content-Type: text/xml; charset="utf-8"', 'SOAPAction: "urn:dslforum-org:service:X_AVM-DE_OnTel:1#' . $action . '"'], $xml->outputMemory());
}

function xml_text(string $xml, string $expression): string
{
    $document = new \DOMDocument(); if (!@$document->loadXML($xml, LIBXML_NONET)) throw new RuntimeException('FRITZ!Box returned invalid XML.');
    $value = (new \DOMXPath($document))->evaluate('string(' . $expression . ')'); return trim((string) $value);
}

function parse_fritzbox_phonebook(string $xml): array
{
    $document = new \DOMDocument(); if (!@$document->loadXML($xml, LIBXML_NONET)) throw new RuntimeException('FRITZ!Box phonebook XML is invalid.');
    $xpath = new \DOMXPath($document); $result = [];
    foreach ($xpath->query('//*[local-name()="contact"]') ?: [] as $node) {
        $name = trim((string) $xpath->evaluate('string(./*[local-name()="person"]/*[local-name()="realName"])', $node));
        $company = trim((string) $xpath->evaluate('string(./*[local-name()="person"]/*[local-name()="company"])', $node));
        $telephone = ''; $mobile = '';
        foreach ($xpath->query('./*[local-name()="telephony"]/*[local-name()="number"]', $node) ?: [] as $number) {
            $value = trim($number->textContent); $type = strtolower($number->getAttribute('type'));
            if ($value === '') continue;
            if ($type === 'fax') continue;
            // Skip FRITZ!Box internal function codes (e.g. **41 Wecker, **603 Leitungen belegt).
            if (str_starts_with($value, '*')) continue;
            $value = normalize_phone($value);
            if ($value === '') continue;
            if ($type === 'mobile' && $mobile === '') $mobile = $value;
            elseif ($telephone === '') $telephone = $value;
        }
        // FRITZ!Box numbers can contain characters we cannot represent on the phone.
        // Drop any number that fails validation instead of aborting the whole import.
        if (!valid_phone($telephone)) $telephone = '';
        if (!valid_phone($mobile)) $mobile = '';
        if ($name === '') $name = $company;
        if ($name === '' || ($telephone === '' && $mobile === '')) continue;
        $id = trim((string) $xpath->evaluate('string(./*[local-name()="uniqueid"])', $node));
        if ($id === '') $id = hash('sha256', $name . "\0" . $telephone . "\0" . $mobile);
        try {
            $result[] = validate_contact(['name' => $name, 'company' => $company, 'telephone' => $telephone, 'mobile' => $mobile, 'email' => '']) + ['source_id' => $id];
        } catch (RuntimeException) {
            continue; // Skip contacts we cannot represent (e.g. oversized fields).
        }
    }
    return $result;
}

function sync_fritzbox(PDO $pdo, array $config): array
{
    $fritz = fritzbox_config($pdo, $config);
    if ($fritz['control_url'] === '' || $fritz['username'] === '' || $fritz['password'] === '') throw new RuntimeException('FRITZ!Box sync is not configured.');
    $soap = fritzbox_soap($fritz['control_url'], $fritz['username'], $fritz['password'], 'GetPhonebook', ['NewPhonebookID' => $fritz['phonebook_id']]);
    $phonebookUrl = xml_text($soap, '//*[local-name()="NewPhonebookURL"]');
    if ($phonebookUrl === '') throw new RuntimeException('FRITZ!Box did not return a phonebook URL.');
    $contacts = parse_fritzbox_phonebook(fritzbox_http($phonebookUrl, $fritz['username'], $fritz['password']));
    $pdo->beginTransaction();
    try {
        $ids = []; $added = 0; $updated = 0; $now = gmdate('c');
        foreach ($contacts as $contact) {
            $ids[] = $contact['source_id']; $find = $pdo->prepare("SELECT id FROM contacts WHERE source = 'fritzbox' AND source_id = ?"); $find->execute([$contact['source_id']]); $existingId = $find->fetchColumn();
            if ($existingId === false) { $insert = $pdo->prepare("INSERT INTO contacts (name, company, telephone, mobile, email, source, source_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'fritzbox', ?, ?, ?)"); $insert->execute([...array_values(array_intersect_key($contact, array_flip(CONTACT_FIELDS))), $contact['source_id'], $now, $now]); $added++; }
            else { $update = $pdo->prepare('UPDATE contacts SET name=?, company=?, telephone=?, mobile=?, email=?, updated_at=? WHERE id=?'); $update->execute([...array_values(array_intersect_key($contact, array_flip(CONTACT_FIELDS))), $now, $existingId]); $updated++; }
        }
        $removed = 0; $stale = $pdo->query("SELECT id, source_id FROM contacts WHERE source = 'fritzbox'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($stale as $contact) if (!in_array($contact['source_id'], $ids, true)) { $delete = $pdo->prepare('DELETE FROM contacts WHERE id = ?'); $delete->execute([$contact['id']]); $removed++; }
        set_setting($pdo, 'fritzbox_last_sync_at', $now); set_setting($pdo, 'fritzbox_last_sync_result', "OK: $added added, $updated updated, $removed removed"); $pdo->commit();
        return compact('added', 'updated', 'removed');
    } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); set_setting($pdo, 'fritzbox_last_sync_result', 'Failed: ' . $e->getMessage()); throw $e; }
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

function remote_directory_xml(array $contacts): string
{
    $xml = new \XMLWriter(); $xml->openMemory(); $xml->startDocument('1.0', 'UTF-8');
    $xml->startElement('tbook'); $xml->writeAttribute('e', '2'); $xml->writeAttribute('version', '2.0');
    foreach ($contacts as $contact) {
        $nameParts = preg_split('/\\s+/u', trim($contact['name'])) ?: [];
        $lastName = count($nameParts) > 1 ? (string) array_pop($nameParts) : '';
        $firstName = implode(' ', $nameParts);
        if ($firstName === '') { $firstName = $lastName; $lastName = ''; }
        $xml->startElement('contact'); $xml->writeAttribute('fav', 'false'); $xml->writeAttribute('vip', 'false'); $xml->writeAttribute('blocked', 'false');
        $xml->writeElement('first_name', $firstName); $xml->writeElement('last_name', $lastName); $xml->startElement('numbers');
        foreach (['telephone' => 'fixed', 'mobile' => 'mobile'] as $field => $type) {
            if ($contact[$field] === '') continue;
            $xml->startElement('number'); $xml->writeAttribute('no', $contact[$field]); $xml->writeAttribute('type', $type); $xml->writeAttribute('outgoing_id', '0'); $xml->endElement();
        }
        $xml->endElement(); $xml->endElement();
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
