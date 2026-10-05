#!/usr/bin/env python3
"""Builds The Devil's Rage Mumble channel tree, channel notes and permissions.

Connects to the local Mumble server as SuperUser (password from ~/mumble/.env)
and makes the server match the layout below. Safe to run again: existing
channels are kept and updated, missing ones are created, extra channels that
admins added by hand are left alone (and listed), and the members of the
Visitor / Member / FC / Admin groups are preserved.

    python3 build-channels.py            build / update
    python3 build-channels.py --show     print the planned tree, change nothing

No packages needed: plain Python 3 standard library.
"""
import argparse
import os
import socket
import ssl
import struct
import sys
import time

# --------------------------------------------------------------------------
# Mumble permission bits (src/ACL.h)
WRITE, TRAVERSE, ENTER, SPEAK = 0x1, 0x2, 0x4, 0x8
MUTEDEAFEN, MOVE, WHISPER, TEXT, LISTEN = 0x10, 0x20, 0x100, 0x200, 0x800
KICK, BAN, REGISTER, SELFREGISTER = 0x10000, 0x20000, 0x40000, 0x80000

ROOM = ENTER | SPEAK | LISTEN | TEXT          # "may use this room"
VISITOR_UP = ["visitor", "member", "fc", "admin"]
MEMBER_UP = ["member", "fc", "admin"]
OUR_GROUPS = ["admin", "fc", "member", "visitor"]


def rule(group, grant=0, deny=0, here=True, subs=True):
    return {"group": group, "grant": grant, "deny": deny, "here": here, "subs": subs}


def members_only():
    """Rules for a room (and everything under it) only Member+ may use."""
    return [rule("all", deny=ROOM)] + [rule(g, grant=ROOM) for g in MEMBER_UP]


# --------------------------------------------------------------------------
# Channel notes. Mumble shows these when a channel is clicked (HTML allowed).
EAR = ("<i>Ear = right-click a channel → Listen to channel. Mumble remembers your ears; "
       "right-click an ear to set its volume.</i>")


def note(*lines):
    return "<br>".join(lines)


ROOT_NOTE = note(
    "<b>The Devil's Rage</b>",
    "New here? Go to <b>Drag Me</b> and someone will pull you in.",
    "This room is listen-only.")

OPS_NOTE = note(
    "<b>Operations: one key for everyone</b>",
    "Your normal push-to-talk key talks into the room you sit in. Nothing else to set up.",
    "<b>Ears point up:</b> add an ear on every room above you in your branch.",
    "<b>Commanders</b> also add ears on the rooms right below them.",
    "Small op? Everyone just sits in <b>Fleet 1</b>.",
    EAR)


def fleet_rooms(fleet):
    """(name, note, children) for one fleet."""
    F = fleet

    def pilots(role, cmd, parent):
        return (f"{role} pilots", note(
            f"<b>{role} pilots sit here.</b>",
            f"Ears: <b>{F}</b>, <b>{parent}</b>, <b>{cmd}</b>.",
            f"You only hear {role} calls, your commanders and the FC.",
            EAR), [])

    def command(role, parent):
        cmd = f"{role} command"
        return (cmd, note(
            f"<b>{role} commander sits here.</b> Your {role} pilots and the "
            f"{parent.lower()} commander hear you.",
            f"Ears: <b>{F}</b>, <b>{parent}</b>, <b>{role} pilots</b>.",
            EAR), [pilots(role, cmd, parent)])

    return (F, note(
        f"<b>Head FC and fleet command sit here.</b>",
        f"Everyone in {F}: add an ear on <b>{F}</b>.",
        "FC: add ears on <b>Subcaps</b>, <b>Capitals</b>, <b>Scouts and scanners</b> "
        "and <b>Multi-role pilots</b>.",
        "Priority speaker: right-click yourself → Priority Speaker.",
        EAR), [
        ("Multi-role pilots", note(
            "<b>Flying more than one role?</b> Sit here.",
            f"Ears: <b>{F}</b> plus the command room of each role you fly "
            "(for example <b>DPS command</b> + <b>Logi command</b> + <b>Dread command</b>).",
            "Turn each ear up or down to taste.",
            EAR), []),
        ("Scouts and scanners", note(
            "<b>Scouts and scanners sit here.</b>",
            f"Ears: <b>{F}</b>.",
            "The FC listens here. Commanders: add an ear if you want scout intel.",
            EAR), []),
        ("Subcaps", note(
            "<b>Subcap commander sits here.</b>",
            f"Ears: <b>{F}</b>, <b>DPS command</b>, <b>Logi command</b>.",
            EAR), [command("DPS", "Subcaps"), command("Logi", "Subcaps")]),
        ("Capitals", note(
            "<b>Capital commander sits here.</b>",
            f"Ears: <b>{F}</b>, <b>Dread command</b>, <b>FAX command</b>.",
            EAR), [command("Dread", "Capitals"), command("FAX", "Capitals")]),
    ])


