#!/usr/bin/env python3
"""
Multicompany OAuth round trip against a live emMCP install (1.11.0+).

A user created in one entity and shared with another (Multicompany transverse
mode) signs in, picks ENTITY on the consent screen, and the AI client then
reads and writes in ENTITY only. Also checks the refusals: an entity the user
has no access to (FORBIDDEN), chosen by tampering with the consent form, asked
in the URL with a token of another entity, or fixed by the connector URL.

Usage:
  EMMCP_BASE=https://doli20.dev03.e-dem.com EMMCP_LOGIN=alice EMMCP_PASSWORD=... \
  EMMCP_ENTITY=3 EMMCP_FORBIDDEN_ENTITY=2 EMMCP_LOGIN_ENTITY=1 \
      python3 tests/e2e_oauth_multicompany.py

Requirements on the install: Multicompany enabled, emMCP enabled in ENTITY and
FORBIDDEN, the user may work in ENTITY (group of that entity with third party
read/create + "extend to all third parties") and not in FORBIDDEN, at least one
third party in each entity. It creates one third party in ENTITY, deletes it at
the end, registers OAuth clients and grants for the user: development installs
only, never a customer's.
"""
import base64
import hashlib
import json
import os
import re
import secrets
import sys
from urllib.parse import urlencode, urlparse, parse_qs

import requests
import urllib3

urllib3.disable_warnings()

BASE = os.environ.get("EMMCP_BASE", "https://doli20.dev03.e-dem.com").rstrip("/")
LOGIN = os.environ.get("EMMCP_LOGIN", "")
PASSWORD = os.environ.get("EMMCP_PASSWORD", "")
ENTITY = int(os.environ.get("EMMCP_ENTITY", "3"))
FORBIDDEN = int(os.environ.get("EMMCP_FORBIDDEN_ENTITY", "2"))
LOGIN_ENTITY = os.environ.get("EMMCP_LOGIN_ENTITY", "1")
MODULE_URL = os.environ.get("EMMCP_MODULE_URL", BASE + "/custom/emmcp")
REDIRECT = "https://claude.ai/api/mcp/auth_callback"

failures = 0


def check(label, ok, detail=""):
    global failures
    print(("  OK   " if ok else "  FAIL ") + label + ("" if ok or not detail else "  -> " + str(detail)[:400]))
    if not ok:
        failures += 1
    return ok


def fatal(label, detail=""):
    check(label, False, detail)
    print("\nAborted: %d failure(s)" % failures)
    sys.exit(1)


s = requests.Session()
s.verify = False
mcp_url = MODULE_URL + "/mcp.php"


def body(resp):
    t = resp.text.strip()
    if t.startswith("event:") or t.startswith("data:"):
        t = "\n".join(l[5:].strip() for l in t.splitlines() if l.startswith("data:"))
    try:
        return json.loads(t)
    except ValueError:
        return {"raw": resp.text[:300]}


class Mcp:
    def __init__(self, url, access):
        self.url = url
        self.hdr = {"Authorization": "Bearer " + access, "Accept": "application/json, text/event-stream",
                    "Content-Type": "application/json"}
        self.sid = None
        self.n = 0

    def post(self, payload):
        h = dict(self.hdr)
        if self.sid:
            h["Mcp-Session-Id"] = self.sid
        return s.post(self.url, data=json.dumps(payload), headers=h)

    def initialize(self):
        r = self.post({"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {
            "protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": {"name": "emmcp-e2e-mc", "version": "1"}}})
        if r.status_code == 200:
            self.sid = r.headers.get("Mcp-Session-Id")
            self.post({"jsonrpc": "2.0", "method": "notifications/initialized"})
        return r

    def call(self, name, args):
        self.n += 1
        r = self.post({"jsonrpc": "2.0", "id": 10 + self.n, "method": "tools/call",
                       "params": {"name": name, "arguments": args}})
        res = body(r).get("result", {})
        text = "".join(c.get("text", "") for c in res.get("content", []))
        return r, res, text


def login_if_needed(r, url):
    if 'name="username"' not in r.text:
        return r
    tok = re.search(r'name="token" value="([^"]+)"', r.text)
    data = {"token": tok.group(1) if tok else "", "actionlogin": "login", "loginfunction": "loginfunction",
            "username": LOGIN, "password": PASSWORD, "entity": LOGIN_ENTITY}
    return s.post(url, data=data, allow_redirects=False)


