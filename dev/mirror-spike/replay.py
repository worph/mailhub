"""SPIKE option A: replay IMAP actions recorded on Live/<address>/ onto the provider.

The local Maildir under ~/live/<account>/ is a pull-only mbsync mirror of the
provider. replay-hook.lua (Dovecot push_notification) appends one JSON line per
IMAP action to ~/live-queue/events.jsonl. This loop turns each action on a Live/
folder into the same IMAP command at the provider:

  FlagsSet / FlagsClear   -> UID STORE +/-FLAGS on that message
  MessageAppend / New     -> APPEND the message to that folder, then drop the
                             local copy (the next pull brings the provider's copy)
  MessageExpunge          -> UID STORE \\Deleted + UID EXPUNGE of that one UID
  MailboxCreate / Rename  -> CREATE / RENAME
  MailboxDelete           -> DELETE only when empty at the provider, else skipped

Nothing is ever inferred: no event, no change at the provider. A local message is
tied to its provider UID through mbsync's own pairing (",U=<n>" in the file name
and the folder's .mbsyncstate), so a message is never looked up by guesswork.
"""
import imaplib, json, os, re, ssl, subprocess, sys, time

HOME = "/srv/vmail/admin"
QUEUE = f"{HOME}/live-queue/events.jsonl"
OFFSET = f"{HOME}/live-queue/offset"
FAILED = f"{HOME}/live-queue/failed.jsonl"
PENDING = f"{HOME}/live-queue/pending.jsonl"  # waiting for mbsync's pairing
LOG = f"{HOME}/live-queue/replay.log"
ACCOUNTS = {  # local prefix -> provider
    "Live/contact@aptero.co/": {
        "name": "contact", "host": "mail.privateemail.com", "user": "contact@aptero.co",
        "passfile": "/mirror/contact.pass", "mirror": f"{HOME}/live/contact",
        "control": f"{HOME}/live-control/contact",
    },
}
SYSTEM_FLAGS = {"\\Seen", "\\Flagged", "\\Answered", "\\Draft", "\\Deleted"}
MAILDIR_FLAG = {"D": "\\Draft", "F": "\\Flagged", "R": "\\Answered", "S": "\\Seen", "T": "\\Deleted"}


def log(*a):
    line = time.strftime("%Y-%m-%dT%H:%M:%S ") + " ".join(str(x) for x in a)
    print(line, flush=True)
    with open(LOG, "a") as f:
        f.write(line + "\n")


class Remote:
    """One provider connection, opened on demand, closed after idling."""
    def __init__(self, acc):
        self.acc, self.m, self.last = acc, None, 0

    def get(self):
        if self.m is not None and time.time() - self.last > 120:
            self.close()
        if self.m is None:
            pw = open(self.acc["passfile"]).read().strip()
            self.m = imaplib.IMAP4_SSL(self.acc["host"], 993, ssl_context=ssl.create_default_context())
            self.m.login(self.acc["user"], pw)
        self.last = time.time()
        return self.m

    def close(self):
        try:
            self.m and self.m.logout()
        except Exception:
            pass
        self.m = None


def q(name):
    return '"' + name.replace("\\", "\\\\").replace('"', '\\"') + '"'


def local_file(acc, box, uid):
    """Dovecot UID -> Maildir file of the mirror (via Dovecot's uidlist)."""
    ul = os.path.join(acc["control"], box, "dovecot-uidlist")
    if not os.path.exists(ul):
        ul = os.path.join(acc["mirror"], box, "dovecot-uidlist")
    base = None
    with open(ul) as f:
        for line in f:
            p = line.rstrip("\n").split(" ", 1)
            if p[0] == str(uid) and len(p) > 1 and ":" in p[1]:
                base = p[1][p[1].index(":") + 1:]
                break
    if base is None:
        return None
    for sub in ("cur", "new"):
        d = os.path.join(acc["mirror"], box, sub)
        for fn in os.listdir(d):
            if fn == base or fn.startswith(base + ":"):
                return os.path.join(d, fn)
    return None


