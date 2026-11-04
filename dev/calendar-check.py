"""Spike: log into Roundcube and list the calendars the Kolab CalDAV driver sees."""
import http.cookiejar, re, sys, urllib.parse, urllib.request

RC = "http://mailhub-webmail/"
jar = http.cookiejar.CookieJar()
web = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))


def get(params, data=None):
    body = urllib.parse.urlencode(data).encode() if data else None
    with web.open(RC + "?" + urllib.parse.urlencode(params), body) as r:
        return r.read().decode("utf-8", "replace")


tok = re.search(r'"request_token":"([^"]+)"', get({"_task": "login"})).group(1)
get({"_task": "login", "_action": "login"}, {"_token": tok, "_task": "login", "_action": "login",
    "_timezone": "UTC", "_user": "admin", "_pass": sys.argv[1]})
page = get({"_task": "calendar"})
cals = re.search(r'"calendars":(\{.*?\}\}),"', page)
print("calendars env:", cals.group(1)[:800] if cals else "(none in page)")
print("addressbooks:", re.findall(r'"name":"([^"]+)","[^}]*"carddav', get({"_task": "addressbook"}))[:5])
