# MailHub images

The custom images behind three Yundera store mail apps: **MailHub** (several mail accounts fetched
into one mailbox, with webmail, calendar, contacts, and an MCP server for AI assistants), **Cypht**
(every account live over IMAP, with our invitation and calendar modules) and **SnappyMail** (stock).

The apps themselves (compose, seeded config, setup scripts, rationale) live in the store:
`Yundera/AppStoreLab` → `Apps/MailHub`, `Apps/Cypht`, `Apps/SnappyMail`. This repo holds only what
has to be built into an image.

| Image | Folder | What it is |
|---|---|---|
| `ghcr.io/worph/mailhub-roundcube` | `roundcube/` | Roundcube + Kolab calendar and address book plugins (CalDAV / CardDAV drivers) + identity_smtp (send through each identity's own provider) + our `mailhub_dav` and `mailhub_accounts` plugins |
| `ghcr.io/worph/mailhub-getmail` | `getmail/` | getmail6 in a loop: fetches every `/config/*.getmailrc` into Dovecot over LMTP, within ~5 s of a change to that folder, and writes `/state/status/<account>.json`. Runs as uid 33 (the webmail's www-data), which writes the account files |
| `ghcr.io/worph/mailhub-mcp` | `mcp/` | MCP server over the unified mailbox (IMAP), calendar (CalDAV) and contacts (CardDAV). Read-only unless `MAILHUB_MCP_WRITE=true`; never sends mail |
| `ghcr.io/worph/mailhub-cypht` | `cypht/` | Official Cypht image + `mailhub_invites` (Accept / Maybe / Decline, written to CalDAV, reply to the organizer), `mailhub_calendar` (CalDAV calendar page, FullCalendar), cypht-gateway's bridge module, TLS certificate checks for public mail servers, no Xdebug. Used by the Cypht app |

Built by `.github/workflows/upstream.yml`, **unchanged** from an upstream tag (no image of that
version is published upstream), on a push to main that changes that file or by hand (Run workflow):

| Image | Upstream | Why |
|---|---|---|
| `ghcr.io/worph/snappymail:3.0.0` | the-djmaze/snappymail `v3.0.0`, `.docker/release/Dockerfile` | Docker Hub stops at v2.38.2.1, without 3.0.0's security fixes. Drop once `djmaze/snappymail:v3.0.0` exists |
| `ghcr.io/worph/cypht-gateway:0.6.0` | ashanzzz/cypht-gateway `v0.6.0` | The project publishes no image. amd64 only (Rust under QEMU is too slow) |

## roundcube

- **Upstream versions** are build args at the top of `roundcube/Dockerfile` (Roundcube, Kolab plugins,
  identity_smtp).
- **`patches/`**: our changes to upstream plugins, applied with `git apply`. A patch that no longer
  applies after a version bump fails the build.
  - `identity_smtp-calendar-task.patch`: invitation replies (calendar task) go through the identity's SMTP.
  - `libkolab-dav-owner.patch`: a DAV account whose name differs from the mail login still counts as
    "my calendars" (`kolab_dav_owner`, set by `mailhub_dav`).

  Both are worth proposing upstream.
- **`plugins/mailhub_dav/`**: loads the calendar and contacts plugins against MailHub's built-in Baïkal,
  or against another CalDAV/CardDAV server set in *Settings → Calendar & contacts* (tested before saving,
  password stored encrypted).
- **`plugins/mailhub_accounts/`**: *Settings → Mail accounts*. Adding an address (Gmail, Namecheap Private
  Email, or any IMAP/SMTP provider) tests the IMAP and SMTP logins, writes its getmail file, and creates its
  identity with the provider's SMTP in identity_smtp's format, in one save. Each account shows its last
  fetch; it can be renamed, switched to delete-at-source, given a new app password, or removed (fetching
  stops; mail and identity stay). Hand-written getmail files are listed read-only. Settings:
  `mailhub_accounts_dir` (`/var/mailhub/accounts`), `mailhub_fetch_state_dir` (`/var/mailhub/fetch`),
  `mailhub_lmtp_host` / `_port` (`mailhub-imap:2424`), `mailhub_lmtp_user` (the IMAP login).
- **Per-address folder**: each account delivers into its own folder, named after the address by default
  (*Folder* field; empty = Inbox). getmail hands its mail to `"admin+<folder>"@mailhub` over LMTP: Dovecot
  files user+detail into the detail's folder (`lmtp_save_to_detail_mailbox`), the quoted local part lets the
  folder carry an `@`, and `auth_username_format` drops the domain so it is still user `admin` (both set in
  the store's `dovecot/mailhub.conf`). The *saving* message shows while logins are checked.
- **Skins**: 11 to choose from in *Settings → User interface*, the app's default being
  [Elastic2022](https://github.com/seb1k/Elastic2022): Roundcube's Elastic,
  [Workbench](https://github.com/AR-Sebastian/roundcube-skin-workbench),
  [Elastic2025](https://github.com/bijanbina/Elastic2025),
  [Stretched Elastic](https://github.com/johndoh/roundcube-stretchedelastic),
  [Outlook](https://github.com/theaustinayers/Outlook-Skin-for-RoundCube),
  [Air and Air Blue](https://github.com/skive/roundcube-air-skin),
  [Elastic Google](https://github.com/nyouaku/elastic-google), [hellomail](https://github.com/dcwq/hellomail)
  and [Elastic Accessibility](https://github.com/nicolas-joubert/roundcube-skins-elastic-accessibility).
  All CC BY-SA 3.0 or GPL-3.0, pinned by tag or commit in the Dockerfile. Outlook and Air are full Elastic
  copies rather than `extends: elastic`, so the Dockerfile links each plugin's Elastic templates for them
  (without it the calendar says "File not found"). Tried and left out: Ultimate Gmail (ships unbuilt, renders
  unstyled) and Larry (not responsive). `dev/skin-shots.js` screenshots each skin (mail, calendar, settings).
- **`post-setup/10-plugin-schemas.php`**: creates and upgrades the plugins' database tables (the official
  image only handles Roundcube's own), with a fix for libkolab's duplicate SQLite index name.

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

CI builds all three images (amd64 + arm64) on every push and PR, and checks the MCP tool count.
Pushing a tag `vX.Y.Z` publishes them as `ghcr.io/worph/mailhub-<image>:X.Y.Z`; then bump the three
tags in the store's `Apps/MailHub/docker-compose.yml`. After the first release, make the three GHCR
packages public (GitHub → Packages → package settings) so boxes can pull them without credentials.