# (name, note, children, acl rules or None)
TREE = [
    ("Drag Me", note("<b>Wait here.</b> Someone will drag you in.",
                     "Visitors and members: drag people from here into Lobby."), [],
     [rule(g, grant=MOVE, subs=False) for g in ("visitor", "member")]),
    ("Lobby", note("<b>Visitors and members.</b>",
                   "Visitors: you can drag people in from Drag Me."), [],
     [rule("all", deny=ROOM)] + [rule(g, grant=ROOM) for g in VISITOR_UP]
     + [rule(g, grant=MOVE, subs=False) for g in ("visitor", "member")]),
    ("Chill 1", note("<b>Members.</b> Hang out."), [], members_only()),
    ("Chill 2", note("<b>Members.</b> Hang out."), [], members_only()),
    ("Operations", OPS_NOTE, [fleet_rooms("Fleet 1"), fleet_rooms("Fleet 2")], members_only()),
    ("AFK", note("<b>Silent room.</b> Anyone can park here; nobody talks."), [],
     [rule("all", deny=SPEAK, subs=False), rule("admin", grant=SPEAK, subs=False)]),
]

ROOT_RULES = [
    rule("all", grant=SELFREGISTER, subs=False),          # anyone may register
    rule("all", deny=WHISPER),                            # no whisper/shout keys...
    rule("all", deny=SPEAK, subs=False),                  # top room is listen-only
    rule("fc", grant=MOVE | MUTEDEAFEN | WHISPER),        # ...except FCs/officers
    rule("admin", grant=WRITE | SPEAK | WHISPER | KICK | BAN | REGISTER),
]


# --------------------------------------------------------------------------
# Minimal protobuf + Mumble framing (Mumble.proto field numbers)
T_VERSION, T_AUTH, T_PING, T_REJECT, T_SYNC = 0, 2, 3, 4, 5
T_CHANREMOVE, T_CHANSTATE, T_DENIED, T_ACL, T_QUERYUSERS = 6, 7, 12, 13, 14


def _varint(n):
    out = bytearray()
    while True:
        b = n & 0x7F
        n >>= 7
        if n:
            out.append(b | 0x80)
        else:
            out.append(b)
            return bytes(out)


def f_int(num, v):
    return _varint(num << 3) + _varint(v & 0xFFFFFFFFFFFFFFFF)


def f_bytes(num, b):
    if isinstance(b, str):
        b = b.encode()
    return _varint((num << 3) | 2) + _varint(len(b)) + b


def decode(buf):
    """-> {field: [values]} with ints for varints and bytes for the rest."""
    out, i = {}, 0
    while i < len(buf):
        key, i = _read_varint(buf, i)
        num, wt = key >> 3, key & 7
        if wt == 0:
            v, i = _read_varint(buf, i)
        elif wt == 2:
            ln, i = _read_varint(buf, i)
            v, i = buf[i:i + ln], i + ln
        elif wt == 5:
            v, i = buf[i:i + 4], i + 4
        elif wt == 1:
            v, i = buf[i:i + 8], i + 8
        else:
            raise ValueError("bad wire type")
        out.setdefault(num, []).append(v)
    return out


def _read_varint(buf, i):
    shift = n = 0
    while True:
        b = buf[i]
        i += 1
        n |= (b & 0x7F) << shift
        if not b & 0x80:
            return n, i
        shift += 7


def one(d, k, default=None):
    v = d.get(k)
    return v[0] if v else default


