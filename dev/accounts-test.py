"""Drive Settings → Mail accounts (mailhub_accounts) over HTTP, against the fake provider.

1. refused: invalid address, unreachable IMAP server
2. add carol@gmail.test (Other provider = GreenMail, unencrypted) → getmail file, identity + SMTP
3. a mail sent to carol at the provider reaches the MailHub inbox within seconds
4. the page shows the fetch status; edit (name; a posted delete-at-source is ignored, IMAP only); remove

Run inside a container on mailhub-internal that sees the accounts folder at /config,
e.g. mailhub-fetch:  python accounts-test.py <mailbox password>
"""
import http.cookiejar, imaplib, os, re, smtplib, sys, time, urllib.parse, urllib.request
from email.message import EmailMessage

RC = "http://mailhub-webmail/"
USER, PASSWORD = "admin", sys.argv[1]
PROVIDER = "mailhub-fakeprovider"
EMAIL = "carol@gmail.test"
SLUG = "carol_at_gmail.test"
RCFILE = f"/config/{SLUG}.getmailrc"

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
check("hand-written accounts listed read-only", "alice_at_gmail.test" in html or "gmail1" in html)

print("== refused")
msg, kind = save(new_account(email="not-an-address"))
check("invalid address refused", kind == "error" and "valid email" in msg, f"{kind}: {msg}")
msg, kind = save(new_account(imap_host="nothing.invalid"))
check("unreachable IMAP refused", kind == "error" and "could not be reached" in msg, f"{kind}: {msg}")
check("nothing written after a refusal", not os.path.exists(RCFILE))

print("== add")
msg, kind = save(new_account())
check("account saved", kind == "confirmation", f"{kind}: {msg}")
rc = open(RCFILE).read() if os.path.exists(RCFILE) else ""
check("getmail file written", "server = mailhub-fakeprovider" in rc and "delete = false" in rc, rc)
check("new account gets its own folder, named after the address", f'override = "admin+{EMAIL}"@mailhub' in rc, rc)
check("getmail file is private", os.path.exists(RCFILE) and oct(os.stat(RCFILE).st_mode & 0o777) == "0o600")
idents = req({"_task": "settings", "_action": "identities"})
check("identity created", EMAIL in idents)
iid = re.search(r'id="rcmrow(\d+)"[^>]*>(?:(?!</tr>).)*' + re.escape(EMAIL), idents, re.S)
if iid:
    form = req({"_task": "settings", "_action": "edit-identity", "_iid": iid.group(1), "_framed": 1})
    check("identity sends through the provider's SMTP", "mailhub-fakeprovider:3025" in form)

print("== fetch")
m = EmailMessage()
m["From"], m["To"], m["Subject"] = "dave@other.test", EMAIL, f"accounts-test {int(time.time())}"
m.set_content("Fetched by an account added from Settings.")
with smtplib.SMTP(PROVIDER, 3025) as s:
    s.send_message(m)
found = False
for _ in range(12):  # the fetcher wakes within ~5 s of the new file
    time.sleep(5)
    with imaplib.IMAP4("mailhub-imap", 31143) as imap:
        imap.login(USER, PASSWORD)
        imap.select(f'"{EMAIL}"', readonly=True)
        typ, data = imap.search(None, "SUBJECT", f'"{m["Subject"]}"')
        if data[0].split():
            found = True
            break
check(f"mail filed in the {EMAIL} folder", found)
html = page()
check("status shows the last fetch", "Last fetch" in html and "OK" in html)

print("== edit")
msg, kind = save({f"_acc[{SLUG}][name]": "Carol Renamed", f"_acc[{SLUG}][delete]": "1"})
check("edit saved", kind == "confirmation", f"{kind}: {msg}")
check("delete at source ignored (IMAP only, never deletes)", "delete = false" in open(RCFILE).read()
      and "delete = true" not in open(RCFILE).read())
check("no delete option on the page", "_delete" not in page())
check("identity renamed", "Carol Renamed" in req({"_task": "settings", "_action": "identities"}))

print("== folder is no longer a setting")
check("no Folder field on the page", "_folder" not in page())
msg, kind = save({f"_acc[{SLUG}][name]": "Carol Renamed", f"_acc[{SLUG}][folder]": "Contact"})
check("a posted folder is ignored", kind == "confirmation" and f'override = "admin+{EMAIL}"@mailhub' in open(RCFILE).read())

print("== remove")
msg, kind = save({f"_acc[{SLUG}][name]": "Carol Renamed", f"_acc[{SLUG}][remove]": "1"})
check("remove saved", kind == "confirmation", f"{kind}: {msg}")
check("getmail file gone", not os.path.exists(RCFILE))
check("identity kept", EMAIL in req({"_task": "settings", "_action": "identities"}))

print("\n" + ("ALL PASSED" if not failures else f"{len(failures)} FAILED: {failures}"))
sys.exit(1 if failures else 0)
