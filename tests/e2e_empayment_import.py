#!/usr/bin/env python3
"""
empayment_import_statement end to end: MCP client -> emMCP -> EMPayment 2.7.0 REST.

  EMMCP_BASE=https://doli21.dev03.e-dem.com EMMCP_API_KEY=<key of a user with the
  EMPayment CODA read + import rights and Banks read + modify> EMPAYMENT_ACCOUNT_ID=52341 \
      python3 tests/e2e_empayment_import.py

WRITES: imports two movements (and their bank lines) on EMPAYMENT_ACCOUNT_ID,
which must be a blank TEST account. Reset it afterwards (doli21:
alfred-reports/empayment-2.7.0-reset-visa-doli21.sql). Never on a customer.
"""
import json
import os
import secrets
import sys

import requests
import urllib3

urllib3.disable_warnings()

BASE = os.environ.get("EMMCP_BASE", "https://doli21.dev03.e-dem.com").rstrip("/")
KEY = os.environ.get("EMMCP_API_KEY", "")
ACCOUNT = int(os.environ.get("EMPAYMENT_ACCOUNT_ID", "0"))
URL = os.environ.get("EMMCP_MODULE_URL", BASE + "/custom/emmcp") + "/mcp.php"
TOOL = "empayment_import_statement"

failures = 0
s = requests.Session()
s.verify = False
hdr = {"Authorization": "Bearer " + KEY, "Accept": "application/json, text/event-stream", "Content-Type": "application/json"}


def check(label, ok, detail=""):
    global failures
    print(("  OK   " if ok else "  FAIL ") + label + ("" if ok or not detail else "  -> " + str(detail)[:500]))
    if not ok:
        failures += 1
    return ok


def body(r):
    t = r.text.strip()
    if t.startswith("event:") or t.startswith("data:"):
        t = "\n".join(l[5:].strip() for l in t.splitlines() if l.startswith("data:"))
    return json.loads(t)


n = [10]


def call(args):
    n[0] += 1
    r = s.post(URL, headers=hdr, data=json.dumps({"jsonrpc": "2.0", "id": n[0], "method": "tools/call",
                                                   "params": {"name": TOOL, "arguments": args}}))
    res = body(r).get("result", {})
    text = "".join(c.get("text", "") for c in res.get("content", []))
    try:
        data = json.loads(text)
    except ValueError:
        data = {"raw": text}
    return bool(res.get("isError")), data


if not KEY or not ACCOUNT:
    print("EMMCP_API_KEY and EMPAYMENT_ACCOUNT_ID are required")
    sys.exit(2)

r = s.post(URL, headers=hdr, data=json.dumps({"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {
    "protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": {"name": "e2e-empayment", "version": "1"}}}))
if r.status_code != 200:
    print("initialize failed", r.status_code, r.text[:300])
    sys.exit(1)
hdr["Mcp-Session-Id"] = r.headers.get("Mcp-Session-Id", "")
s.post(URL, headers=hdr, data=json.dumps({"jsonrpc": "2.0", "method": "notifications/initialized"}))

print("1. Tool offered")
tools = {t["name"]: t for t in body(s.post(URL, headers=hdr, data=json.dumps({"jsonrpc": "2.0", "id": 2, "method": "tools/list"})))["result"]["tools"]}
check("tools/list contains " + TOOL, TOOL in tools)

suffix = secrets.token_hex(3).upper()
ref = "E2E-" + suffix
good_lines = [
    {"date": "2026-11-02", "value_date": "2026-11-03", "amount": "-55.03", "counterparty": "AMAZON MKTP FR",
     "communication": "405-1790026-3293954", "external_id": "E2E%s01" % suffix},
    {"date": "2026-11-10", "amount": "-20.00", "counterparty": "SNCB", "external_id": "E2E%s02" % suffix},
]
base = {"bank_account_id": ACCOUNT, "statement_ref": ref, "kind": "card", "source_label": "Relevé e2e é.pdf",
        "period_from": "2026-11-01", "period_to": "2026-11-15", "currency": "EUR",
        "opening_balance": "-100.00", "closing_balance": "-175.03", "lines": good_lines}

print("2. Refusals before anything is written")
err, d = call(dict(base, lines=[{"date": "2026-11-02", "amount": "-12,5", "counterparty": "X"},
                                {"date": "2026-11-03", "amount": "-3.00"}], opening_balance=None, closing_balance=None))
idx = sorted({e.get("index") for e in d.get("error", {}).get("errors", []) if isinstance(e, dict)})
check("bad lines: tool error, 400, every faulty line listed", err and d.get("http_status") == 400 and idx == [0, 1], d)
err, d = call(dict(base, closing_balance="-175.00"))
check("balance mismatch: 422 with guidance to re-read", err and d.get("http_status") == 422 and "never adjust" in d.get("what_to_do", ""), d)
err, d = call(dict(base, bank_account_id=999999999))
check("unknown account: 404, never guess", err and d.get("http_status") == 404 and "never guess" in d.get("what_to_do", ""), d)

print("3. Review")
err, d = call(base)
fp = d.get("intent_fingerprint")
check("review: preview, 2 new lines, balance ok", not err and d.get("status") == "preview" and d.get("counts", {}).get("new") == 2
      and d.get("balance_check", {}).get("status") == "ok", d)
check("review: next_step says to show the user, names the fingerprint", fp and fp in d.get("next_step", "") and d["next_step"].startswith("Show the user"), d.get("next_step"))

print("4. Import")
err, d = call(dict(base, confirm=True))
check("confirm without fingerprint refused by the tool", err and d.get("error") == "FINGERPRINT_REQUIRED", d)
err, d = call(dict(base, confirm=True, intent_fingerprint="0" * 64))
check("forged fingerprint: 409, review again", err and d.get("http_status") == 409 and "Run the review again" in d.get("what_to_do", ""), d)
err, d = call(dict(base, confirm=True, intent_fingerprint=fp))
check("import: 2 movements imported", not err and d.get("status") == "imported" and d.get("counts", {}).get("imported") == 2, d)
check("import: absolute link to the movements", BASE.split("//")[1].split("/")[0] in d.get("next_step", "") and "search_statement=" in d.get("next_step", ""), d.get("next_step"))

print("5. Replay creates nothing")
err, d = call(base)
check("review again: already_imported", not err and d.get("status") == "already_imported", d)
err, d = call(dict(base, confirm=True, intent_fingerprint=fp))
check("import again with the old fingerprint: already_imported, nothing written", not err and d.get("status") == "already_imported"
      and "do not change any line" in d.get("next_step", ""), d)

print("\n%s: %d failure(s)  (statement %s on account %d: reset the test account now)" % (
    "PASSED" if failures == 0 else "FAILED", failures, ref, ACCOUNT))
sys.exit(1 if failures else 0)
