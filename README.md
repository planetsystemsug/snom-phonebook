# snom-phonebook

Small, self-hosted central phonebook for three Snom D862 phones. It uses PHP,
SQLite, and one Docker container; it has no PBX, LDAP, Active Directory or
external runtime dependency.

## Phone XML endpoints

The endpoint is `GET /phonebook.xml.php`. It responds with UTF-8 XML using the
project-approved `SnomIPPhoneDirectory` format:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<SnomIPPhoneDirectory>
  <Title>Zentrales Telefonbuch</Title>
  <DirectoryEntry><Name>Erika Mustermann — Beispiel GmbH</Name><Telephone>+49 30 1234</Telephone></DirectoryEntry>
</SnomIPPhoneDirectory>
```

Mobile numbers produce an additional entry marked `(Mobil)`. E-mail addresses
are retained for administration but cannot be represented by this small
name/telephone XML schema. See [protocol decision](docs/snom-d862.md) for the
compatibility caveat and Snom source.

The additional, vendor-documented Remote XML Directory endpoint is
`GET` or `POST /remote-directory.xml.php`. It emits the D86x remote-directory
`tbook` 2.0 schema, supports multiple typed numbers per contact, and is the
recommended endpoint for the **Externes Verzeichnis** polling feature:

```xml
<tbook e="2" version="2.0">
  <contact fav="false" vip="false" blocked="false">
    <first_name>Erika</first_name><last_name>Mustermann</last_name>
    <numbers><number no="+49 30 1234" type="fixed" outgoing_id="0"/></numbers>
  </contact>
</tbook>
```

## QNAP Container Station deployment

1. Keep the Git checkout on the development PC. Copy the repository contents
   to a QNAP shared folder, for example `/share/Container/snom-phonebook`, via
   SMB/File Station. Do not use the Windows PC as a server. The QNAP does not
   need Git installed.
2. In that folder, copy `.env.example` to `.env` and generate two password
   hashes on a trusted machine with PHP:

   ```sh
   php -r "echo password_hash('a-long-admin-password', PASSWORD_DEFAULT), PHP_EOL;"
   php -r "echo password_hash('a-separate-phone-password', PASSWORD_DEFAULT), PHP_EOL;"
   ```

   Put the first hash in `ADMIN_PASSWORD_HASH`, a random value of at least 32
   characters in `APP_SECRET`, and optionally put `phone` and the second hash
   in `PHONEBOOK_AUTH_USER` and `PHONEBOOK_AUTH_PASSWORD_HASH`. **Put password
   hashes in single quotes**, because bcrypt hashes contain `$` characters and
   Compose interpolates unquoted `.env` values:

   ```dotenv
   ADMIN_USERNAME=admin
   ADMIN_PASSWORD_HASH='$2y$10$paste-the-complete-generated-hash-here'
   PHONEBOOK_AUTH_USER=phone
   PHONEBOOK_AUTH_PASSWORD_HASH='$2y$10$paste-the-complete-generated-hash-here'
   ```
3. **Do not paste the Compose YAML into Container Station's Create
   Application editor.** That editor validates a temporary YAML file and does
   not load the adjacent `.env` file, so it cannot resolve the required
   variables. Enable SSH on the NAS, then run the following from the repository
   directory on the NAS (not from the Windows PC):

   ```sh
   cd /share/Container/snom-phonebook
   docker compose --env-file .env -p snom-phonebook up -d --build --force-recreate
   ```

   Container Station will still show and manage the resulting container. The
   named `phonebook-data` volume holds the SQLite database outside the web root
   and survives container replacement. For later updates, pull the changes on
   the development PC, copy the changed project files to this same QNAP folder
   without overwriting `.env`, then repeat this command.

   Container logs rotate automatically: three files of at most 5 MB are kept
   (15 MB total). Recreating the container removes its old Docker-managed log;
   the persistent phonebook data volume is not affected.
4. Permit the NAS port `8081` only on the trusted LAN. Browse to
   `http://NAS-HOSTNAME:8081/`, sign in with `ADMIN_USERNAME` and the password
   used to produce `ADMIN_PASSWORD_HASH`, and add contacts.
5. Confirm `http://NAS-HOSTNAME:8081/health.php` returns `ok`. Docker's
   internal health check validates SQLite directly and does not create noisy
   Apache access-log requests. If phone
   authentication is enabled, confirm the XML endpoint with:

   ```sh
   curl -u phone:a-separate-phone-password http://NAS-HOSTNAME:8081/phonebook.xml.php
   ```
