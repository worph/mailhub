"""Drive Settings → Mail accounts (mailhub_accounts) over HTTP against the fake provider
(GreenMail, dev/fakeprovider.compose.yml), and check what mailhub-mirror does with it.

1. refused: invalid address, unreachable IMAP server; nothing written
2. add carol@gmail.test (Other provider = GreenMail, unencrypted) → account file, identity + SMTP
3. a message already at the provider is copied to Live/carol@gmail.test/INBOX; a new one arrives
   after a save on the page (which wakes the mirror); All inboxes shows it; the status says so
4. replay: flagging the local copy flags the message at the provider
5. remove: the local copy is deleted, the provider keeps every message

Run inside mailhub-mirror (it sees the account files at /accounts):
    docker exec mailhub-mirror python3 /tmp/accounts-test.py <mailbox password>
"""
import http.cookiejar, imaplib, json, os, re, smtplib, sys, time, urllib.parse, urllib.request
from email.message import EmailMessage

RC = "http://mailhub-webmail/"
USER, PASSWORD = "admin", sys.argv[1]
PROVIDER = "mailhub-fakeprovider"
EMAIL = "carol@gmail.test"
SLUG = "carol_at_gmail.test"
ACCFILE = f"/accounts/{SLUG}.json"
LIVE = f"Live/{EMAIL}/INBOX"

jar = http.cookiejar.CookieJar()
web = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
failures = []


def req(params, data=None):
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    with web.open(urllib.request.Request(RC + "?" + urllib.parse.urlencode(params), data=body)) as r:
        return r.read().decode("utf-8", "replace")


def token_of(html):
    m = re.search(r'"request_token":"([^"]+)"', html) or re.search(r'name="_token" value="([^"]+)"', html)
    return m.group(1) if m else None


def check(label, ok, detail=""):
    print(("PASS " if ok else "FAIL ") + label + (f"  [{detail}]" if detail and not ok else ""))
    if not ok:
        failures.append(label)


def page():
    return req({"_task": "settings", "_action": "edit-prefs", "_section": "mailhub_accounts", "_framed": 1})


def save(fields):
    html = page()
    data = {"_token": token_of(html), "_section": "mailhub_accounts", "_task": "settings", "_action": "save-prefs"}
    data.update(fields)
    out = req({"_task": "settings", "_action": "save-prefs", "_framed": 1}, data)
    m = re.search(r'display_message\("((?:[^"\\]|\\.)*)","(\w+)"', out)
    return (m.group(1).encode().decode("unicode_escape"), m.group(2)) if m else ("", "none")


def new_account(**over):
    f = {"_new[preset]": "other", "_new[email]": EMAIL, "_new[name]": "Carol (test)", "_new[pass]": "app-pass",
         "_new[imap_host]": PROVIDER, "_new[imap_port]": "3143", "_new[imap_security]": "none",
         "_new[smtp_host]": PROVIDER, "_new[smtp_port]": "3025", "_new[smtp_security]": "none"}
    f.update({f"_new[{k}]": v for k, v in over.items()})
    return f


print("== login")
tok = token_of(req({"_task": "login"}))
req({"_task": "login", "_action": "login"},
    {"_token": tok, "_task": "login", "_action": "login", "_timezone": "UTC", "_user": USER, "_pass": PASSWORD})
html = page()
check("settings page renders", "Add an account" in html and "mh_preset" in html, html[:300])
check("no Folder or Delete-from-provider option", "_folder" not in html and "_delete" not in html)


def provider():
    m = imaplib.IMAP4(PROVIDER, 3143)
    m.login(EMAIL, "app-pass")
    return m


def local():
    m = imaplib.IMAP4("mailhub-imap", 31143)
    m.login(USER, PASSWORD)
    return m


def send(subject):
    m = EmailMessage()
    m["From"], m["To"], m["Subject"] = "dave@other.test", EMAIL, subject
    m.set_content("Mirrored by an account added from Settings.")
    with smtplib.SMTP(PROVIDER, 3025) as s:
        s.send_message(m)


def find(box, subject, tries=30):
    for _ in range(tries):
        try:
            with local() as imap:
                if imap.select(f'"{box}"', readonly=True)[0] == "OK":
                    hits = imap.search(None, "SUBJECT", f'"{subject}"')[1][0].split()
                    if hits:
                        return hits
        except imaplib.IMAP4.error:
            pass
        time.sleep(5)
    return []


