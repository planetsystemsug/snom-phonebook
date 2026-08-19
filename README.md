# snom-phonebook

Small, self-hosted central phonebook for three Snom D862 phones. It uses PHP,
SQLite, and one Docker container; it has no PBX, LDAP, Active Directory or
external runtime dependency.

## Phone XML endpoint

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

## QNAP Container Station deployment

1. Clone this Git repository to a QNAP shared folder, for example
   `/share/Container/snom-phonebook`. Do not use the Windows PC as a server.
2. In that folder, copy `.env.example` to `.env` and generate two password
   hashes on a trusted machine with PHP:

   ```sh
   php -r "echo password_hash('a-long-admin-password', PASSWORD_DEFAULT), PHP_EOL;"
   php -r "echo password_hash('a-separate-phone-password', PASSWORD_DEFAULT), PHP_EOL;"
   ```

   Put the first hash in `ADMIN_PASSWORD_HASH`, a random value of at least 32
   characters in `APP_SECRET`, and optionally put `phone` and the second hash
   in `PHONEBOOK_AUTH_USER` and `PHONEBOOK_AUTH_PASSWORD_HASH`.
3. **Do not paste the Compose YAML into Container Station's Create
   Application editor.** That editor validates a temporary YAML file and does
   not load the adjacent `.env` file, so it cannot resolve the required
   variables. Enable SSH on the NAS, then run the following from the repository
   directory on the NAS (not from the Windows PC):

   ```sh
   cd /share/Container/snom-phonebook
   docker compose --env-file .env -p snom-phonebook up -d --build
   ```

   Container Station will still show and manage the resulting container. The
   named `phonebook-data` volume holds the SQLite database outside the web root
   and survives container replacement. For later updates, run `git pull` in the
   same directory, then repeat this command.
4. Permit the NAS port `8080` only on the trusted LAN. Browse to
   `http://NAS-HOSTNAME:8080/`, sign in with `ADMIN_USERNAME` and the password
   used to produce `ADMIN_PASSWORD_HASH`, and add contacts.
5. Confirm `http://NAS-HOSTNAME:8080/health.php` returns `ok`. If phone
   authentication is enabled, confirm the XML endpoint with:

   ```sh
   curl -u phone:a-separate-phone-password http://NAS-HOSTNAME:8080/phonebook.xml.php
   ```
6. On each phone, create an **Externes Verzeichnis** entry named, for example,
   `Firma`; set URL to `http://NAS-HOSTNAME:8080/phonebook.xml.php`, configure
   the same optional HTTP username/password, choose a polling interval, and
   verify one contact on the actual phone. Do not hard-code the QNAP IP address.

Use HTTPS and a certificate trusted by the phones if the NAS provides a reverse
proxy; otherwise keep this service on an isolated trusted LAN. The XML endpoint
sends `Cache-Control: no-cache` so phone polls see recent changes.

## Local tests

PHP 8.2+ with `pdo_sqlite` and `xmlwriter` is required. Run:

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
