<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/App.php';
use function SnomPhonebook\{config, db, save_contact, validate_contact, phonebook_xml, remote_directory_xml, phonebook_authorized, encrypt_secret, decrypt_secret, parse_fritzbox_phonebook, fritzbox_fault};
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
$remoteXml = remote_directory_xml([['name' => 'Jörg & Söhne', 'company' => '', 'telephone' => '+49 30', 'mobile' => '+49 171', 'email' => '']]);
check(str_contains($remoteXml, '<tbook e="2" version="2.0">'), 'emits Remote XML Directory tbook version 2.0');
check(str_contains($remoteXml, '<first_name>Jörg &amp;</first_name>') && str_contains($remoteXml, '<last_name>Söhne</last_name>'), 'maps UTF-8 display name to Remote XML Directory name fields');
check(str_contains($remoteXml, 'no="+49 30" type="fixed" outgoing_id="0"') && str_contains($remoteXml, 'type="mobile"'), 'emits typed Remote XML Directory numbers');
$hash = password_hash('secret', PASSWORD_DEFAULT); $authConfig = ['phonebook_auth_user' => 'phone', 'phonebook_auth_password_hash' => $hash];
check(phonebook_authorized(['PHP_AUTH_USER' => 'phone', 'PHP_AUTH_PW' => 'secret'], $authConfig), 'authorizes valid endpoint credentials');
check(!phonebook_authorized(['PHP_AUTH_USER' => 'phone', 'PHP_AUTH_PW' => 'wrong'], $authConfig), 'rejects invalid endpoint credentials');
check(phonebook_authorized([], ['phonebook_auth_user' => '', 'phonebook_auth_password_hash' => '']), 'allows endpoint auth when disabled');
$secretConfig = ['secret' => str_repeat('s', 32)];
check(decrypt_secret(encrypt_secret('router-password', $secretConfig), $secretConfig) === 'router-password', 'encrypts stored FRITZ!Box password');
$fritzContacts = parse_fritzbox_phonebook('<?xml version="1.0"?><phonebooks><phonebook><contact><uniqueid>42</uniqueid><person><realName>Erika Mustermann</realName><company>Beispiel GmbH</company></person><telephony><number type="work">+49301234</number><number type="mobile">+491711234</number></telephony></contact></phonebook></phonebooks>');
check(count($fritzContacts) === 1 && $fritzContacts[0]['source_id'] === '42' && $fritzContacts[0]['telephone'] === '+49301234' && $fritzContacts[0]['mobile'] === '+491711234', 'parses FRITZ!Box phonebook contact and typed numbers');
$fritzMixed = '<?xml version="1.0"?><phonebooks><phonebook>'
    . '<contact><uniqueid>1</uniqueid><person><realName>Guter &amp; Schlechter</realName></person><telephony><number type="work">+49301234</number><number type="mobile">+49 171 abc</number></telephony></contact>'
    . '<contact><uniqueid>2</uniqueid><person><realName>Nur Schlecht</realName></person><telephony><number type="work">abc def</number></telephony></contact>'
    . '</phonebook></phonebooks>';
$fritzMixedContacts = parse_fritzbox_phonebook($fritzMixed);
check(count($fritzMixedContacts) === 1 && $fritzMixedContacts[0]['source_id'] === '1' && $fritzMixedContacts[0]['telephone'] === '+49301234' && $fritzMixedContacts[0]['mobile'] === '', 'drops unrepresentable FRITZ!Box numbers without aborting the import');
check(fritzbox_fault('<?xml version="1.0"?><s:Fault xmlns:s="urn:test"><faultstring>Invalid phonebook ID</faultstring></s:Fault>') === 'Invalid phonebook ID', 'reports a safe FRITZ!Box SOAP fault');
$upnp713 = '<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><s:Fault><faultcode>s:Client</faultcode><faultstring>UPnPError</faultstring><detail><UPnPError xmlns="urn:schemas-upnp-org:control-1-0"><errorCode>713</errorCode><errorDescription>Invalid array index</errorDescription></UPnPError></detail></s:Fault></s:Body></s:Envelope>';
check(fritzbox_fault($upnp713) === '713: Invalid array index', 'reports the specific UPnP error code and description instead of generic UPnPError');
$upnp401 = '<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><s:Fault><faultcode>s:Client</faultcode><faultstring>UPnPError</faultstring><detail><UPnPError xmlns="urn:schemas-upnp-org:control-1-0"><errorCode>401</errorCode><errorDescription>Unauthenticated</errorDescription></UPnPError></detail></s:Fault></s:Body></s:Envelope>';
check(fritzbox_fault($upnp401) === '401: Unauthenticated', 'reports an unauthenticated UPnP fault');

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
$resetConfig = config(); $resetConfig['admin_password_hash'] = password_hash('new-admin-test', PASSWORD_DEFAULT); db($resetConfig);
$adminHash = $pdo->query("SELECT password_hash FROM users WHERE username = 'admin'")->fetchColumn();
check(password_verify('new-admin-test', $adminHash), 'updates configured admin password hash on restart');
$process = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', dirname(__DIR__) . '/public'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
if (is_resource($process)) {
    usleep(300000);
    $body = @file_get_contents("http://127.0.0.1:$port/phonebook.xml.php");
    check($body === false && str_contains(implode("\n", $http_response_header ?? []), '401'), 'HTTP endpoint challenges without credentials');
    $context = stream_context_create(['http' => ['header' => 'Authorization: Basic ' . base64_encode('phone:phone-test')]]);
    $body = file_get_contents("http://127.0.0.1:$port/phonebook.xml.php", false, $context);
    check(str_contains($body, '<SnomIPPhoneDirectory>') && str_contains($body, 'Özil &amp; Co.'), 'HTTP XML endpoint returns escaped directory XML');
    check(str_contains(implode("\n", $http_response_header ?? []), 'Content-Type: application/xml; charset=UTF-8'), 'HTTP XML endpoint sets XML UTF-8 content type');
    $remoteContext = stream_context_create(['http' => ['method' => 'POST', 'header' => 'Authorization: Basic ' . base64_encode('phone:phone-test')]]);
    $remoteBody = file_get_contents("http://127.0.0.1:$port/remote-directory.xml.php", false, $remoteContext);
    check(str_contains($remoteBody, '<tbook e="2" version="2.0">'), 'Remote XML Directory endpoint accepts phone POST requests');
    proc_terminate($process); foreach ($pipes as $pipe) fclose($pipe); proc_close($process);
} else { check(false, 'starts PHP HTTP test server'); }
echo $failures ? "$failures test(s) failed\n" : "All tests passed\n"; exit($failures ? 1 : 0);
