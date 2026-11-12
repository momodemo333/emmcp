#!/usr/bin/env python3
"""
Full OAuth + MCP round trip against a live emMCP install, the way Claude does it:

  401 on mcp.php -> protected resource metadata -> AS metadata (both forms)
  -> dynamic registration -> Dolibarr login + consent -> code (+ iss check)
  -> token -> initialize / tools/list / tools/call -> refresh -> replay check

Usage:
  EMMCP_BASE=https://doli21.dev03.e-dem.com EMMCP_LOGIN=morgan EMMCP_PASSWORD=... \
      python3 tests/e2e_oauth_flow.py

Exit code 0 when every step passed. Never run it against a customer install:
it registers a client and creates a grant for the account it signs in with.
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

BASE = os.environ.get("EMMCP_BASE", "https://doli21.dev03.e-dem.com").rstrip("/")
LOGIN = os.environ.get("EMMCP_LOGIN", "")
PASSWORD = os.environ.get("EMMCP_PASSWORD", "")
MODULE_URL = os.environ.get("EMMCP_MODULE_URL", BASE + "/custom/emmcp")
REDIRECT = "https://claude.ai/api/mcp/auth_callback"
VERIFY = os.environ.get("EMMCP_VERIFY_TLS", "0") == "1"

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
s.verify = VERIFY
mcp_url = MODULE_URL + "/mcp.php"

print("1. Discovery")
r = s.post(mcp_url, json={"jsonrpc": "2.0", "id": 1, "method": "initialize"})
check("mcp.php without credentials answers 401", r.status_code == 401, r.status_code)
www = r.headers.get("WWW-Authenticate", "")
m = re.search(r'resource_metadata="([^"]+)"', www)
if not m:
    fatal("WWW-Authenticate carries resource_metadata", www)
prm = s.get(m.group(1)).json()
check("protected resource metadata lists an authorization server", bool(prm.get("authorization_servers")), prm)
issuer = prm["authorization_servers"][0]
as_meta = s.get(issuer + "/.well-known/oauth-authorization-server").json()
check("AS metadata issuer matches", as_meta.get("issuer") == issuer, as_meta.get("issuer"))
oidc = s.get(issuer + "/.well-known/openid-configuration")
check("OpenID discovery answers", oidc.status_code == 200, oidc.status_code)

print("2. Registration")
r = s.post(as_meta["registration_endpoint"], json={
    "client_name": "emMCP e2e test",
    "redirect_uris": [REDIRECT],
    "grant_types": ["authorization_code", "refresh_token"],
    "response_types": ["code"],
    "token_endpoint_auth_method": "none",
})
if not check("register 201", r.status_code in (200, 201), r.text):
    fatal("registration")
client_id = r.json()["client_id"]

print("3. Authorize (login + consent)")
verifier = secrets.token_urlsafe(48)
challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).rstrip(b"=").decode()
state = secrets.token_urlsafe(12)
auth_params = {
    "response_type": "code", "client_id": client_id, "redirect_uri": REDIRECT,
    "state": state, "scope": "dolibarr", "resource": mcp_url,
    "code_challenge": challenge, "code_challenge_method": "S256",
}
auth_url = as_meta["authorization_endpoint"] + "?" + urlencode(auth_params)
r = s.get(auth_url, allow_redirects=False)
if 'name="username"' in r.text:
    tok = re.search(r'name="token" value="([^"]+)"', r.text)
    data = {"token": tok.group(1) if tok else "", "actionlogin": "login", "loginfunction": "loginfunction",
            "username": LOGIN, "password": PASSWORD}
    r = s.post(auth_url, data=data, allow_redirects=False)
if not check("consent screen shown", 'value="consent"' in r.text, r.status_code):
    fatal("consent screen", r.text[:300])
form_token = re.search(r'name="token" value="([^"]+)"', r.text).group(1)
consent = dict(auth_params, token=form_token, action="consent", decision="accept")
r = s.post(as_meta["authorization_endpoint"], data=consent, allow_redirects=False)
loc = r.headers.get("Location", "")
q = parse_qs(urlparse(loc).query)
if not check("redirect carries a code", "code" in q, loc):
    fatal("authorization code")
check("state echoed", q.get("state", [""])[0] == state)
check("iss equals the metadata issuer (RFC 9207)", q.get("iss", [""])[0] == as_meta["issuer"], q.get("iss"))

print("4. Token")
r = s.post(as_meta["token_endpoint"], data={
    "grant_type": "authorization_code", "code": q["code"][0], "redirect_uri": REDIRECT,
    "client_id": client_id, "code_verifier": verifier, "resource": mcp_url,
})
if not check("token 200", r.status_code == 200, r.text):
    fatal("token")
tokens = r.json()
access, refresh = tokens["access_token"], tokens.get("refresh_token")

print("5. MCP calls with the access token")
hdr = {"Authorization": "Bearer " + access, "Accept": "application/json, text/event-stream",
       "Content-Type": "application/json"}


def rpc(payload, session_id=None):
    h = dict(hdr)
    if session_id:
        h["Mcp-Session-Id"] = session_id
    return s.post(mcp_url, data=json.dumps(payload), headers=h)


def body(resp):
    t = resp.text.strip()
    if t.startswith("event:") or t.startswith("data:"):
        t = "\n".join(l[5:].strip() for l in t.splitlines() if l.startswith("data:"))
    try:
        return json.loads(t)
    except ValueError:
        return {"raw": resp.text[:300]}


r = rpc({"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {
    "protocolVersion": "2025-06-18", "capabilities": {},
    "clientInfo": {"name": "emmcp-e2e", "version": "1"}}})
if not check("initialize 200", r.status_code == 200, "%s %s" % (r.status_code, r.text)):
    fatal("initialize")
sid = r.headers.get("Mcp-Session-Id")
rpc({"jsonrpc": "2.0", "method": "notifications/initialized"}, sid)
r = rpc({"jsonrpc": "2.0", "id": 2, "method": "tools/list"}, sid)
tools = [t["name"] for t in body(r).get("result", {}).get("tools", [])]
check("tools/list returns tools", len(tools) > 5, r.text[:200])
r = rpc({"jsonrpc": "2.0", "id": 3, "method": "tools/call",
         "params": {"name": "dolibarr_environment", "arguments": {}}}, sid)
res = body(r).get("result", {})
check("tools/call dolibarr_environment succeeds", r.status_code == 200 and not res.get("isError"), r.text[:300])

r = rpc({"jsonrpc": "2.0", "id": 4, "method": "tools/call",
         "params": {"name": "dolibarr_list", "arguments": {"resource": "thirdparties", "limit": 1}}}, sid)
res = body(r).get("result", {})
check("tools/call dolibarr_list goes through the REST API", r.status_code == 200 and not res.get("isError"), r.text[:300])
if "todo_list" in tools:
    r = rpc({"jsonrpc": "2.0", "id": 5, "method": "tools/call", "params": {"name": "todo_list", "arguments": {}}}, sid)
    res = body(r).get("result", {})
    check("extension tool (emmcpext todo_list) works", r.status_code == 200 and not res.get("isError"), r.text[:300])
else:
    print("  --   no extension module enabled (todo_list absent)")

print("6. Refresh")
if refresh:
    r = s.post(as_meta["token_endpoint"], data={"grant_type": "refresh_token", "refresh_token": refresh,
                                                "client_id": client_id})
    check("refresh 200", r.status_code == 200, r.text)
    new_access = r.json().get("access_token", "")
    h = dict(hdr, Authorization="Bearer " + new_access)
    r2 = s.post(mcp_url, data=json.dumps({"jsonrpc": "2.0", "id": 9, "method": "initialize", "params": {
        "protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": {"name": "e2e", "version": "1"}}}), headers=h)
    check("new access token works", r2.status_code == 200, r2.status_code)

print("7. Failures carry a precise reason")
r = s.post(as_meta["token_endpoint"], data={"grant_type": "refresh_token", "refresh_token": refresh or "x",
                                            "client_id": client_id})
check("replayed refresh token refused", r.status_code == 400, r.text)
check("reason says it was already used", "already used" in r.json().get("error_description", ""), r.text)
r = s.post(as_meta["token_endpoint"], data={"grant_type": "refresh_token", "refresh_token": "emcp_rnope",
                                            "client_id": "emcp_client_does_not_exist"})
check("unknown client_id refused with 401", r.status_code == 401, r.text)
check("reason names the unknown client_id", "unknown client_id" in r.json().get("error_description", ""), r.text)
r = s.get(as_meta["authorization_endpoint"] + "?" + urlencode(dict(auth_params, client_id="emcp_client_does_not_exist")))
check("authorize with unknown client_id shows an error page", "Invalid OAuth request" in r.text, r.status_code)

print("\n%s: %d failure(s)" % ("PASSED" if failures == 0 else "FAILED", failures))
sys.exit(1 if failures else 0)
