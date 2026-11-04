# MailHub spike (wisera, 2026-11-04)

Runs on wisera (`ssh admin@80.241.218.30`) as a **Maison-managed app** at `/DATA/AppData/mailhub/`, laid out the
way a store install would leave it, but placed by hand (no store yet):

| On the box | From this folder |
|---|---|
| `/DATA/AppData/mailhub/docker-compose.yml`, `icon.png` | `docker-compose.yml`, `icon.png` |
| `/DATA/AppData/mailhub/.seed/` | `seed/` (Maison writes it into the app folder, create-if-absent) |
| `/DATA/AppData/mailhub/.scripts/` | `scripts/` (the `baikal-init` setup step mounts it) |
| `~/mailhub-build/` — `docker build -t mailhub-roundcube:spike roundcube`, `… mailhub-getmail:spike getmail` | `images/` (no registry yet) |
| `~/mailhub-spike/` — GreenMail, its own compose project on the app's network | `dev/` |

Bring it up **through Maison** (folders → secrets → setup steps → seed → routes → up), never with a bare
`docker compose up`. Maison's API answers on the `pcs` network when the Host is the dashboard's:

```bash
docker run --rm --network pcs curlimages/curl:8.11.1 -s -H "Host: maison-wisera.inojob.com" \
  -X POST http://maison-app:8080/api/apps/mailhub/start
cd ~/mailhub-spike && docker compose -f fakeprovider.compose.yml up -d   # needs mailhub_mailhub-internal
docker exec mailhub-fetch python /tmp/send-test-mails.py          # plain mail + iMIP invitation at the fake provider
docker exec mailhub-fetch python /tmp/itip2.py "$MAILHUB_PASSWORD" # login, identity+SMTP, accept, check Baïkal + organizer
```
(copy the scripts into the container first; `.env` holds `MAILHUB_PASSWORD`.)

Lint: `docker run --rm -v "$PWD:/s" -w /s --entrypoint maison ghcr.io/yundera/maison:1.1.88 lint MailHub/docker-compose.yml`

**Maison pitfalls hit on the way:** without `x-compose-app` the folder shows as a bare *service* (no route → no web
UI, no credentials); hand-written `caddy_*` labels are wrong (declare `routes`, Maison writes the labels per box
domain); a one-shot compose service (exited container) makes the tile `partial` forever — use a `steps` entry.

## Results

| Check | Result |
|---|---|
| getmail6 → LMTP → Dovecot, delete at source | ✅ both fake accounts fetched, source INBOX emptied, per-account status JSON written |
| Kolab plugins 3.6.1 on Roundcube 1.7.5 | ✅ after two fixes (below) |
| Invitation shows Accept / Maybe / Decline / Delegate | ✅ |
| Accept saves the event in Baïkal (CalDAV driver) | ✅ default calendar preselected |
| iMIP REPLY (PARTSTAT=ACCEPTED) reaches the organizer | ✅ |
| Reply leaves through the **identity's** SMTP, not the global one | ✅ only with the identity_smtp patch (global SMTP pointed at a dead host to prove it) |
| Contacts (kolab_addressbook, CardDAV driver) | ✅ Baïkal address book listed |
| Public IMAPS `mailhub.<domain>:993` | ✅ verified LE chain, using the box wildcard cert from Caddy storage |
| Public DAV `mailhubdav.<domain>/dav.php` (no AppShield, Baïkal basic auth) | ✅ 207 with creds, 401 without |
| Webmail behind AppShield | ✅ 302 to SSO on both routes |
| RAM | ~130 MB for the 5 real containers |

## Fixes the spike needed

1. **libkolab 3.6.1 `sqlite.initial.sql`** reuses the index name `ix_contact_type` on two tables → patched in
   `roundcube/post-setup/10-plugin-schemas.php`, which also inits/upgrades plugin schemas (the image only handles core).
   `calendar-caldav` ships no sqlite upgrade dir → skip `db_update` when absent.
2. **identity_smtp** loads only on `mail|settings`; iTIP replies are sent from the `calendar` task, so they went out
   through the global SMTP (SPF/DMARC fail with a real provider). Patched to `mail|settings|calendar` in the Dockerfile.
3. identity_smtp saves SMTP settings only when an **existing** identity is saved (create, then edit) — the future
   mailhub service should write identities + SMTP directly instead.
4. Kolab's libkolab Elastic CSS ships as LESS only → compiled in a build stage.

## Open points for the real app

