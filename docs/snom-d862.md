# Snom D862 External Directory protocol investigation

## Status: Remote XML Directory verified

The target phone is a Snom D862 running firmware `10.1.226.13`.  The intended
feature is the phone UI path **Telefonbuch → Externes Verzeichnis**, which has
the configuration fields **Name**, **Benutzer**, **URL**, **Passwort**, and
**Intervall**.

The initial investigation did not locate the contract. On 2026-08-19, the
project owner supplied Snom's Remote XML Directory page, which provides the
required D86x `tbook` 2.0 contract. The following section records the verified
implementation.

The project owner explicitly approved the `SnomIPPhoneDirectory` standard on
2026-08-19. The implementation retains that schema as an intentional
compatibility endpoint, but it must not be described as vendor-verified for
this exact screen: Snom documents it as an XML MiniBrowser item and marks it
deprecated.

On 2026-08-19, the project owner also supplied Snom's **Remote XML Directory**
documentation. This is the verified protocol for the D86x remote directory
feature and is implemented at `/remote-directory.xml.php`.

## Verified Remote XML Directory contract

- Available from firmware 10.1.178.0; therefore supported by D862
  10.1.226.13.
- The phone downloads the XML periodically with HTTP `POST`; the endpoint also
  accepts `GET` to make browser diagnostics possible.
- Root element: `<tbook e="2" version="2.0">`.
- Each contact is `<contact fav="false" vip="false" blocked="false">` with
  `first_name`, `last_name`, and a `numbers` element.
- Each number is `<number no="…" type="fixed|mobile" outgoing_id="0"/>`.
  The endpoint maps the application telephone field to `fixed` and mobile to
  `mobile`.
- HTTP Basic authentication is supported when both phonebook authentication
  environment settings are set.
- Phones allow up to three remote XML directories, 1,000 entries each. The
  documented refresh interval is 3,600 to 1,209,600 seconds; reboot after
  configuring the directory.

## What was verified

1. Snom's 10.1.226.13 release notes list a D862 firmware image.  The same
   release notes mention changes to local `tbook` handling (including the old
   format); this is evidence that D862 firmware has local directory/tbook
   support, not evidence for the External Directory protocol.
2. Snom's XML MiniBrowser documentation describes
   `SnomIPPhoneDirectory` with `DirectoryEntry`, `Name`, and `Telephone`.
   It explicitly identifies this as a MiniBrowser XML item and marks it
   deprecated.
3. Snom's older D862/D865 release notes state that XML `tbook` file import was
   added to the **Local Directory** / Phone Manager.  This is a different
   feature from External Directory.

## Evidence still recommended

Provide at least one of the following for a D862 running `10.1.226.13`:

- The matching Snom administrator/provisioning manual section that defines the
  External Directory URL's HTTP exchange and XML schema.
- A Snom support case response confirming the protocol, authentication method,
  request parameters, and a complete sample response for this exact feature.
- A packet capture (with credentials redacted) of one of the phones requesting
  a known-good External Directory, together with that server's XML response.

The evidence should confirm the root element, required/optional elements and
attributes, UTF-8/content-type requirements, search/paging semantics, and the
HTTP authentication method. Once available, update this document and validate
the implementation against an actual D862 before production use.

## Sources consulted

- [Snom 10.1.226.13 release notes](https://service.snom.com/spaces/wiki/pages/392463956/10.1.226.13%2BRelease)
  — identifies the D862 firmware image and records a `tbook`-related fix; it
  does not specify the External Directory protocol.
- [SnomIPPhoneDirectory documentation](https://docs.snom.io/xml_minibrowser/main_tags/SnomIPPhoneDirectory/)
  — defines an XML **MiniBrowser** menu item, not the D862 External Directory
  URL contract.
- [D862/D865 10.1.169.13 release notes](https://service.snom.com/plugins/viewsource/viewpagesrc.action?pageId=234331356)
  — says XML `tbook` import was extended to the D8xx Local Directory / Phone
  Manager; it does not connect that import format to External Directory.
- [Snom Remote XML Directory](https://service.snom.com/spaces/wiki/pages/248578234/Remote%2BXML%2BDirectory)
  — authoritative remote `tbook` 2.0 schema, HTTP POST requirement, D86x
  behavior, authentication settings, interval, and capacity.
