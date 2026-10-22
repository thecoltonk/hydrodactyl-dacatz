#!/usr/bin/env python3
"""
Live attack test for the self-service server plans. Run it on the VPS against
the real panel with THROWAWAY accounts. It creates a few small servers and
deletes them again at the end.

Setup (admin panel):
  user A: plan 2 GB RAM / 1 core / 10 GB / max servers 3
  user B: plan 2 GB RAM / 1 core / 10 GB / max servers 3
  user C: plan 2 GB RAM / 2 cores / 10 GB / max servers 0
  user D: no plan (optional)
Each user: Account -> API Keys -> create a key. Then:

  PANEL=https://your-panel A_KEY=ptlc_... B_KEY=ptlc_... C_KEY=ptlc_... \
  D_KEY=ptlc_... VICTIM=<uuid of a server none of them own> \
  python3 attack-selfservice.py
"""
import json
import os
import ssl
import sys
import threading
import urllib.error
import urllib.request

PANEL = os.environ.get("PANEL", "").rstrip("/")
KEYS = {k: os.environ.get(f"{k}_KEY", "") for k in "ABCD"}
VICTIM = os.environ.get("VICTIM", "")
if not PANEL or not KEYS["A"] or not KEYS["B"] or not KEYS["C"] or not VICTIM:
    sys.exit(__doc__)

CTX = ssl.create_default_context()
results = []
created = []  # (key, uuid, name)