- Double login: AppShield SSO, then Roundcube's own login. Dovecot's static passdb + Roundcube `autologon` behind AppShield.
- ~~Dovecot reads the cert at start: renewals need `doveadm reload`.~~ ~~The wildcard path is Caddy internals,
  not a platform contract.~~ Solved by Maison 1.1.92 `certificates` (see the section at the end).
- `/.well-known/caldav` redirects to `http://` (Baïkal nginx behind TLS proxy) — harmless, worth fixing.
- Roundcube image re-runs `installto` + composer on each start because `/var/www/html` is an anonymous volume: slow start.
- Real providers not tested yet (Gmail app passwords, Namecheap SMTP, `move_on_delete`/Gmail trash semantics).

## Split: MailHub + Baïkal as two apps (2026-11-04)

Baïkal is its own store app (`Apps/Baikal`, its own login, see its `rationale.md`). MailHub has no DAV server: the
`mailhub_dav` plugin (`roundcube/plugins/`) adds **Settings → Calendar & contacts** (URL, user, password),
tests the server before saving, stores the password encrypted, and loads `calendar` + `kolab_addressbook` only once
connected. Without a server MailHub shows no calendar (calendar task 404s).

Checked on wisera (`dev/connect-dav-test.py`, then `dev/itip-test.py <pw> <dav calendar url> me <baikal pw>`):
not connected → no calendar; wrong password / non-DAV URL → refused with a reason; connected → Baïkal's calendar and
address book in Roundcube; fresh invitation fetched → accepted → event in the Baïkal app → reply via the identity SMTP.

Two more fixes it took:
- **Ownership**: libkolab treats a DAV calendar as "someone else's" unless its owner equals the IMAP login
  (`me` ≠ `admin`), and invitations are only saved to your own → patched `kolab_storage_dav_folder.php` to use
  `kolab_dav_owner`, which `mailhub_dav` sets to the DAV user.
- **Cloudflare**: the gateway address `baikal-<domain>` (Cloudflare in front) rejects libkolab's HTTP client
  (`HTTP_Request2` curl adapter: 403 "error code: 1010" / connection reset), while curl and Guzzle pass. Root cause
  not found. The direct address `baikal.<domain>` works, so both apps' tips use it; the plugin's save-time test runs
  libkolab's own discovery so a refused address is caught with a hint. Worth raising with mesh-router (WAF rule on
  the gateway?) — phones were not tested through the gateway.
- Test-harness note: restarting GreenMail reuses UIDs, so getmail's `oldmail-*` state skips new mail — clear it.

## Back to one app: Baïkal built into MailHub (2026-11-04, same day)

After trying the two-app split on wisera, Baïkal as its own app felt too admin-like to set up for one person's
calendar. Baïkal is built into MailHub again; `Apps/Baikal` stays as a standalone store app for other uses.

What carried over from the split:
- `mailhub_dav` now defaults to the built-in server (`mailhub_dav_default_url`, same login as the mailbox, taken
  from the session — no setup). Settings → *Calendar & contacts* is only an optional override to another
  CalDAV/CardDAV server; clearing the URL goes back to the built-in one.
- The `kolab_dav_owner` patch (needed only for an external server whose user differs from the mail login).
- Baïkal's bootstrap is a Maison `setup` step (`scripts/baikal-init.php`), not a one-shot service.

New: the built-in Baïkal's nginx config (`seed/baikal/nginx.conf`, a `files` entry) serves `/dav.php` only — admin
console, welcome page and legacy endpoints 404 — and redirects `.well-known` relatively (no more `http://`). Its
admin password is a separate never-shown secret.

Checked: built-in status shown, calendar `personal`, phone address `https://mailhubdav.<domain>/dav.php` → 207 with the
mailbox login / 401 otherwise, fresh invitation fetched → accepted → stored in the built-in calendar → reply via the
identity SMTP.

## Backups (checked 2026-11-04)

Maison backs up the whole app folder with a stop for the short second pass (consistent SQLite + Maildir). Both
engines run as root, so the `0700` folders are readable. Every bind with state is inside the app folder (the
cert mount is read-only platform data). No `backup.exclude` on purpose — see the comment in the compose
(`getmail/state` must survive). Baïkal's folders are declared `101:101` (its nginx user) so Maison and the image
stop re-chowning them. wisera: nightly Kopia includes `app:mailhub`; an on-demand backup produced
`2026-11-04_124454` (remote, ~0.9 MB). **Restore not tested.**

## MCP + Beacon (2026-11-04)

