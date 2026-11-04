"""Spike: call MailHub's MCP tools over streamable HTTP (as Beacon would, via beaconify).

python mcp-test.py [url]        default http://mailhub-beacon:9099/mcp
"""
import datetime as dt, json, sys, urllib.request

URL = sys.argv[1] if len(sys.argv) > 1 else "http://mailhub-beacon:9099/mcp"
_id = 0
_session = None  # stateful servers (Beacon) hand one out on initialize


def rpc(method, params=None):
    global _id
    _id += 1
    body = json.dumps({"jsonrpc": "2.0", "id": _id, "method": method, "params": params or {}}).encode()
    global _session
    headers = {"Content-Type": "application/json", "Accept": "application/json, text/event-stream",
               "MCP-Protocol-Version": "2025-06-18"}
    if _session:
        headers["Mcp-Session-Id"] = _session
    req = urllib.request.Request(URL, body, headers)
    with urllib.request.urlopen(req, timeout=60) as r:
        _session = r.headers.get("Mcp-Session-Id") or _session
        raw = r.read().decode()
    if method == "initialize" and _session:
        note = json.dumps({"jsonrpc": "2.0", "method": "notifications/initialized"}).encode()
        urllib.request.urlopen(urllib.request.Request(URL, note, {**headers, "Mcp-Session-Id": _session}), timeout=30).read()
    if raw.startswith("event:") or raw.startswith("data:"):
        raw = next(l[5:] for l in raw.splitlines() if l.startswith("data:"))
    msg = json.loads(raw)
    if "error" in msg:
        raise RuntimeError(msg["error"])
    return msg["result"]


def call(tool, **args):
    res = rpc("tools/call", {"name": tool, "arguments": args})
    if res.get("isError"):
        return "ERROR: " + " ".join(c.get("text", "") for c in res["content"])
    if res.get("structuredContent") is not None:
        sc = res["structuredContent"]
        return sc.get("result", sc) if isinstance(sc, dict) else sc
    return " ".join(c.get("text", "") for c in res["content"])


def show(label, value, width=400):
    print(f"\n== {label}\n{json.dumps(value, ensure_ascii=False, default=str)[:width]}")


init = rpc("initialize", {"protocolVersion": "2025-06-18", "capabilities": {},
                          "clientInfo": {"name": "mailhub-spike", "version": "0"}})
print("server:", init["serverInfo"], "\ninstructions:", init.get("instructions", "")[:200])
tools = rpc("tools/list")["tools"]
print("tools:", [t["name"] for t in tools])

show("mail_list_folders", call("mail_list_folders"))
found = call("mail_search", limit=3)
show("mail_search (latest 3)", found, 700)
show("mail_search text=meeting", call("mail_search", text="meeting", limit=2), 300)
if isinstance(found, list) and found:
    show(f"mail_read uid={found[0]['uid']}", call("mail_read", uid=found[0]["uid"], max_chars=300), 900)
show("mail_list_identities", call("mail_list_identities"))
show("calendar_list", call("calendar_list"))
today = dt.date.today()
show("calendar_events next 7 days", call("calendar_events", start=today.isoformat(),
                                          end=(today + dt.timedelta(days=7)).isoformat()), 600)
show("contacts_search", call("contacts_search"))

# ---- write tools (only listed when MAILHUB_MCP_WRITE=true) ----
names = {t["name"] for t in tools}
if "mail_create_draft" in names:
    ident = call("mail_list_identities")
    alice = next(i["email"] for i in ident if i["email"].endswith("gmail.test"))
    inv = call("mail_search", text="meeting", limit=1)[0]
    show("mail_create_draft (reply)", call("mail_create_draft", from_email=alice, reply_to_uid=inv["uid"],
                                             body="Thanks, see you there."))
    drafts = call("mail_search", folder="Drafts", limit=1)
    show("Drafts now", drafts, 400)
    show("draft content", call("mail_read", uid=drafts[0]["uid"], folder="Drafts", max_chars=200), 700)
    show("mail_set_flags flagged", call("mail_set_flags", uids=[inv["uid"]], flagged=True))
    show("flagged search", call("mail_search", flagged_only=True, limit=3), 300)
    show("mail_move draft -> Trash", call("mail_move", uids=[drafts[0]["uid"]], folder="Drafts", to_folder="Trash"))
    show("bad identity", call("mail_create_draft", from_email="nobody@nowhere.test", subject="x"))
    tomorrow = (dt.date.today() + dt.timedelta(days=1)).isoformat()
    ev = call("calendar_create_event", summary="MCP test event", start=f"{tomorrow}T10:00:00",
              end=f"{tomorrow}T10:30:00", location="Wisera")
    show("calendar_create_event", ev)
    show("events tomorrow", [e["summary"] for e in call("calendar_events", start=tomorrow,
         end=(dt.date.today() + dt.timedelta(days=2)).isoformat())])
    show("calendar_delete_event", call("calendar_delete_event", uid=ev["uid"]))
    show("contacts_create", call("contacts_create", name="Olivia Organizer", email_address="olivia@other.test",
                                 organization="Other Test"))
    show("contacts_search olivia", call("contacts_search", query="olivia"))