def register(as_meta, name):
    r = s.post(as_meta["registration_endpoint"], json={
        "client_name": name, "redirect_uris": [REDIRECT], "grant_types": ["authorization_code", "refresh_token"],
        "response_types": ["code"], "token_endpoint_auth_method": "none"})
    if r.status_code not in (200, 201):
        fatal("register " + name, r.text)
    return r.json()["client_id"]


def authorize_params(client_id, resource):
    verifier = secrets.token_urlsafe(48)
    challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).rstrip(b"=").decode()
    return verifier, {
        "response_type": "code", "client_id": client_id, "redirect_uri": REDIRECT,
        "state": secrets.token_urlsafe(12), "scope": "dolibarr", "resource": resource,
        "code_challenge": challenge, "code_challenge_method": "S256"}


print("1. Discovery and registration")
r = s.post(mcp_url, json={"jsonrpc": "2.0", "id": 1, "method": "initialize"})
check("mcp.php without credentials answers 401", r.status_code == 401, r.status_code)
prm_url = re.search(r'resource_metadata="([^"]+)"', r.headers.get("WWW-Authenticate", "")).group(1)
issuer = s.get(prm_url).json()["authorization_servers"][0]
as_meta = s.get(issuer + "/.well-known/oauth-authorization-server").json()
client_id = register(as_meta, "emMCP e2e multicompany")

print("2. Consent: choose entity %d" % ENTITY)
verifier, params = authorize_params(client_id, mcp_url)
auth_url = as_meta["authorization_endpoint"] + "?" + urlencode(params)
r = login_if_needed(s.get(auth_url, allow_redirects=False), auth_url)
if r.status_code in (301, 302, 303):
    r = s.get(auth_url, allow_redirects=False)
if not check("consent screen shown", 'value="consent"' in r.text, r.status_code):
    fatal("consent screen", r.text[:500])
options = [int(v) for v in re.findall(r'<option value="(\d+)"', r.text)]
hidden = re.search(r'name="emmcp_entity" value="(\d+)"', r.text)
offered = options or ([int(hidden.group(1))] if hidden else [])
check("consent offers entity %d" % ENTITY, ENTITY in offered, offered)
check("consent does not offer entity %d (no access)" % FORBIDDEN, FORBIDDEN not in offered, offered)
consent_page = r.text
form_token = re.search(r'name="token" value="([^"]+)"', consent_page).group(1)

print("3. Tampered consent asking entity %d is refused" % FORBIDDEN)
r = s.post(as_meta["authorization_endpoint"],
           data=dict(params, token=form_token, action="consent", decision="accept", emmcp_entity=str(FORBIDDEN)),
           allow_redirects=False)
q = parse_qs(urlparse(r.headers.get("Location", "")).query)
check("redirect carries error=access_denied and no code", q.get("error") == ["access_denied"] and "code" not in q,
      r.headers.get("Location", r.status_code))

print("4. Accept with entity %d, token" % ENTITY)
verifier, params = authorize_params(client_id, mcp_url)
auth_url = as_meta["authorization_endpoint"] + "?" + urlencode(params)
r = s.get(auth_url, allow_redirects=False)
form_token = re.search(r'name="token" value="([^"]+)"', r.text).group(1)
r = s.post(as_meta["authorization_endpoint"],
           data=dict(params, token=form_token, action="consent", decision="accept", emmcp_entity=str(ENTITY)),
           allow_redirects=False)
q = parse_qs(urlparse(r.headers.get("Location", "")).query)
if not check("redirect carries a code", "code" in q, r.headers.get("Location", r.status_code)):
    fatal("code")
r = s.post(as_meta["token_endpoint"], data={
    "grant_type": "authorization_code", "code": q["code"][0], "redirect_uri": REDIRECT,
    "client_id": client_id, "code_verifier": verifier, "resource": mcp_url})
if not check("token 200", r.status_code == 200, r.text):
    fatal("token")
tokens = r.json()

print("5. MCP in entity %d" % ENTITY)
m = Mcp(mcp_url, tokens["access_token"])
check("initialize 200", m.initialize().status_code == 200)
r = m.post({"jsonrpc": "2.0", "id": 2, "method": "tools/list"})
tools = [t["name"] for t in body(r).get("result", {}).get("tools", [])]
check("tools/list returns tools", len(tools) > 5, r.text[:200])
check("no SQL tool under Multicompany", not any("sql" in t for t in tools), tools)
r, res, text = m.call("dolibarr_environment", {})
env = {}
try:
    env = json.loads(text)