6. On each phone, create an **Externes Verzeichnis** entry named, for example,
   `Firma`; set URL to
   `http://NAS-HOSTNAME:8081/remote-directory.xml.php`, configure the same
   optional HTTP username/password, and choose an interval between 3600 and
   1209600 seconds. Reboot the phone after applying the setting and verify one
   contact. Do not hard-code the QNAP IP address.

Use HTTPS and a certificate trusted by the phones if the NAS provides a reverse
proxy; otherwise keep this service on an isolated trusted LAN. The XML endpoint
sends `Cache-Control: no-cache` so phone polls see recent changes.

## FRITZ!Box master phonebook sync

The application can import one FRITZ!Box phonebook one-way over the local
TR-064 `X_AVM-DE_OnTel` interface. It never writes to the router. Existing
manually created contacts are retained; contacts previously imported from the
FRITZ!Box are added, updated, or removed to mirror the selected FRITZ!Box
phonebook. Imported numbers are normalised to international format (German
domestic numbers with a leading `0`, or the `00` access code, become E.164-style;
e.g. `030 1234567` becomes `+49301234567`). FRITZ!Box internal function codes —
numbers starting with `*`, such as `**41` Wecker or `**603` Leitungen belegt — are
not imported. This uses AVM's documented `GetPhonebook` interface; see
[AVM interfaces](https://fritz.com/en/pages/interfaces).

1. In the FRITZ!Box, enable TR-064 and create a dedicated user that has only
   the phonebook permission. Do not use the router administrator account.
2. In the application, sign in and open **FRITZ!Box-Synchronisierung**. Enter
   the local control URL (normally
   `http://fritz.box:49000/upnp/control/x_contact`), that dedicated username,
   its password, and the phonebook ID (normally `0`). Save, then use **Jetzt
   synchronisieren** to test the connection.
   This is a local trusted-LAN URL. Prefer a local HTTPS control URL when the
   FRITZ!Box certificate can be verified by the container; the application
   deliberately does not disable TLS certificate verification.
   If the test reports an HTTP 500 error, use the displayed SOAP fault text to
   correct the selected phonebook ID or the dedicated user's **Phone**/
   phonebook permission; `0` is the normal default phonebook.
3. The password is encrypted in SQLite with `APP_SECRET`; it is not stored in
   `.env` and is never displayed by the UI.
4. To schedule the sync on the QNAP, create a QNAP Task Scheduler job. Run it
   hourly (or another suitable interval) as an administrator:

   ```sh
   docker exec snom-phonebook php /var/www/html/bin/fritzbox-sync.php
   ```

   The phone poll interval should be equal to or shorter than this task's
   interval if you want updates to appear promptly. The task uses no Internet
   access and communicates only with the local FRITZ!Box. FRITZ!Box-derived
   contacts are read-only in this application: edit or delete them at the
   FRITZ!Box, then run the sync.

## Local tests

PHP 8.2+ with `pdo_sqlite`, `xmlwriter`, `curl`, `dom`, and `sodium` is
required. Run:

```sh
php tests/run.php
```

The tests cover generated XML, German umlauts and XML special characters, an
empty phonebook, invalid contact data, and optional HTTP Basic authentication.

### Why Container Station rejected `.env`

The Compose file intentionally uses the `${VARIABLE:?message}` form to fail
closed when a secret is absent. Docker Compose loads `.env` only from its
project directory (or one explicitly supplied with `--env-file`). Container
Station's YAML editor instead converts a temporary file, which is why its
validator reports `ADMIN_PASSWORD_HASH` as missing even when your repository's
`.env` is correct. Use the SSH command above; do not weaken the Compose file by
putting passwords into Git or by adding insecure defaults.

## Security

Management uses a password hash stored in configuration, server-side sessions,
session-id regeneration at login, CSRF tokens on every POST, HTML escaping, and
strict server-side input validation. The phone endpoint supports optional HTTP
Basic authentication with a password hash. Never commit `.env`, database files,
or generated runtime data.

To reset the management password, generate a new `ADMIN_PASSWORD_HASH` from
the new plaintext password, replace only that single-quoted value in `.env`,
and rerun the deployment command with `--force-recreate`. The configured admin
account is synchronized on startup; the SQLite database itself remains intact.
