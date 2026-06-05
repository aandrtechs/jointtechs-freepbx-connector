# Jointtechs FreePBX Connector

Read-only FreePBX module that pairs customer PBX boxes with the hosted Jointtechs Voice Portal.

FreePBX Module Admin upload/download accepts archives such as `.tgz` and `.zip`, not a `.git` URL. Use the release archive URL when pasting a URL into Module Admin:

```bash
fwconsole ma downloadinstall https://github.com/aandrtechs/jointtechs-freepbx-connector/releases/download/v0.3.1/jointtechsconnector-0.3.1.tgz
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
https://github.com/aandrtechs/jointtechs-freepbx-connector/releases/download/v0.3.1/jointtechsconnector-0.3.1.tgz
```

V1 behavior:

- Module auto-registers with `https://portal.joint.tech` during install/config load.
- Portal lists the PBX under `Unassigned PBX Boxes`.
- Jointtechs assigns the PBX to the correct customer from `/admin/pbx`.
- Optional pairing-code flow remains available as a backup.
- Portal returns PBX ID, token, and action secret.
- Module stores token locally.
- `bin/heartbeat.php` sends PBX/Asterisk/module versions, hostname, disk status, and last sync status.
- `bin/sync-calls.php` reads recent Asterisk CDR records and posts them to `/api/pbx/sync/calls`.
- `bin/sync-recordings.php` reads recording metadata and posts it to `/api/pbx/sync/recordings`.
- Connector is read-only in v1.
- Connector uses outbound HTTPS for pairing/sync and signed inbound HTTPS for portal-triggered actions/playback.
- v0.2.1 reads recent rows from `asteriskcdrdb.cdr`, maps native CDR columns, and sends recording file paths when available.
- v0.2.2 simplifies pairing and receives portal-triggered signed actions through FreePBX `admin/ajax.php` because direct module PHP files may be blocked by Apache.
- v0.3.0 auto-registers on install and discovers hostname, connector URL, local IP, versions, CDR columns, and recording path.
- v0.3.1 sends approved recording bytes to the portal over the signed action channel so the portal can play/download from a 10-minute temporary cache.

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
- `bin/heartbeat.php`: Heartbeat worker.
- `bin/sync-calls.php`: CDR sync worker.
- `bin/sync-recordings.php`: Recording metadata sync worker.
- `config.example.json`: Example local connector config shape.
