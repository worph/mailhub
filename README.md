# MailHub images

The custom images behind the **MailHub** Yundera store app: several mail accounts fetched into
one mailbox, with webmail, calendar, contacts, and an MCP server for AI assistants.

The app itself (compose, seeded config, setup script, rationale) lives in the store:
`Yundera/AppStoreLab` → `Apps/MailHub`. This repo holds only what has to be built into an image.

| Image | Folder | What it is |
|---|---|---|
| `ghcr.io/worph/mailhub-roundcube` | `roundcube/` | Roundcube + Kolab calendar and address book plugins (CalDAV / CardDAV drivers) + identity_smtp (send through each identity's own provider) + our `mailhub_dav` plugin |
| `ghcr.io/worph/mailhub-getmail` | `getmail/` | getmail6 in a loop: fetches every `/config/*.getmailrc` into Dovecot over LMTP and writes `/state/status/<account>.json` |
| `ghcr.io/worph/mailhub-mcp` | `mcp/` | MCP server over the unified mailbox (IMAP), calendar (CalDAV) and contacts (CardDAV). Read-only unless `MAILHUB_MCP_WRITE=true`; never sends mail |

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
accept one through the webmail, and call the MCP tools. `spike-notes.md` is the spike's log (wisera,
2026-11-04): what was checked, the fixes, the open points. It predates this repo, so it still says
`images/` for what is now the top-level folders.

## Releasing

CI builds all three images (amd64 + arm64) on every push and PR, and checks the MCP tool count.
Pushing a tag `vX.Y.Z` publishes them as `ghcr.io/worph/mailhub-<image>:X.Y.Z`; then bump the three
tags in the store's `Apps/MailHub/docker-compose.yml`. After the first release, make the three GHCR
packages public (GitHub → Packages → package settings) so boxes can pull them without credentials.
