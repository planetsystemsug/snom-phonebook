<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/App.php';
use function SnomPhonebook\{config, db, save_contact, validate_contact, phonebook_xml, phonebook_authorized};
$failures = 0;
function check(bool $condition, string $message): void { global $failures; if (!$condition) { $failures++; echo "FAIL: $message\n"; } }
function expect_exception(callable $callable, string $message): void { try { $callable(); check(false, $message); } catch (RuntimeException) { check(true, $message); } }
$valid = validate_contact(['name' => 'Jörg & Söhne', 'company' => 'Müller <Partner>', 'telephone' => '+49 (30) 12 34', 'mobile' => '', 'email' => 'joerg@example.test']);
check($valid['name'] === 'Jörg & Söhne', 'accepts German umlauts');
expect_exception(fn() => validate_contact(['name' => 'A', 'telephone' => 'abc', 'mobile' => '']), 'rejects invalid telephone');
expect_exception(fn() => validate_contact(['name' => '', 'telephone' => '123', 'mobile' => '']), 'requires name');
expect_exception(fn() => validate_contact(['name' => 'A', 'telephone' => '', 'mobile' => '']), 'requires phone or mobile');
$xml = phonebook_xml([['name' => 'Jörg & Söhne', 'company' => 'Müller <Partner>', 'telephone' => '+49 30', 'mobile' => '+49 171', 'email' => '']]);
check(str_contains($xml, 'Jörg &amp; Söhne — Müller &lt;Partner&gt;'), 'escapes XML special characters and keeps UTF-8');
check(substr_count($xml, '<DirectoryEntry>') === 2, 'emits one entry per supplied number');
check(!str_contains(phonebook_xml([]), '<DirectoryEntry>'), 'handles empty phonebook');
$hash = password_hash('secret', PASSWORD_DEFAULT); $authConfig = ['phonebook_auth_user' => 'phone', 'phonebook_auth_password_hash' => $hash];
check(phonebook_authorized(['PHP_AUTH_USER' => 'phone', 'PHP_AUTH_PW' => 'secret'], $authConfig), 'authorizes valid endpoint credentials');
check(!phonebook_authorized(['PHP_AUTH_USER' => 'phone', 'PHP_AUTH_PW' => 'wrong'], $authConfig), 'rejects invalid endpoint credentials');
check(phonebook_authorized([], ['phonebook_auth_user' => '', 'phonebook_auth_password_hash' => '']), 'allows endpoint auth when disabled');

// Exercise the real HTTP endpoint with PHP's built-in server, including its
// Basic-auth response and XML content type.
$testDir = sys_get_temp_dir() . '/snom-phonebook-test-' . bin2hex(random_bytes(6));
mkdir($testDir, 0700, true);
$port = random_int(20000, 40000);
putenv('APP_DATA_DIR=' . $testDir);
putenv('APP_SECRET=' . str_repeat('a', 32));
putenv('ADMIN_PASSWORD_HASH=' . password_hash('admin-test', PASSWORD_DEFAULT));
putenv('PHONEBOOK_AUTH_USER=phone');
putenv('PHONEBOOK_AUTH_PASSWORD_HASH=' . password_hash('phone-test', PASSWORD_DEFAULT));
$pdo = db(config()); save_contact($pdo, ['name' => 'Özil & Co.', 'company' => '', 'telephone' => '1234', 'mobile' => '', 'email' => '']);
$process = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', dirname(__DIR__) . '/public'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
if (is_resource($process)) {
    usleep(300000);
    $body = @file_get_contents("http://127.0.0.1:$port/phonebook.xml.php");
    check($body === false && str_contains(implode("\n", $http_response_header ?? []), '401'), 'HTTP endpoint challenges without credentials');
    $context = stream_context_create(['http' => ['header' => 'Authorization: Basic ' . base64_encode('phone:phone-test')]]);
    $body = file_get_contents("http://127.0.0.1:$port/phonebook.xml.php", false, $context);
    check(str_contains($body, '<SnomIPPhoneDirectory>') && str_contains($body, 'Özil &amp; Co.'), 'HTTP XML endpoint returns escaped directory XML');
    check(str_contains(implode("\n", $http_response_header ?? []), 'Content-Type: application/xml; charset=UTF-8'), 'HTTP XML endpoint sets XML UTF-8 content type');
    proc_terminate($process); foreach ($pipes as $pipe) fclose($pipe); proc_close($process);
} else { check(false, 'starts PHP HTTP test server'); }
echo $failures ? "$failures test(s) failed\n" : "All tests passed\n"; exit($failures ? 1 : 0);