def call(who, method, path, body=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(PANEL + path, data=data, method=method, headers={
        "Authorization": "Bearer " + KEYS[who],
        "Accept": "application/json",
        "Content-Type": "application/json",
    })
    try:
        with urllib.request.urlopen(req, context=CTX, timeout=120) as res:
            raw = res.read().decode() or "{}"
            return res.status, json.loads(raw) if raw.strip().startswith(("{", "[")) else {}
    except urllib.error.HTTPError as err:
        raw = err.read().decode() or "{}"
        try:
            return err.code, json.loads(raw)
        except ValueError:
            return err.code, {"raw": raw[:200]}


def check(name, status, expected, detail=""):
    ok = status in expected
    results.append(ok)
    print(f"{'PASS' if ok else 'FAIL'}  {name}: got {status}, want {'/'.join(map(str, expected))} {detail}")


def error_of(body):
    if isinstance(body, dict):
        if isinstance(body.get("errors"), list) and body["errors"]:
            return body["errors"][0].get("detail", "")
        return str(body.get("error", ""))[:160]
    return ""


def create(who, name, egg, memory, cpu, disk, extra=None):
    body = {"name": name, "egg_id": egg, "memory": memory, "cpu": cpu, "disk": disk}
    body.update(extra or {})
    status, data = call(who, "POST", "/api/client/account/plan/servers", body)
    if status == 201:
        created.append((who, data["uuid"], name))
    return status, data


def usage(who):
    _, data = call(who, "GET", "/api/client/account/plan")
    return data.get("plan"), data.get("used")


# --- plan basics -------------------------------------------------------------
status, info = call("A", "GET", "/api/client/account/plan")
check("A can read own plan", status, [200])
eggs = info.get("eggs", [])
if not info.get("plan") or not eggs:
    sys.exit("User A has no plan or no eggs are visible; set up the plan first.")
egg = next((e["id"] for e in eggs if "paper" in e["name"].lower()), eggs[0]["id"])

# --- A: quota + throttle (3 creates / 10 min) ---------------------------------
s, d = create("A", "atk over quota", egg, 3072, 50, 1024)
check("A create over RAM quota", s, [422], error_of(d))
s, d = create("A", "atk small", egg, 512, 50, 1024)
check("A create small valid server", s, [201], error_of(d))
a_server = d.get("uuid") if s == 201 else None
s, d = create("A", "atk unlimited cpu", egg, 512, 0, 1024)
check("A create with CPU 0 (unlimited bypass)", s, [422], error_of(d))
s, d = create("A", "atk throttle", egg, 512, 50, 1024)
check("A 4th create inside 10 min is throttled", s, [429], error_of(d))

# --- A: resize bypasses --------------------------------------------------------
if a_server:
    for label, body, want in [
        ("resize over quota", {"memory": 3072, "cpu": 50, "disk": 1024}, [422]),
        ("resize to unlimited RAM (0)", {"memory": 0, "cpu": 50, "disk": 1024}, [422]),
        ("resize to unlimited CPU (0)", {"memory": 512, "cpu": 0, "disk": 1024}, [422]),
        ("resize to unlimited disk (0)", {"memory": 512, "cpu": 50, "disk": 0}, [422]),
        ("resize negative", {"memory": -1, "cpu": 50, "disk": 1024}, [422]),
        ("resize within plan", {"memory": 1024, "cpu": 100, "disk": 2048}, [200]),
    ]:
        s, d = call("A", "PATCH", f"/api/client/servers/{a_server}/quick-build", body)
        check(f"A {label}", s, want, error_of(d))
    s, d = call("A", "DELETE", f"/api/client/servers/{a_server}/self-service", {"confirm_name": "wrong"})
    check("A delete with wrong name", s, [422], error_of(d))

# --- cross-account attacks ---------------------------------------------------
for who, target, label in [("B", a_server, "A's server"), ("B", VICTIM, "victim server"), ("A", VICTIM, "victim server")]:
    if not target:
        continue
    s, d = call(who, "PATCH", f"/api/client/servers/{target}/quick-build", {"memory": 512, "cpu": 50, "disk": 1024})
    check(f"{who} resize {label}", s, [403, 404], error_of(d))
    s, d = call(who, "DELETE", f"/api/client/servers/{target}/self-service", {"confirm_name": "x"})
    check(f"{who} delete {label}", s, [403, 404], error_of(d))

# --- B: input validation -----------------------------------------------------
s, d = create("B", "atk bad egg", 999999, 512, 50, 1024)
check("B create with nonexistent egg", s, [422], error_of(d))
s, d = create("B", "atk\u0000\u0007ctrl", egg, 512, 50, 1024)
check("B create with control chars in name", s, [422], error_of(d))
s, d = create("B", "atk bad pack", None, 512, 50, 1024,
              {"modpack": {"source": "modrinth", "project_id": "../../admin", "version_id": "x/../y"}})
check("B create with path-traversal modpack ids", s, [422], error_of(d))

# --- D: no plan --------------------------------------------------------------
if KEYS["D"]:
    s, d = create("D", "atk no plan", egg, 512, 50, 1024)
    check("D (no plan) create", s, [403], error_of(d))
    s, d = call("D", "GET", "/api/client/account/plan/modpacks/search?source=modrinth")
    check("D (no plan) modpack search", s, [403], error_of(d))
    if a_server:
        s, d = call("D", "PATCH", f"/api/client/servers/{a_server}/quick-build", {"memory": 512, "cpu": 50, "disk": 1024})
        check("D resize A's server", s, [403, 404], error_of(d))

# --- C: race 3 simultaneous creates against a 2 GB plan -----------------------
race = []


def racer(i):
    race.append(create("C", f"atk race {i}", egg, 1024, 50, 1024))


threads = [threading.Thread(target=racer, args=(i,)) for i in range(3)]
for t in threads:
    t.start()
for t in threads:
    t.join()
wins = [d for s, d in race if s == 201]
check("C race: exactly 2 of 3 fit a 2 GB plan", len(wins), [2], str([s for s, _ in race]))
plan, used = usage("C")
check("C usage never exceeds plan", 0 if used["memory"] <= plan["memory"] else 1, [0], f"used {used['memory']} of {plan['memory']} MiB")
ports = []
for _, uuid, _ in [c for c in created if c[0] == "C"]:
    _, srv = call("C", "GET", f"/api/client/servers/{uuid}")
    for alloc in srv.get("attributes", {}).get("relationships", {}).get("allocations", {}).get("data", []):
        ports.append((alloc["attributes"]["ip"], alloc["attributes"]["port"]))
check("C race servers got distinct ports", 0 if len(ports) == len(set(ports)) else 1, [0], str(ports))

# --- cleanup -----------------------------------------------------------------
for who, uuid, name in created:
    s, d = call(who, "DELETE", f"/api/client/servers/{uuid}/self-service", {"confirm_name": name})
    check(f"cleanup: {who} deletes '{name}'", s, [204], error_of(d))
for who in "AC":
    _, used = usage(who)
    check(f"{who} usage back to zero", used["servers"], [0], str(used))

print(f"\n{sum(results)}/{len(results)} passed")
sys.exit(0 if all(results) else 1)