class Mumble:
    def __init__(self, host, port, password):
        ctx = ssl.create_default_context()
        ctx.check_hostname = False          # local server, self-signed certificate
        ctx.verify_mode = ssl.CERT_NONE
        raw = socket.create_connection((host, port), timeout=10)
        self.s = ctx.wrap_socket(raw, server_hostname=host)
        self.buf = b""
        self.channels = {}                  # id -> {"name", "parent"}
        self.denied = []
        self.acl_reply = None
        self.users_reply = None
        self.last_ping = 0
        self.synced = False
        v1 = (1 << 16) | (5 << 8)
        v2 = (1 << 48) | (5 << 32)
        self.send(T_VERSION, f_int(1, v1) + f_int(5, v2) + f_bytes(2, "devilsrage-builder")
                  + f_bytes(3, "Linux"))
        self.send(T_AUTH, f_bytes(1, "SuperUser") + f_bytes(2, password) + f_int(5, 1))
        deadline = time.time() + 20
        while not self.synced:
            if time.time() > deadline:
                sys.exit("No answer from the Mumble server (timed out logging in).")
            self.pump(1)

    def send(self, mtype, payload):
        self.s.sendall(struct.pack(">HI", mtype, len(payload)) + payload)

    def pump(self, seconds):
        end = time.time() + seconds
        while True:
            if time.time() - self.last_ping > 10:
                self.send(T_PING, f_int(1, int(time.time())))
                self.last_ping = time.time()
            left = end - time.time()
            if left <= 0:
                return
            self.s.settimeout(left)
            try:
                chunk = self.s.recv(65536)
            except (socket.timeout, TimeoutError):
                return
            except ssl.SSLWantReadError:
                continue
            if not chunk:
                sys.exit("The Mumble server closed the connection.")
            self.buf += chunk
            while len(self.buf) >= 6:
                mtype, ln = struct.unpack(">HI", self.buf[:6])
                if len(self.buf) < 6 + ln:
                    break
                body, self.buf = self.buf[6:6 + ln], self.buf[6 + ln:]
                self.handle(mtype, decode(body))

    def handle(self, mtype, m):
        if mtype == T_REJECT:
            sys.exit("Login refused by the server: "
                     + one(m, 2, b"wrong SuperUser password?").decode(errors="replace"))
        elif mtype == T_SYNC:
            self.synced = True
        elif mtype == T_CHANSTATE:
            cid = one(m, 1)
            if cid is None:
                return
            ch = self.channels.setdefault(cid, {"name": None, "parent": None})
            if 2 in m:
                ch["parent"] = one(m, 2)
            if 3 in m:
                ch["name"] = one(m, 3).decode()
        elif mtype == T_CHANREMOVE:
            self.channels.pop(one(m, 1), None)
        elif mtype == T_DENIED:
            reason = one(m, 4, b"").decode(errors="replace")
            self.denied.append(f"channel {one(m, 2)}: type {one(m, 5)} {reason}".strip())
        elif mtype == T_ACL:
            self.acl_reply = m
        elif mtype == T_QUERYUSERS:
            self.users_reply = m

    def child(self, parent, name):
        for cid, ch in self.channels.items():
            if ch["parent"] == parent and ch["name"] == name:
                return cid
        return None

    # -- channels --------------------------------------------------------
    def ensure(self, parent, name, position, description):
        cid = self.child(parent, name)
        if cid is None:
            time.sleep(1.2)                 # server allows ~1 new channel per second
            self.send(T_CHANSTATE, f_int(2, parent) + f_bytes(3, name)
                      + f_bytes(5, description) + f_int(9, position) + f_int(8, 0))
            deadline = time.time() + 8
            while cid is None and time.time() < deadline:
                self.pump(0.3)
                cid = self.child(parent, name)
            if cid is None:
                sys.exit(f"Could not create channel '{name}'. {'; '.join(self.denied)}")
            print(f"  created  {name}")
        else:
            self.send(T_CHANSTATE, f_int(1, cid) + f_bytes(5, description) + f_int(9, position))
            print(f"  updated  {name}")
        return cid

    # -- permissions -----------------------------------------------------
    def query_acl(self, cid):
        time.sleep(1.2)
        self.acl_reply = None
        self.send(T_ACL, f_int(1, cid) + f_int(5, 1))
        deadline = time.time() + 8
        while self.acl_reply is None and time.time() < deadline:
            self.pump(0.3)
        if self.acl_reply is None:
            sys.exit("The server did not answer the permissions query.")
        return self.acl_reply

    def set_acl(self, cid, rules, groups=b""):
        payload = f_int(1, cid) + f_int(2, 1) + groups
        for r in rules:
            body = (f_int(1, int(r["here"])) + f_int(2, int(r["subs"])) + f_int(3, 0)
                    + f_bytes(5, r["group"]) + f_int(6, r["grant"]) + f_int(7, r["deny"]))
            payload += f_bytes(4, body)
        time.sleep(1.2)                     # server allows ~1 permission change per second
        self.send(T_ACL, payload)
        self.pump(0.5)


def user_id(m, name):
    """Registered user id for a Mumble name, or exit with a clear message."""
    m.users_reply = None
    m.send(T_QUERYUSERS, f_bytes(2, name))
    deadline = time.time() + 8
    while m.users_reply is None and time.time() < deadline:
        m.pump(0.3)
    r = m.users_reply or {}
    for uid, nm in zip(r.get(1, []), r.get(2, [])):
        if nm.decode().lower() == name.lower():
            return uid
    sys.exit(f"'{name}' is not a registered Mumble user yet. They need to connect once "
             "and click Self → Register first.")


