"""Send a message from the webmail as <identity email> and check it reached the fake provider.

The global SMTP on the test box points at a dead host, so a delivered message proves the
identity's own SMTP (set by Settings → Mail accounts) was used.
Run on mailhub-internal:  python send-as-test.py <mailbox password> <identity email>
"""
import http.cookiejar, imaplib, re, sys, time, urllib.parse, urllib.request

RC, PASSWORD, FROM = "http://mailhub-webmail/", sys.argv[1], sys.argv[2]
TO = "dave@other.test"
jar = http.cookiejar.CookieJar()
web = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))


def req(params, data=None, ajax_token=None):
    r = urllib.request.Request(RC + "?" + urllib.parse.urlencode(params),
                               data=urllib.parse.urlencode(data).encode() if data else None)
    if ajax_token:
        r.add_header("X-Roundcube-Request", ajax_token)
    with web.open(r) as resp:
        return resp.geturl(), resp.read().decode("utf-8", "replace")


tok = re.search(r'name="_token" value="([^"]+)"', req({"_task": "login"})[1]).group(1)
req({"_task": "login", "_action": "login"}, {"_token": tok, "_task": "login", "_action": "login",
    "_timezone": "UTC", "_user": "admin", "_pass": PASSWORD})
url, html = req({"_task": "mail", "_action": "compose"})
cid = urllib.parse.parse_qs(urllib.parse.urlparse(url).query)["_id"][0]
tok = re.search(r'"request_token":"([^"]+)"', html).group(1)
iid = re.search(r'<option value="(\d+)"[^>]*>[^<]*' + re.escape(FROM), html).group(1)
subject = f"send-as-test {int(time.time())}"
_, out = req({"_task": "mail", "_action": "send"}, {
    "_token": tok, "_task": "mail", "_action": "send", "_id": cid, "_from": iid, "_to": TO,
    "_subject": subject, "_message": "Sent through the identity's own SMTP.", "_is_html": 0}, ajax_token=tok)
err = re.search(r'display_message\("((?:[^"\\]|\\.)*)","error"', out)
print("webmail:", "error: " + err.group(1) if err else "sent")

time.sleep(3)
with imaplib.IMAP4("mailhub-fakeprovider", 3143) as imap:
    imap.login(TO, "x")
    imap.select("INBOX")
    found = imap.search(None, "SUBJECT", f'"{subject}"')[1][0].split()
    if found:
        hdr = imap.fetch(found[-1], "(BODY.PEEK[HEADER.FIELDS (FROM)])")[1][0][1].decode().strip()
        print("PASS delivered via the identity's SMTP —", hdr)
    else:
        print("FAIL not delivered")
        sys.exit(1)
