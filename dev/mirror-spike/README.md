# Option A spike: local mirror + action replay (2026-11-06, wisera)

Roundcube shows each provider account as `Live/<address>/…` and Cypht-style combined views as
`Virtual/…`, all served by MailHub's local Dovecot. The provider stays the source of truth:

- **Pull** (`mbsyncrc`, container `mailhub-mirror-spike`, every 5 min): mbsync `Sync Pull` into a Maildir
  under `~/live/<account>/`. It never writes to the provider. `Remove None`, state inside each folder.
- **Replay** (`replay.py`, container `mailhub-replay-spike`): Dovecot's Lua push notification
  (`replay-hook.lua`) records every IMAP action into `~/live-queue/events.jsonl`; actions on `Live/`
  folders become the same command at the provider (flags → UID STORE, message added → APPEND, message
  removed → UID EXPUNGE of that UID only, folder create/rename; folder delete only when empty). The
  provider UID comes from mbsync's pairing (`,U=` in the file name + `.mbsyncstate`). Actions on a
  message mbsync has not paired yet wait in `pending.jsonl`, in order per folder.
- **Dovecot** (`live-spike.conf`, included from `dovecot/mailhub.conf`): the Live namespace (maildir,
  fs layout, `mail_inbox_path` = the mirror's INBOX folder, index/control outside the mirror), the
  virtual namespace (`mailbox_list_layout = fs`), plugins `mail_lua` + `push_notification(_lua)` + `virtual`.
- `mailhub-livesub-spike` subscribes new Live folders (Roundcube lists subscribed folders only).

Tested on the throwaway provider folders `MailHub-test` and `MailHub-test/Moved`: Seen / Flagged,
move between Live folders (APPEND + single-UID expunge), permanent delete, an action queued while the
pairing was missing then applied. Wipe tests: deleting the mail files (state kept) or the whole local
folder produced no event and left the provider untouched; the whole-folder case re-downloaded on the
next pull, the files-only case stays empty until its `.mbsyncstate` is removed.

Measured (local): folder open 0.02–0.2 s, message open instant, full-text search 0.01 s once indexed,
Roundcube folder list ~2 s per page load; combined views open in 0–1 s with no date limit.

Gotchas: `push_notification_lua` needs `mail_lua` loaded or every IMAP login fails; the virtual
namespace needs `mailbox_list_layout = fs`; virtual wildcards do not reach other namespaces, so the
combined views list Live folders by name; first download of contact@ (~20k messages, 1.9 GB) ~1 h.
Not done: a second account, Gmail labels, moving between accounts, a store-app form of any of it.