class Wait(Exception):
    """mbsync has not written this folder's pairing yet (first download running)."""


def far_of_near(acc, box, near):
    try:
        with open(os.path.join(acc["mirror"], box, ".mbsyncstate")) as f:
            for line in f:
                p = line.split()
                if len(p) >= 2 and p[0].isdigit() and p[1] == str(near):
                    return int(p[0])
    except FileNotFoundError:
        raise Wait(box)
    raise Wait(f"{box} near {near}")  # paired in mbsync's next state write


def near_uid(path):
    m = re.search(r",U=(\d+)", os.path.basename(path))
    return int(m.group(1)) if m else None


def far_uid(acc, box, path):
    """Mirror file -> provider UID through mbsync's pairing, or None (local-only file)."""
    n = near_uid(path)
    return None if n is None else far_of_near(acc, box, n)


# Dovecot UID -> provider UID for every Live/ message seen so far, so an expunge
# (whose file is already gone) can still be resolved.
SNAP = {}


def snapshot():
    for prefix, acc in ACCOUNTS.items():
        for root, dirs, files in os.walk(acc["control"]):
            if "dovecot-uidlist" not in files:
                continue
            box = os.path.relpath(root, acc["control"])
            try:
                with open(os.path.join(root, "dovecot-uidlist")) as f:
                    for line in f:
                        p = line.rstrip("\n").split(" ", 1)
                        if len(p) > 1 and p[0].isdigit() and ":" in p[1]:
                            m = re.search(r",U=(\d+)", p[1])
                            if m:
                                SNAP[(prefix, box, int(p[0]))] = int(m.group(1))  # near (mbsync) UID
            except FileNotFoundError:
                pass


def apply(ev, remotes):
    box_full = ev.get("mailbox", "")
    prefix = next((p for p in ACCOUNTS if box_full.startswith(p)), None)
    if prefix is None:
        return "skip (not a Live folder)"
    acc, box = ACCOUNTS[prefix], box_full[len(prefix):]
    kind, uid = ev.get("event"), int(ev.get("uid", 0) or 0)
    r = remotes[prefix]

    if kind in ("FlagsSet", "FlagsClear"):
        flags = [f for f in (ev.get("flags") or []) if f in SYSTEM_FLAGS]
        if not flags:
            return "skip (no system flag)"
        path = None
        try:
            path = local_file(acc, box, uid)
        except FileNotFoundError:
            pass
        if path:
            fu = far_uid(acc, box, path)
        else:
            n = SNAP.get((prefix, box, uid))
            fu = None if n is None else far_of_near(acc, box, n)
        if fu is None:
            return "skip (local-only message)"
        m = r.get()
        m.select(q(box))
        typ, d = m.uid("STORE", str(fu), "+FLAGS.SILENT" if kind == "FlagsSet" else "-FLAGS.SILENT", "(" + " ".join(flags) + ")")
        return f"{kind} {flags} on provider UID {fu}: {typ}"

    if kind in ("MessageAppend", "MessageNew"):
        path = local_file(acc, box, uid)
        if path is None:
            return "skip (file not found)"
        if near_uid(path) is not None:
            return "skip (already a mirrored message)"
        info = os.path.basename(path).split(":2,", 1)
        flags = [MAILDIR_FLAG[c] for c in (info[1] if len(info) > 1 else "") if c in MAILDIR_FLAG]
        data = open(path, "rb").read()
        m = r.get()
        typ, d = m.append(q(box), "(" + " ".join(flags) + ")", imaplib.Time2Internaldate(os.path.getmtime(path)), data)
        if typ != "OK":
            raise RuntimeError(f"APPEND {typ} {d}")
        os.remove(path)  # the next pull brings the provider's copy, paired by mbsync
        subprocess.run(["mbsync", "-q", "-c", "/mirror/mbsyncrc", f"{acc['name']}:{box}"], check=False)
        return f"APPEND to {box} ({len(data)} bytes): {typ} {d}"

    if kind == "MessageExpunge":
        n = SNAP.get((prefix, box, uid))
        if n is None:
            return "skip (local-only or unknown message)"
        fu = far_of_near(acc, box, n)
        m = r.get()
        m.select(q(box))
        m.uid("STORE", str(fu), "+FLAGS.SILENT", "(\\Deleted)")
        typ, d = m.uid("EXPUNGE", str(fu))  # UIDPLUS: only this message
        SNAP.pop((prefix, box, uid), None)
        return f"EXPUNGE provider UID {fu} in {box}: {typ}"

    if kind == "MailboxCreate":
        typ, d = r.get().create(q(box))
        return f"CREATE {box}: {typ} {d}"
    if kind == "MailboxRename":
        old = ev.get("mailbox_old", "")
        if not old.startswith(prefix):
            return "skip (rename from outside Live)"
        typ, d = r.get().rename(q(old[len(prefix):]), q(box))
        return f"RENAME {old[len(prefix):]} -> {box}: {typ} {d}"
    if kind == "MailboxDelete":
        m = r.get()
        typ, d = m.status(q(box), "(MESSAGES)")
        if typ == "OK" and b"MESSAGES 0" in d[0]:
            typ, d = m.delete(q(box))
            return f"DELETE {box} (was empty): {typ}"
        return f"skip DELETE {box}: not empty at the provider ({d})"

    return f"skip ({kind})"


