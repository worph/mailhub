"""Spike: drive Roundcube over HTTP (inside the app network) through the invitation flow.

1. log in, 2. create the alice@gmail.test identity with its own SMTP (identity_smtp),
3. open the invitation and check the Accept/Maybe/Decline UI is rendered,
4. accept it the way the button does, 5. report what Baïkal and the organizer got.

Run inside a container on mailhub-internal:  python itip-test.py <password>
"""
import http.cookiejar, imaplib, json, re, sys, time, urllib.parse, urllib.request, base64

RC = "http://mailhub-webmail/"
USER, PASSWORD = "admin", sys.argv[1]
# The calendar now lives in the Baïkal app: <dav calendar url> <dav user> <dav password>
DAV, DAV_USER, DAV_PASS = sys.argv[2:5]
IDENTITY = {"name": "Alice (Gmail 1)", "email": "alice@gmail.test", "smtp": "mailhub-fakeprovider:3025"}

jar = http.cookiejar.CookieJar()
web = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))


def req(params, data=None, token=None, ajax=False):
    url = RC + "?" + urllib.parse.urlencode(params)
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    r = urllib.request.Request(url, data=body)
    if token and ajax:
        r.add_header("X-Roundcube-Request", token)
    if ajax:
        r.add_header("X-Requested-With", "XMLHttpRequest")
    with web.open(r) as resp:
        return resp.read().decode("utf-8", "replace")


def token_of(html):
    m = re.search(r'"request_token":"([^"]+)"', html) or re.search(r'name="_token" value="([^"]+)"', html)
    return m.group(1) if m else None


def step(msg):
    print(f"\n== {msg}")


step("login")
tok = token_of(req({"_task": "login"}))
req({"_task": "login", "_action": "login"},
    {"_token": tok, "_task": "login", "_action": "login", "_timezone": "UTC", "_user": USER, "_pass": PASSWORD})
mail = req({"_task": "mail", "_mbox": "INBOX"})
tok = token_of(mail)
print("logged in:", bool(tok) and "rcmail" in mail)

step("identity with its own SMTP")
fields = {
    "_task": "settings", "_action": "save-identity", "_framed": 1,
    "_name": IDENTITY["name"], "_email": IDENTITY["email"], "_organization": "", "_reply-to": "", "_bcc": "",
    "_signature": "", "_html_signature": 0,
    "_smtp_host": IDENTITY["smtp"], "_smtp_user": IDENTITY["email"], "_smtp_pass": "x", "_smtp_pass2": "x",
}
idents = req({"_task": "settings", "_action": "identities"})
if IDENTITY["email"] not in idents:
    form = req({"_task": "settings", "_action": "add-identity", "_framed": 1})
    req({"_task": "settings", "_action": "save-identity"}, {"_token": token_of(form) or tok, **fields})
    idents = req({"_task": "settings", "_action": "identities"})
# identity_smtp stores SMTP settings only when an existing identity is saved
# ("Please save first to edit the SMTP settings"), so save it a second time.
iid = re.search(r'rcmrow(\d+)"[^>]*>(?:(?!rcmrow).)*?' + re.escape(IDENTITY["email"]), idents, re.S)
iid = iid.group(1) if iid else None
form = req({"_task": "settings", "_action": "edit-identity", "_iid": iid, "_framed": 1})
req({"_task": "settings", "_action": "save-identity"}, {"_token": token_of(form) or tok, "_iid": iid, **fields})
form = req({"_task": "settings", "_action": "edit-identity", "_iid": iid, "_framed": 1})
print("identity", iid, "smtp host stored:", IDENTITY["smtp"] in form)

step("open the invitation")
lst = req({"_task": "mail", "_action": "list", "_mbox": "INBOX", "_remote": 1}, token=tok, ajax=True)
uids = re.findall(r'add_message_row\((\d+)', lst)
inv_uid = None
for uid in sorted(uids, key=int, reverse=True):
    page = req({"_task": "mail", "_action": "show", "_uid": uid, "_mbox": "INBOX"})
    if "Invitation" in page and ("rsvp" in page or "itip" in page):
        inv_uid, show = uid, page
        break
