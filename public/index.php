<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/App.php';
use function SnomPhonebook\{config, db, start_session, logged_in, csrf_ok, h, contacts, save_contact};

$config = config(); start_session($config); $pdo = db($config); $error = '';
if (isset($_POST['action'])) {
    if (!csrf_ok()) { http_response_code(400); $error = 'Ungültige Anfrage. Bitte erneut versuchen.'; }
    elseif ($_POST['action'] === 'login') {
        $user = (string) ($_POST['username'] ?? ''); $password = (string) ($_POST['password'] ?? '');
        $stmt = $pdo->prepare('SELECT id, username, password_hash FROM users WHERE username = ?'); $stmt->execute([$user]); $found = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($found && password_verify($password, $found['password_hash'])) { session_regenerate_id(true); $_SESSION['user_id'] = $found['id']; $_SESSION['username'] = $found['username']; header('Location: /'); exit; }
        $error = 'Anmeldung fehlgeschlagen.';
    } elseif ($_POST['action'] === 'logout') { $_SESSION = []; session_destroy(); header('Location: /'); exit;
    } elseif (!logged_in()) { http_response_code(403); $error = 'Anmeldung erforderlich.';
    } else {
        try {
            $id = isset($_POST['id']) && ctype_digit((string) $_POST['id']) ? (int) $_POST['id'] : null;
            if ($_POST['action'] === 'save') save_contact($pdo, $_POST, $id);
            elseif ($_POST['action'] === 'delete' && $id !== null) { $stmt = $pdo->prepare('DELETE FROM contacts WHERE id = ?'); $stmt->execute([$id]); }
            header('Location: /'); exit;
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
}
$editing = ['id' => '', 'name' => '', 'company' => '', 'telephone' => '', 'mobile' => '', 'email' => ''];
if (logged_in() && isset($_GET['edit']) && ctype_digit((string) $_GET['edit'])) { $stmt = $pdo->prepare('SELECT * FROM contacts WHERE id = ?'); $stmt->execute([(int) $_GET['edit']]); $editing = $stmt->fetch(PDO::FETCH_ASSOC) ?: $editing; }
$query = logged_in() ? trim((string) ($_GET['q'] ?? '')) : ''; $allContacts = logged_in() ? contacts($pdo, $query) : [];
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Snom Telefonbuch</title><style>
body{font-family:system-ui,sans-serif;max-width:1050px;margin:2rem auto;padding:0 1rem;color:#14243a;background:#f6f8fa}header{display:flex;justify-content:space-between;align-items:center}h1{color:#12466b}.card{background:#fff;padding:1.5rem;border-radius:8px;box-shadow:0 1px 4px #0002;margin:1rem 0}label{display:block;font-weight:600;margin:.65rem 0 .2rem}input{box-sizing:border-box;width:100%;padding:.6rem;border:1px solid #aab5bf;border-radius:4px;font:inherit}button,.button{background:#076b93;color:#fff;border:0;padding:.65rem 1rem;border-radius:4px;font:inherit;text-decoration:none;cursor:pointer}.secondary{background:#59636c}.danger{background:#a52a2a}table{width:100%;border-collapse:collapse;background:#fff}th,td{text-align:left;padding:.65rem;border-bottom:1px solid #d6dde3}form.inline{display:inline}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 1rem}.error{color:#9e1b1b}@media(max-width:650px){.grid{grid-template-columns:1fr}table{font-size:.85rem}}
</style></head><body><header><h1>Snom Telefonbuch</h1><?php if (logged_in()): ?><form class="inline" method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><button class="secondary" name="action" value="logout">Abmelden</button></form><?php endif ?></header>
<?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif ?>
<?php if (!logged_in()): ?><main class="card"><h2>Anmelden</h2><form method="post"><input type="hidden" name="action" value="login"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><label>Benutzername<input name="username" autocomplete="username" required></label><label>Passwort<input type="password" name="password" autocomplete="current-password" required></label><p><button>Anmelden</button></p></form></main>
<?php else: ?><main><section class="card"><h2><?= $editing['id'] !== '' ? 'Kontakt bearbeiten' : 'Kontakt hinzufügen' ?></h2><form method="post"><input type="hidden" name="action" value="save"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="id" value="<?=h((string)$editing['id'])?>"><div class="grid"><label>Name *<input name="name" value="<?=h($editing['name'])?>" required></label><label>Firma<input name="company" value="<?=h($editing['company'])?>"></label><label>Telefon<input name="telephone" value="<?=h($editing['telephone'])?>" inputmode="tel"></label><label>Mobil<input name="mobile" value="<?=h($editing['mobile'])?>" inputmode="tel"></label><label>E-Mail<input name="email" value="<?=h($editing['email'])?>" type="email"></label></div><p><button>Speichern</button><?php if ($editing['id'] !== ''): ?> <a class="button secondary" href="/">Abbrechen</a><?php endif ?></p></form></section>
<section class="card"><form method="get"><label>Kontakte suchen<input name="q" value="<?=h($query)?>" placeholder="Name, Firma, Nummer oder E-Mail"></label><p><button>Filtern</button> <a class="button secondary" href="/">Zurücksetzen</a></p></form></section><section><h2>Kontakte (<?=count($allContacts)?>)</h2><table><thead><tr><th>Name</th><th>Firma</th><th>Telefon</th><th>Mobil</th><th>E-Mail</th><th></th></tr></thead><tbody><?php foreach ($allContacts as $contact): ?><tr><td><?=h($contact['name'])?></td><td><?=h($contact['company'])?></td><td><?=h($contact['telephone'])?></td><td><?=h($contact['mobile'])?></td><td><?=h($contact['email'])?></td><td><a href="/?edit=<?=$contact['id']?>">Bearbeiten</a> <form class="inline" method="post" onsubmit="return confirm('Kontakt löschen?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$contact['id']?>"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><button class="danger">Löschen</button></form></td></tr><?php endforeach ?></tbody></table></section></main><?php endif ?></body></html>
