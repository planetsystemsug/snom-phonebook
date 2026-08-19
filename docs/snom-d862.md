# Snom D862 External Directory protocol investigation

## Status: not verified — implementation blocked

The target phone is a Snom D862 running firmware `10.1.226.13`.  The intended
feature is the phone UI path **Telefonbuch → Externes Verzeichnis**, which has
the configuration fields **Name**, **Benutzer**, **URL**, **Passwort**, and
**Intervall**.

Snom's published documentation available during this investigation does **not**
state the XML request/response contract for that particular D862 feature and
firmware version.  It therefore does not establish that a URL configured there
accepts a static `tbook` document, a Snom XML MiniBrowser document, another
Snom directory schema, or a server-side search API.  It also does not document
the authentication scheme used by the *Benutzer* and *Passwort* fields, any
request query parameters, pagination/search behaviour, or a response content
type.

No XML endpoint or phonebook application may be implemented until this is
verified.  In particular, `SnomIPPhoneDirectory` must not be used by
assumption: Snom documents it as an XML MiniBrowser item and marks it
deprecated, which is not evidence that the External Directory configuration
uses it.

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

## Required evidence to unblock

Provide at least one of the following for a D862 running `10.1.226.13`:

- The matching Snom administrator/provisioning manual section that defines the
  External Directory URL's HTTP exchange and XML schema.
- A Snom support case response confirming the protocol, authentication method,
  request parameters, and a complete sample response for this exact feature.
- A packet capture (with credentials redacted) of one of the phones requesting
  a known-good External Directory, together with that server's XML response.

The evidence must confirm the root element, required/optional elements and
attributes, UTF-8/content-type requirements, search/paging semantics, and the
HTTP authentication method.  Once supplied, this document should be updated
with the exact contract and source before implementation resumes.

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
