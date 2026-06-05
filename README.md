# Jointtechs FreePBX Connector

Read-only FreePBX module that pairs customer PBX boxes with the hosted Jointtechs Voice Portal.

FreePBX Module Admin upload/download accepts archives such as `.tgz` and `.zip`, not a `.git` URL. Use the release archive URL when pasting a URL into Module Admin:

```bash
fwconsole ma downloadinstall https://github.com/aandrtechs/jointtechs-freepbx-connector/releases/download/v0.2.0/jointtechsconnector-0.2.0.tgz
fwconsole ma install jointtechsconnector
fwconsole ma enable jointtechsconnector
fwconsole reload
```

If installing manually from Git:

```bash
cd /var/www/html/admin/modules
git clone https://github.com/aandrtechs/jointtechs-freepbx-connector.git jointtechsconnector
fwconsole ma install jointtechsconnector
fwconsole ma enable jointtechsconnector
fwconsole reload
```

If installing from the FreePBX web UI, paste this URL into Module Admin's upload/download URL field:

```text
https://github.com/aandrtechs/jointtechs-freepbx-connector/releases/download/v0.2.0/jointtechsconnector-0.2.0.tgz
```

V1 behavior:

- Admin enters portal URL and pairing code.
- Module calls `POST /api/pbx/pair`.
- Portal returns PBX ID and token.
- Module stores token locally.
- `bin/heartbeat.php` sends PBX/Asterisk/module versions, hostname, disk status, and last sync status.
- `bin/sync-calls.php` reads recent Asterisk CDR records and posts them to `/api/pbx/sync/calls`.
- `bin/sync-recordings.php` reads recording metadata and posts it to `/api/pbx/sync/recordings`.
- Connector is read-only in v1.
- Connector uses outbound HTTPS for pairing/sync and signed inbound HTTPS for portal-triggered actions/playback in v0.2.0.

Target assumptions:

- FreePBX 17 on Debian 12 with PHP 8.2 and Asterisk 20/21.
- FreePBX 16+ where practical.
- CDR for v1 call history.
- CEL and AMI reserved for later phases.

## Module Metadata

The module descriptor uses:

- `rawname`: `jointtechsconnector`
- `name`: `Jointtechs Connector`
- `category`: `Connectivity`
- `publisher`: `Jointtechs`
- `license`: `GPLv3+`
- `supported`: `16.0`
- Requirements: `core`, `cdr`

## Files

- `module.xml`: FreePBX Module Admin descriptor.
- `Jointtechsconnector.class.php`: BMO module class, config storage helper, and AJAX pairing handler.
- `page.jointtechsconnector.php`: FreePBX admin page entry.
- `views/pairing.php`: Pairing form.
- `ajax.php`: FreePBX AJAX entry marker; command handlers live on the main module class.
- `bin/heartbeat.php`: Heartbeat worker scaffold.
- `bin/sync-calls.php`: CDR sync worker scaffold.
- `bin/sync-recordings.php`: Recording metadata sync worker scaffold.
- `config.example.json`: Example local connector config shape.
