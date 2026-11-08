# MailHub images

The custom images behind three Yundera store mail apps: **MailHub** (several mail accounts fetched
into one mailbox, with webmail, calendar, contacts, and an MCP server for AI assistants), **Cypht**
(every account live over IMAP, with our invitation and calendar modules) and **SnappyMail** (stock).

The apps themselves (compose, seeded config, setup scripts, rationale) live in the store:
`Yundera/AppStore` → `Apps/MailHub`, `Apps/Cypht`, `Apps/SnappyMail`. This repo holds the Cypht image,
the stock-upstream builds and the test kit. MailHub's own images have their own repos (history kept there):

| Image | Repo | Since |
|---|---|---|
| `ghcr.io/worph/mailhub-roundcube` | [worph/mailhub-roundcube](https://github.com/worph/mailhub-roundcube): the webmail, Kolab calendar and contacts, Settings → Mail accounts | 0.4.1 |
| `ghcr.io/worph/mailhub-mirror` | [worph/mailhub-mirror](https://github.com/worph/mailhub-mirror): every provider mailbox as a local copy, actions replayed at the provider, the Merged views | 0.4.2 |
| `ghcr.io/worph/mailhub-mcp` | [worph/mailhub-mcp](https://github.com/worph/mailhub-mcp): MCP server over the mailbox, calendar and contacts | 0.4.2 |

| Image | Folder | What it is |
|---|---|---|
| `ghcr.io/worph/mailhub-getmail` | `getmail/` | (before 0.4.0) getmail6 in a loop, copying each provider's INBOX into Dovecot over LMTP. No longer used by the app nor built by CI |
| `ghcr.io/worph/mailhub-cypht` | `cypht/` | Official Cypht image + `mailhub_invites` (Accept / Maybe / Decline, written to CalDAV, reply to the organizer), `mailhub_calendar` (CalDAV calendar page, FullCalendar), cypht-gateway's bridge module, TLS certificate checks for public mail servers, no Xdebug. Used by the Cypht app |

Built by `.github/workflows/upstream.yml`, **unchanged** from an upstream tag (no image of that
version is published upstream), on a push to main that changes that file or by hand (Run workflow):

| Image | Upstream | Why |
|---|---|---|
| `ghcr.io/worph/snappymail:3.0.0` | the-djmaze/snappymail `v3.0.0`, `.docker/release/Dockerfile` | Docker Hub stops at v2.38.2.1, without 3.0.0's security fixes. Drop once `djmaze/snappymail:v3.0.0` exists |
| `ghcr.io/worph/cypht-gateway:0.6.0` | ashanzzz/cypht-gateway `v0.6.0` | The project publishes no image. amd64 only (Rust under QEMU is too slow) |

## dev

The spike's test kit. `fakeprovider.compose.yml` runs GreenMail as a fake Gmail / Namecheap on the app's
network; `accounts/` are the matching getmail accounts. The scripts send test mail and invitations,
accept one through the webmail, add/edit/remove an account in Settings → Mail accounts (`accounts-test.py`),
send as an address through its own SMTP (`send-as-test.py`), and call the MCP tools. `spike-notes.md` is the spike's log (wisera,
2026-11-04): what was checked, the fixes, the open points. It predates this repo, so it still says
`images/` for what is now the top-level folders.

## Releasing

CI builds the cypht image (amd64 + arm64) on every push and PR. Pushing a tag `vX.Y.Z` publishes it as
`ghcr.io/worph/mailhub-cypht:X.Y.Z`; then bump its tag in the store's `Apps/Cypht/docker-compose.yml`.
After the first release, make the GHCR package public (GitHub → Packages → package settings) so boxes can
pull it without credentials.
