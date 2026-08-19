# Project Instructions

## Purpose

This repository contains a centralized XML phonebook application for three Snom D862 SIP desk phones.

## Target environment

- Snom D862
- Firmware: 10.1.226.13
- QNAP NAS
- Docker/Container Station
- SQLite
- PHP/web application

## Architecture

Browser
  -> Web application
  -> SQLite
  -> Snom XML endpoint
  -> Snom D862 phones

## Constraints

- No PBX.
- No Active Directory.
- No LDAP.
- No MySQL/MariaDB.
- No dependency on the Windows 10 PC.
- Runtime must not require Internet access.
- Keep dependencies minimal.

## Snom protocol

The D862 configuration function being targeted is:

Telefonbuch -> Externes Verzeichnis

Configuration fields:

- Name
- Benutzer
- URL
- Passwort
- Intervall

The exact XML protocol must be verified before implementation. Do not assume that Snom XML MiniBrowser XML is the same protocol as the external-directory XML protocol.

## Database

Use SQLite.

The database must be stored outside the web root and on a persistent Docker volume.

## Security

- Passwords use secure password hashing.
- All management operations require authentication.
- Use CSRF protection.
- Escape HTML.
- Escape XML.
- Never expose SQLite files through HTTP.
- Never commit secrets.

## Code quality

- Prefer simple, maintainable code over frameworks and unnecessary abstractions.
- Every protocol assumption must be documented.
- Add automated tests for XML generation and HTTP endpoints.
- German UTF-8 text must work correctly.

## Deployment

The final repository must contain:

- Dockerfile
- docker-compose.yml
- README.md
- .gitignore
- application source
- tests
- Snom protocol documentation

The README must contain exact QNAP deployment instructions.