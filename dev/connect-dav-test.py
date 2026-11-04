"""Spike: connect the Baïkal app from Roundcube's Settings → Calendar & contacts.

python connect-dav-test.py <mailbox password> <dav url> <dav user> <dav password>
"""
import http.cookiejar, re, sys, urllib.error, urllib.parse, urllib.request

RC = "http://mailhub-webmail/"
PASSWORD, DAV_URL, DAV_USER, DAV_PASS = sys.argv[1:5]
jar = http.cookiejar.CookieJar()
web = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))


def req(params, data=None):
    body = urllib.parse.urlencode(data).encode() if data else None
    with web.open(RC + "?" + urllib.parse.urlencode(params), body) as r:
        return r.read().decode("utf-8", "replace")


def token(html):
    return re.search(r'"request_token":"([^"]+)"', html).group(1)


def calendars():
    try:
        page = req({"_task": "calendar"})
    except urllib.error.HTTPError as e:
        return f"(no calendar: HTTP {e.code})"
    return re.findall(r'"id":"[^"]+","name":"([^"]+)","listname"', page)


def save(url, user, pw):
    form = req({"_task": "settings", "_action": "edit-prefs", "_section": "mailhub_dav", "_framed": 1})
    out = req({"_task": "settings", "_action": "save-prefs"}, {
        "_token": token(form), "_section": "mailhub_dav", "_framed": 1,
        "_dav_url": url, "_dav_user": user, "_dav_pass": pw})
    msgs = re.findall(r'display_message\("([^"]*)"', out)
    return msgs or ["(no message)"]


tok = token(req({"_task": "login"}))
req({"_task": "login", "_action": "login"}, {"_token": tok, "_task": "login", "_action": "login",
    "_timezone": "UTC", "_user": "admin", "_pass": PASSWORD})

settings = req({"_task": "settings"})
print("1. Settings has 'Calendar & contacts':", "mailhub_dav" in settings)
form = req({"_task": "settings", "_action": "edit-prefs", "_section": "mailhub_dav", "_framed": 1})
print("   status shown:", re.findall(r"(Not connected[^<]*|Connected[^<]*)", form)[:1])
print("2. calendars before connecting:", calendars())
print("3. save with a wrong password ->", save(DAV_URL, DAV_USER, "wrong-password"))
print("   save with a non-DAV URL     ->", save(DAV_URL.rsplit("/dav.php", 1)[0] + "/", DAV_USER, DAV_PASS))
print("4. save with the right login  ->", save(DAV_URL, DAV_USER, DAV_PASS))
print("5. calendars after connecting:", calendars())
abook = req({"_task": "addressbook"})
print("   address books:", sorted(set(re.findall(r'"name":"(Contacts|Personal Addresses)"', abook))))