print("invitation uid:", inv_uid)
if not inv_uid:
    sys.exit("invitation not found / no iTIP UI rendered")
buttons = sorted(set(re.findall(r'class="button (accept|tentative|decline|delegate)', show)))
print("RSVP buttons rendered:", buttons or "(pattern not found — dumping hints)")
if not buttons:
    print(re.findall(r'<[^>]*(?:rsvp|itip)[^>]*>', show)[:10])
mime = re.search(r"accept_itip_reply\(\s*'([^']+)'|rsvp_status\([^)]*?'(\d[\d.]*)'", show)
part = re.search(r'"(\d+(?:\.\d+)*)"[^)]*?"accepted"|mime_id\W+(\d+(?:\.\d+)*)', show)
targets = re.findall(r'<select[^>]*id="itip-saveto"[^>]*>(.*?)</select>', show, re.S)
print("calendar choices:", re.findall(r'value="([^"]+)"', targets[0]) if targets else None)
js_calls = re.findall(r"(rcube_libcalendaring\.[a-z_]+\([^)]{0,200}\))", show)
print("itip js calls:", js_calls[:6])
task = "mail" if "task:'mail'" in show.replace('"', "'") else None

# The Accept button calls rcube_libcalendaring.add_from_itip_mail(mime_id, task, 'accepted', dom_id)
call = next((c for c in js_calls if "add_from_itip_mail" in c), None)
args = re.findall(r"'([^']*)'", call) if call else []
mime_id, task = (args[0], args[1]) if len(args) >= 2 else ("2", "calendar")
# The UI fetches the picker from calendar/itip-status and preselects the first
# writable calendar; take the same one from the calendar view's env.
cal_env = req({"_task": "calendar"})
folder = next((c for c, ed in re.findall(r'"id":"([^"]+)","name":"[^"]*"[^}]*?"editable":(true|false)', cal_env) if ed == "true"), "")

step(f"accept via {task}/mailimportitip (part {mime_id}, calendar {folder!r})")
res = req({"_task": task, "_action": "mailimportitip"}, {
    "_uid": inv_uid, "_mbox": "INBOX", "_part": mime_id, "_folder": folder, "_status": "accepted",
    "_del": 0, "_noreply": 0, "_comment": "Accepted from the MailHub spike", "_remote": 1, "_token": tok,
}, token=tok, ajax=True)
print(res[:600])

step("Baïkal calendar content")
r = urllib.request.Request(DAV, method="PROPFIND", headers={"Depth": "1",
    "Authorization": "Basic " + base64.b64encode(f"{DAV_USER}:{DAV_PASS}".encode()).decode()})
with urllib.request.urlopen(r) as resp:
    hrefs = re.findall(r"<d:href>([^<]+\.ics)</d:href>", resp.read().decode())
print("events:", hrefs)

step("organizer mailbox (olivia@other.test at the fake provider)")
time.sleep(2)
m = imaplib.IMAP4("mailhub-fakeprovider", 3143)
m.login("olivia@other.test", "x")
n = int(m.select("INBOX")[1][0])
print("messages:", n)
for i in range(1, n + 1):
    raw = m.fetch(str(i), "(RFC822)")[1][0][1].decode("utf-8", "replace")
    hdr = raw.split("\r\n\r\n", 1)[0]
    print("---\n" + "\n".join(l for l in hdr.splitlines() if l.split(":")[0] in ("From", "To", "Subject", "Received")))
    print("METHOD:REPLY" in raw and "PARTSTAT=ACCEPTED" in raw and "(iTIP REPLY, PARTSTAT=ACCEPTED)" or "(no iTIP reply payload)")