`mailhub-mcp` (`mcp/server.py`, Python, `mcp` 2.3 `MCPServer`, stateless streamable HTTP) on the app
network only; `mailhub-beacon` (beaconify 1.0.2) announces it on `pcs` and proxies `/mcp`. Tools appear in Beacon
as `mailhub__<tool>`.

| Mode | Tools |
|---|---|
| read (default) | `mail_list_folders`, `mail_search`, `mail_read` (never marks read), `mail_list_identities`, `calendar_list`, `calendar_events` (recurrences expanded), `contacts_search` |
| `MAILHUB_MCP_WRITE=true` adds | `mail_set_flags`, `mail_move`, `mail_create_draft` (to Drafts, threaded when replying — **never sends**), `calendar_create_event`, `calendar_delete_event`, `contacts_create` |

CalDAV/CardDAV are plain `httpx` + `icalendar`/`vobject` (the `caldav` 3.x lib refuses `http://` by default and its
API is new). Identities come from Roundcube's SQLite, mounted read-only and opened with `immutable=1` (`mode=ro`
cannot lock on a read-only mount); Roundcube's auto-created `admin@mailhub-imap` identity is filtered out.
Deliberate errors raise `ToolError` so the reason reaches the assistant (other exceptions are hidden by MCPServer).

Checked on wisera with `dev/mcp-test.py` (via beaconify, and via Beacon's `/mcp/` with `call`), after installing
the official Beacon app through Maison: discovered within one cycle, all 7 read tools and all 6 write tools work.
Test leftovers: a contact "Olivia Organizer", a flagged invitation, a draft in Trash.

Not done: no remote/OAuth path of its own (remote assistants go through Beacon's), no `send` tool by design.

## Mail accounts in Settings (2026-11-04)

`mailhub_accounts` plugin: *Settings → Mail accounts* replaces hand-written getmail files and the
create-then-edit identity dance. getmail now runs as uid 33 (the webmail's www-data) so the webmail can
write the private accounts folder; the compose declares both getmail folders `33:33` with
`recursive: true` (Maison re-owns files from older installs). getmail needs a passwd entry for its uid
(`getpwuid` in `MDA_qmaillocal`'s defaults), hence the `getmail` user in the image.

Checked on wisera (`dev/accounts-test.py`, 18 checks): bad address and unreachable server refused with
nothing written; account added → private getmail file + identity with the provider's SMTP; a mail sent to
it at the fake provider reached the inbox within seconds; status shown; rename + delete-at-source; remove
(file gone, identity kept). `dev/send-as-test.py`: sending as that identity from the webmail reached the
provider with the global SMTP pointing at a dead host. Not checked: the page's look in a browser.

## IMAPS certificate from Maison (2026-11-04)

Mounting Caddy's storage into Dovecot broke on mestio (zone not delegated: no wildcard, and `<app>.<domain>`
resolves to Cloudflare) and needed Dovecot as root for the sslip.io cert (root 0600). Replaced by a Maison
feature (1.1.92): the app declares `certificates: [{route: mailhub, path: tls/, user: 1000, restart:
[mailhub-imap]}]`; the deployment states `cert-store` in the routing setting; Maison writes the certificate of
the route's first host that has a public cert and resolves to the box, sticky on `APP_TLS_HOST`, self-signed
when none, re-checked every 10 min with a Dovecot restart on change. The store compose has a `tls-fallback`
step (self-signed) for a Maison older than 1.1.92.

Checked on wisera: picked the wildcard name, sticky on the sslip.io name when that was in use, a planted stale
certificate replaced by the 10-minute check with Dovecot restarted, IMAPS verified externally, accounts test
passing with Dovecot as vmail again.

## Feedback round (2026-11-04, after the first real account on mestio)

- Saving an account showed nothing for the seconds the IMAP/SMTP check takes → *Checking the account with
  its provider…* (Roundcube's loading message) and the Save button disabled meanwhile.
- Default skin → Workbench (Elastic2022 also installed). Screenshots of both, mail + calendar + settings,
  render correctly with the Kolab plugins (`dev/skin-shots.js`, puppeteer on the app network).
- One inbox per address, Outlook-style → per-account *Folder*: delivered to `admin+<folder>`, Dovecot files it
  there (created + subscribed on first delivery); `check_all_folders` for unread counts. A `dovecot-reload`
  started step applies a changed `mailhub.conf` (bind-mounted, so an update does not recreate Dovecot).
  Already-fetched mail stays where it is. `accounts-test.py` now 23 checks, all passing on wisera.