def handle(line, remotes, pending):
    ev = json.loads(line)
    box = ev.get("mailbox", "")
    if any(json.loads(p).get("mailbox") == box for p in pending):
        pending.append(line)  # keep this folder's actions in order
        return
    try:
        res = apply(ev, remotes)
        if not res.startswith("skip (not a Live"):
            log(ev.get("event"), box, ev.get("uid"), "->", res)
    except Wait as w:
        log(ev.get("event"), box, ev.get("uid"), "-> waiting for mbsync pairing:", w)
        pending.append(line)
    except Exception as e:
        for r in remotes.values():
            r.close()
        log("FAILED", line.strip(), "->", repr(e))
        with open(FAILED, "a") as ff:
            ff.write(line)


def retry_pending(remotes, pending):
    kept, blocked = [], set()
    for line in pending:
        ev = json.loads(line)
        box = ev.get("mailbox", "")
        if box in blocked:
            kept.append(line)
            continue
        try:
            res = apply(ev, remotes)
            log("(pending)", ev.get("event"), box, ev.get("uid"), "->", res)
        except Wait:
            kept.append(line)
            blocked.add(box)
        except Exception as e:
            log("FAILED (pending)", line.strip(), "->", repr(e))
            with open(FAILED, "a") as ff:
                ff.write(line)
    return kept


def main():
    remotes = {p: Remote(a) for p, a in ACCOUNTS.items()}
    last_snap, last_retry = 0, 0
    pending = open(PENDING).read().splitlines(True) if os.path.exists(PENDING) else []
    while True:
        if time.time() - last_snap > 5:
            snapshot()
            last_snap = time.time()
        try:
            off = int(open(OFFSET).read())
        except (FileNotFoundError, ValueError):
            off = os.path.getsize(QUEUE) if os.path.exists(QUEUE) else 0  # start from now
            open(OFFSET, "w").write(str(off))
        if os.path.exists(QUEUE) and os.path.getsize(QUEUE) > off:
            with open(QUEUE) as f:
                f.seek(off)
                for line in f:
                    if not line.endswith("\n"):
                        break  # half-written line: next round
                    off += len(line.encode())
                    handle(line, remotes, pending)
                    with open(PENDING, "w") as pf:
                        pf.writelines(pending)
                    open(OFFSET, "w").write(str(off))
        if pending and time.time() - last_retry > 30:
            snapshot()
            pending = retry_pending(remotes, pending)
            with open(PENDING, "w") as pf:
                pf.writelines(pending)
            last_retry = time.time()
        for r in remotes.values():
            if r.m is not None and time.time() - r.last > 120:
                r.close()
        time.sleep(2)


if __name__ == "__main__":
    main()