except ValueError:
    pass
check("dolibarr_environment reports entity %d" % ENTITY, str(env.get("entity", "")) == str(ENTITY) or '"entity": %d' % ENTITY in text, text[:300])
r, res, text = m.call("dolibarr_list", {"resource": "thirdparties", "fields": "id,name,entity", "limit": 100})
rows = []
try:
    rows = json.loads(text)
except ValueError:
    pass
check("dolibarr_list returns third parties", isinstance(rows, list) and len(rows) > 0, text[:300])
check("every third party belongs to entity %d" % ENTITY,
      isinstance(rows, list) and rows and all(str(x.get("entity")) == str(ENTITY) for x in rows),
      [(x.get("name"), x.get("entity")) for x in rows] if isinstance(rows, list) else rows)
name = "E2E MC %s" % secrets.token_hex(3)
r, res, text = m.call("dolibarr_create", {"resource": "thirdparties", "data": json.dumps({"name": name, "client": 1})})
new_id = None
mm = re.search(r'"?id"?\s*[:=]\s*"?(\d+)', text) or re.search(r"(\d+)", text)
if mm:
    new_id = int(mm.group(1))
check("dolibarr_create thirdparty succeeds", not res.get("isError") and new_id, text[:300])
if new_id:
    r, res, text = m.call("dolibarr_get", {"resource": "thirdparties", "id": new_id})
    created = {}
    try:
        created = json.loads(text)
    except ValueError:
        pass
    check("the created third party is in entity %d" % ENTITY, str(created.get("entity")) == str(ENTITY),
          (created.get("name"), created.get("entity")))

print("6. Same token, another entity in the URL")
m2 = Mcp(mcp_url + "?entity=%d" % FORBIDDEN, tokens["access_token"])
r = m2.initialize()
check("mcp.php?entity=%d with an entity-%d token answers 403" % (FORBIDDEN, ENTITY), r.status_code == 403, r.status_code)
m3 = Mcp(mcp_url + "?entity=%d" % ENTITY, tokens["access_token"])
check("mcp.php?entity=%d with the same token works" % ENTITY, m3.initialize().status_code == 200)

print("7. Connector URL of entity %d (no access)" % FORBIDDEN)
forbidden_url = mcp_url + "?entity=%d" % FORBIDDEN
r = s.post(forbidden_url, json={"jsonrpc": "2.0", "id": 1, "method": "initialize"})
prm_url2 = re.search(r'resource_metadata="([^"]+)"', r.headers.get("WWW-Authenticate", ""))
prm2 = s.get(prm_url2.group(1)).json() if prm_url2 else {}
check("its protected resource metadata names that URL", prm2.get("resource") == forbidden_url, prm2.get("resource"))
client2 = register(as_meta, "emMCP e2e multicompany forbidden")
_, params2 = authorize_params(client2, forbidden_url)
r = s.get(as_meta["authorization_endpoint"] + "?" + urlencode(params2), allow_redirects=False)
check("consent refused with a readable message", 'value="consent"' not in r.text and "class=\"error\"" in r.text,
      r.text[-400:])

print("8. Refresh keeps the entity")
r = s.post(as_meta["token_endpoint"], data={"grant_type": "refresh_token", "refresh_token": tokens["refresh_token"],
                                            "client_id": client_id})
check("refresh 200", r.status_code == 200, r.text)
m4 = Mcp(mcp_url, r.json().get("access_token", ""))
check("new access token works", m4.initialize().status_code == 200)
r, res, text = m4.call("dolibarr_list", {"resource": "thirdparties", "fields": "id,name,entity", "limit": 100})
try:
    rows = json.loads(text)
except ValueError:
    rows = []
check("after refresh: still only entity %d" % ENTITY,
      isinstance(rows, list) and rows and all(str(x.get("entity")) == str(ENTITY) for x in rows), text[:300])

if new_id:
    r, res, text = m4.call("dolibarr_delete", {"resource": "thirdparties", "id": new_id})
    print("  --   cleanup: third party %d deleted: %s" % (new_id, not res.get("isError")))

print("\n%s: %d failure(s)" % ("PASSED" if failures == 0 else "FAILED", failures))
sys.exit(1 if failures else 0)