def root_groups(reply, extra_add=None):
    """Our four groups (keeping their members) plus any group an admin added."""
    existing = {}
    for g in reply.get(3, []):
        g = decode(g)
        if one(g, 2, 1):                    # inherited from a parent: not ours to send
            continue
        existing[one(g, 1).decode()] = g
    out = b""
    names = OUR_GROUPS + [n for n in existing if n not in OUR_GROUPS]
    for name in names:
        g = existing.get(name, {})
        body = (f_bytes(1, name) + f_int(2, 0) + f_int(3, one(g, 3, 1))
                + f_int(4, one(g, 4, 1)))
        adds = list(g.get(5, []))
        if extra_add and extra_add[0] == name and extra_add[1] not in adds:
            adds.append(extra_add[1])
        for uid in adds:
            body += f_int(5, uid)
        for uid in g.get(6, []):
            body += f_int(6, uid)
        out += f_bytes(3, body)
    kept = {n: len(existing[n].get(5, [])) for n in existing}
    return out, kept


def read_password(env_file):
    if os.environ.get("MUMBLE_SUPERUSER_PASSWORD"):
        return os.environ["MUMBLE_SUPERUSER_PASSWORD"]
    try:
        with open(env_file) as fh:
            for line in fh:
                if line.startswith("MUMBLE_SUPERUSER_PASSWORD="):
                    return line.split("=", 1)[1].strip().strip('"')
    except FileNotFoundError:
        pass
    sys.exit(f"No SuperUser password found in {env_file}.")


def show(nodes, depth=0):
    for name, _, children, *rest in nodes:
        print("  " * depth + "- " + name)
        show(children, depth + 1)


def build(m, parent, nodes, acls):
    for pos, (name, desc, children, *rest) in enumerate(nodes):
        cid = m.ensure(parent, name, pos, desc)
        if rest and rest[0] is not None:
            acls.append((name, cid, rest[0]))
        build(m, cid, children, acls)


def main():
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("--show", action="store_true", help="print the planned tree only")
    ap.add_argument("--host", default="127.0.0.1")
    ap.add_argument("--port", type=int, default=64738)
    ap.add_argument("--env", default=os.path.expanduser("~/mumble/.env"))
    ap.add_argument("--add", nargs=2, metavar=("GROUP", "NAME"),
                    help="add a registered user to visitor/member/fc/admin and stop")
    a = ap.parse_args()

    if a.show:
        print("The Devil's Rage")
        show(TREE, 1)
        return

    m = Mumble(a.host, a.port, read_password(a.env))
    if a.add:
        group, name = a.add[0].lower(), a.add[1]
        if group not in OUR_GROUPS:
            sys.exit(f"Group must be one of: {', '.join(OUR_GROUPS)}")
        uid = user_id(m, name)
        groups, kept = root_groups(m.query_acl(0), (group, uid))
        m.set_acl(0, ROOT_RULES, groups)
        m.pump(1)
        if m.denied:
            sys.exit("Server refused: " + "; ".join(m.denied))
        print(f"Added {name} to {group}.")
        return
    print("Logged in as SuperUser. Channels:")
    m.send(T_CHANSTATE, f_int(1, 0) + f_bytes(5, ROOT_NOTE))
    acls = []
    build(m, 0, TREE, acls)

    print("Permissions:")
    groups, kept = root_groups(m.query_acl(0))
    m.set_acl(0, ROOT_RULES, groups)
    print("  The Devil's Rage (top) + groups "
          + ", ".join(f"{n}: {kept.get(n, 0)} kept" for n in OUR_GROUPS))
    for name, cid, rules in acls:
        m.set_acl(cid, rules)
        print(f"  {name}")

    m.pump(1.5)
    wanted = {"The Devil's Rage"}

    def collect(nodes):
        for name, _, children, *rest in nodes:
            wanted.add(name)
            collect(children)
    collect(TREE)
    extra = sorted({c["name"] for c in m.channels.values() if c["name"] not in wanted
                    and c["parent"] is not None})
    if extra:
        print("Left alone (not in the layout): " + ", ".join(extra))
    if m.denied:
        print("Server refused some changes:")
        for d in m.denied:
            print("  " + d)
        sys.exit(1)
    print(f"Done: {len(m.channels)} channels on the server.")


if __name__ == "__main__":
    main()