if SLUG in html:  # left over from an earlier run
    save({f"_acc[{SLUG}][name]": "x", f"_acc[{SLUG}][remove]": "1"})
    time.sleep(10)

print("== refused")
msg, kind = save(new_account(email="not-an-address"))
check("invalid address refused", kind == "error" and "valid email" in msg, f"{kind}: {msg}")
msg, kind = save(new_account(imap_host="nothing.invalid"))
check("unreachable IMAP refused", kind == "error" and "could not be reached" in msg, f"{kind}: {msg}")
check("nothing written after a refusal", not os.path.exists(ACCFILE))

print("== add")
first = f"before-add {int(time.time())}"
send(first)
time.sleep(2)
msg, kind = save(new_account())
check("account saved", kind == "confirmation", f"{kind}: {msg}")
acc = json.load(open(ACCFILE)) if os.path.exists(ACCFILE) else {}
check("account file written", acc.get("imap_host") == PROVIDER and acc.get("email") == EMAIL, str(acc)[:200])
check("account file is private", os.path.exists(ACCFILE) and oct(os.stat(ACCFILE).st_mode & 0o777) == "0o600")
idents = req({"_task": "settings", "_action": "identities"})
check("identity created", EMAIL in idents)
iid = re.search(r'id="rcmrow(\d+)"[^>]*>(?:(?!</tr>).)*' + re.escape(EMAIL), idents, re.S)
if iid:
    form = req({"_task": "settings", "_action": "edit-identity", "_iid": iid.group(1), "_framed": 1})
    check("identity sends through the provider's SMTP", "mailhub-fakeprovider:3025" in form)

print("== mirror")
check(f"message already at the provider copied to {LIVE}", bool(find(LIVE, first)))
with local() as imap:
    subs = b" ".join(imap.lsub('""', "*")[1] or [])
check("Live folder subscribed (shows in the webmail)", LIVE.encode() in subs, subs[:300])
later = f"after-add {int(time.time())}"
send(later)
msg, kind = save({f"_acc[{SLUG}][name]": "Carol Renamed"})  # a save wakes the mirror
check("edit saved", kind == "confirmation", f"{kind}: {msg}")
check("new message at the provider arrives after a save", bool(find(LIVE, later, tries=12)))
check("All inboxes shows it", bool(find("Virtual/All inboxes", later, tries=6)))
html = page()
status = re.findall(r"(?:Last sync|Syncing)[^<]{0,160}", html)
check("status shows the last sync, OK", bool(status) and "OK" in status[0] and "FAILED" not in status[0], status[:1])
check("identity renamed", "Carol Renamed" in req({"_task": "settings", "_action": "identities"}))

print("== replay")
with local() as imap:
    if imap.select(f'"{LIVE}"')[0] == "OK":
        uids = imap.uid("SEARCH", None, "SUBJECT", f'"{later}"')[1][0].split()
        if uids:
            imap.uid("STORE", uids[-1], "+FLAGS", r"(\Flagged)")
flagged = False
for _ in range(15):
    time.sleep(2)
    with provider() as p:
        p.select("INBOX", readonly=True)
        if p.search(None, "FLAGGED", "SUBJECT", f'"{later}"')[1][0].split():
            flagged = True
            break
check("flag set here is set at the provider", flagged)

print("== remove")
with provider() as p:
    before = int(p.select("INBOX", readonly=True)[1][0])
msg, kind = save({f"_acc[{SLUG}][name]": "Carol Renamed", f"_acc[{SLUG}][remove]": "1"})
check("remove saved", kind == "confirmation", f"{kind}: {msg}")
check("account file gone", not os.path.exists(ACCFILE))
gone = False
for _ in range(20):
    time.sleep(3)
    with local() as imap:
        if not [l for l in (imap.list('""', f"Live/{EMAIL}*")[1] or []) if l]:
            gone = True
            break
check("local copy deleted", gone)
with provider() as p:
    after = int(p.select("INBOX", readonly=True)[1][0])
check("the provider keeps every message", after == before, f"{before} -> {after}")
check("identity kept", "Carol Renamed" in req({"_task": "settings", "_action": "identities"}))

print()
print("ALL PASSED" if not failures else f"{len(failures)} FAILED: {failures}")
sys.exit(1 if failures else 0)
