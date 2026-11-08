# MailHub images

The custom images behind three Yundera store mail apps: **MailHub** (several mail accounts fetched
into one mailbox, with webmail, calendar, contacts, and an MCP server for AI assistants), **Cypht**
(every account live over IMAP, with our invitation and calendar modules) and **SnappyMail** (stock).

The apps themselves (compose, seeded config, setup scripts, rationale) live in the store:
`Yundera/AppStore` → `Apps/MailHub`, `Apps/Cypht`, `Apps/SnappyMail`. This repo holds only what
has to be built into an image. MailHub's webmail image, `ghcr.io/worph/mailhub-roundcube`, has its own repo
since 0.4.0: [worph/mailhub-roundcube](https://github.com/worph/mailhub-roundcube) (history kept there).

| Image | Folder | What it is |
|---|---|---|
| `ghcr.io/worph/mailhub-mirror` | `mirror/` | Every provider mailbox as a local copy under `Live/<address>/`: mbsync pull only (nothing in that direction writes to the provider), the IMAP actions done there (recorded by the store's Dovecot Lua hook) replayed at the provider as the same commands, `Virtual/All inboxes`, `Unread`, `Flagged` regenerated from the accounts and folders that exist, a status per account for Settings → Mail accounts. Replaced getmail in the app with 0.4.0 |
| `ghcr.io/worph/mailhub-getmail` | `getmail/` | (before 0.4.0) getmail6 in a loop, copying each provider's INBOX into Dovecot over LMTP. No longer used by the app nor built by CI |
| `ghcr.io/worph/mailhub-mcp` | `mcp/` | MCP server over the unified mailbox (IMAP), calendar (CalDAV) and contacts (CardDAV). Read-only unless `MAILHUB_MCP_WRITE=true`; never sends mail |
| `ghcr.io/worph/mailhub-cypht` | `cypht/` | Official Cypht image + `mailhub_invites` (Accept / Maybe / Decline, written to CalDAV, reply to the organizer), `mailhub_calendar` (CalDAV calendar page, FullCalendar), cypht-gateway's bridge module, TLS certificate checks for public mail servers, no Xdebug. Used by the Cypht app |

Built by `.github/workflows/upstream.yml`, **unchanged** from an upstream tag (no image of that
version is published upstream), on a push to main that changes that file or by hand (Run workflow):

| Image | Upstream | Why |
|---|---|---|
| `ghcr.io/worph/snappymail:3.0.0` | the-djmaze/snappymail `v3.0.0`, `.docker/release/Dockerfile` | Docker Hub stops at v2.38.2.1, without 3.0.0's security fixes. Drop once `djmaze/snappymail:v3.0.0` exists |
| `ghcr.io/worph/cypht-gateway:0.6.0` | ashanzzz/cypht-gateway `v0.6.0` | The project publishes no image. amd64 only (Rust under QEMU is too slow) |

## mcp

Settings: `MAILHUB_PASSWORD` (required), `MAILHUB_USER` (`admin`), `MAILHUB_IMAP_HOST` / `_PORT`
(`mailhub-imap:31143`), `MAILHUB_DAV_URL` (`http://mailhub-dav/dav.php`), `MAILHUB_ROUNDCUBE_DB`
(`/roundcube/sqlite.db`, read for the sending identities), `MAILHUB_TZ`, `MAILHUB_MCP_WRITE`, `PORT` (8080).
Serves stateless streamable HTTP on `/mcp`. In the app, a beaconify sidecar announces it to Beacon.

`python mcp/check_tools.py` checks that it registers 7 tools (13 with `MAILHUB_MCP_WRITE=true`).

## dev

The spike's test kit. `fakeprovider.compose.yml` runs GreenMail as a fake Gmail / Namecheap on the app's
network; `accounts/` are the matching getmail accounts. The scripts send test mail and invitations,
accept one through the webmail, add/edit/remove an account in Settings → Mail accounts (`accounts-test.py`),
send as an address through its own SMTP (`send-as-test.py`), and call the MCP tools. `spike-notes.md` is the spike's log (wisera,
2026-11-04): what was checked, the fixes, the open points. It predates this repo, so it still says
`images/` for what is now the top-level folders.

## Releasing

CI builds the mirror, mcp and cypht images (amd64 + arm64) on every push and PR, and checks the MCP tool count.
Pushing a tag `vX.Y.Z` publishes them as `ghcr.io/worph/mailhub-<image>:X.Y.Z`; then bump the mirror and mcp
tags in the store's `Apps/MailHub/docker-compose.yml`. After the first release, make the GHCR
packages public (GitHub → Packages → package settings) so boxes can pull them without credentials.
