#!/usr/bin/env python3
"""
EMPayment statement tools end to end: MCP client -> emMCP -> EMPayment 2.7.0 REST.

  EMMCP_BASE=https://doli21.dev03.e-dem.com EMMCP_API_KEY=<key of a user with the
  EMPayment CODA read + import rights and Banks read + modify> EMPAYMENT_ACCOUNT_ID=52341 \
      python3 tests/e2e_empayment_import.py

Tools: empayment_review_statement (read-only) then empayment_import_statement.
Needs EMPayment 2.7.0 with the corrections after review (LINE_EXTERNAL_ID_CONFLICT,
accept_probable_duplicates, closed line schema, BALANCE_MISMATCH hint).

WRITES: imports three movements (and their bank lines) on EMPAYMENT_ACCOUNT_ID,
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
REVIEW, IMPORT = "empayment_review_statement", "empayment_import_statement"

failures = 0
s = requests.Session()
s.verify = False
hdr = {"Authorization": "Bearer " + KEY, "Accept": "application/json, text/event-stream", "Content-Type": "application/json"}


def check(label, ok, detail=""):
    global failures
    print(("  OK   " if ok else "  FAIL ") + label + ("" if ok or not detail else "  -> " + str(detail)[:600]))
    if not ok:
        failures += 1
    return ok


def body(r):
    t = r.text.strip()
    if t.startswith("event:") or t.startswith("data:"):
        t = "\n".join(l[5:].strip() for l in t.splitlines() if l.startswith("data:"))
    return json.loads(t)


n = [10]


def call(tool, args):
    """Returns (is_error, data). A JSON-RPC error (schema refusal) counts as an error."""
    n[0] += 1
    args = {k: v for k, v in args.items() if v is not None}
    r = s.post(URL, headers=hdr, data=json.dumps({"jsonrpc": "2.0", "id": n[0], "method": "tools/call",
                                                   "params": {"name": tool, "arguments": args}}))
    b = body(r)
    if "error" in b:
        return True, {"jsonrpc_error": b["error"]}
    res = b.get("result", {})
    text = "".join(c.get("text", "") for c in res.get("content", []))
    try:
        data = json.loads(text)
    except ValueError:
        data = {"raw": text}
    return bool(res.get("isError")), data


def codes(d):
    err = d.get("error") if isinstance(d.get("error"), dict) else {}
    return [e.get("code") for e in err.get("errors", []) if isinstance(e, dict)]


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

print("1. Tools offered")
tools = {t["name"]: t for t in body(s.post(URL, headers=hdr, data=json.dumps({"jsonrpc": "2.0", "id": 2, "method": "tools/list"})))["result"]["tools"]}
check("both tools listed", REVIEW in tools and IMPORT in tools, list(tools))
check("review is read-only, import is not", tools.get(REVIEW, {}).get("annotations", {}).get("readOnlyHint") is True
      and tools.get(IMPORT, {}).get("annotations", {}).get("readOnlyHint") is False)

suffix = secrets.token_hex(3).upper()
ref = "E2E-" + suffix
line1 = {"date": "2026-11-02", "value_date": "2026-11-03", "amount": "-55.03", "counterparty": "AMAZON MKTP FR",
         "communication": "405-1790026-3293954", "external_id": "E2E%s01" % suffix}
line2 = {"date": "2026-11-10", "amount": "-20.00", "counterparty": "SNCB INTERNET", "communication": "Billet Bruxelles"}
base = {"bank_account_id": ACCOUNT, "statement_ref": ref, "kind": "card", "source_label": "Relevé e2e é.pdf",
        "period_from": "2026-11-01", "period_to": "2026-11-15", "currency": "EUR",
        "opening_balance": "-100.00", "closing_balance": "-175.03", "lines": [line1, line2]}

print("2. Refusals before anything is written")
err, d = call(REVIEW, dict(base, lines=[{"date": "2026-11-02", "amount": "-12,5", "counterparty": "X"},
                                        {"date": "2026-11-03", "amount": "-3.00"}], opening_balance=None, closing_balance=None))
idx = sorted({e.get("index") for e in (d.get("error") or {}).get("errors", []) if isinstance(e, dict)}) if isinstance(d.get("error"), dict) else []
check("bad lines: 400, every faulty line listed", err and d.get("http_status") == 400 and idx == [0, 1], d)
err, d = call(REVIEW, dict(base, lines=[dict(line1, type="debit")]))
check("unknown line key refused (MCP schema or LINE_FIELD_UNKNOWN)", err and ("jsonrpc_error" in d or "LINE_FIELD_UNKNOWN" in codes(d)), d)
err, d = call(REVIEW, dict(base, opening_balance="100.00", closing_balance="175.03"))
check("balances as printed (positive): 422 BALANCE_MISMATCH", err and d.get("http_status") == 422 and "BALANCE_MISMATCH" in codes(d), d)
check("BALANCE_MISMATCH: opposite of both balances, server hint relayed", "opposite of BOTH balances" in d.get("what_to_do", "") and bool(d.get("server_hint")), d)
err, d = call(REVIEW, dict(base, bank_account_id=999999999))
check("unknown account: 404, never guess", err and d.get("http_status") == 404 and "never guess" in d.get("what_to_do", ""), d)

print("3. Review then import")
err, d = call(REVIEW, base)
fp = d.get("intent_fingerprint")
check("review: preview, 2 new lines, balance ok", not err and d.get("status") == "preview" and d.get("counts", {}).get("new") == 2
      and d.get("balance_check", {}).get("status") == "ok", d)
check("review: next_step names empayment_import_statement and the fingerprint", fp and fp in d.get("next_step", "") and IMPORT in d.get("next_step", ""), d.get("next_step"))
err, d = call(IMPORT, dict(base, intent_fingerprint="0" * 64))
check("forged fingerprint: 409, review again", err and d.get("http_status") == 409 and REVIEW in d.get("what_to_do", ""), d)
err, d = call(IMPORT, dict(base, intent_fingerprint=fp))
check("import: 2 movements, 2 bank lines, no warning", not err and d.get("status") == "imported" and d.get("counts", {}).get("imported") == 2
      and d.get("bank_lines") == 2 and "WARNING" not in d.get("next_step", ""), d)
check("import: absolute link to the movements", "search_statement=" in d.get("next_step", "") and "https://" in d.get("next_step", ""), d.get("next_step"))

print("4. Replay read identically creates nothing")
err, d = call(REVIEW, base)
check("review again: already_imported", not err and d.get("status") == "already_imported", d)
err, d = call(IMPORT, dict(base, intent_fingerprint=fp))
check("import again with the old fingerprint: already_imported", not err and d.get("status") == "already_imported", d)

print("5. external_id that is not unique: conflict, not a duplicate")
err, d = call(REVIEW, dict(base, statement_ref=ref + "B", opening_balance=None, closing_balance=None,
                           lines=[dict(line1, date="2026-11-12", amount="-9.99", communication="autre achat")]))
check("same external_id, other date and amount: 422 LINE_EXTERNAL_ID_CONFLICT", err and "LINE_EXTERNAL_ID_CONFLICT" in codes(d), d)
check("guidance: remove external_id, never change date or amount", "Remove external_id" in d.get("what_to_do", "") and "Never change the date or the amount" in d.get("what_to_do", ""), d.get("what_to_do"))

print("6. Same movement read differently: probable duplicate to confirm")
reread = dict(base, statement_ref=ref + "C", opening_balance=None, closing_balance=None,
              lines=[{"date": line2["date"], "amount": line2["amount"], "counterparty": "SNCB", "communication": "Billet BXL"}])
err, d = call(REVIEW, reread)
fp2 = d.get("intent_fingerprint")
check("review flags requires_probable_duplicates_confirmation", not err and d.get("requires_probable_duplicates_confirmation") is True, d)
check("next_step: show each one, review again with accept_probable_duplicates", "accept_probable_duplicates=true" in d.get("next_step", ""), d.get("next_step"))
err, d = call(IMPORT, dict(reread, intent_fingerprint=fp2))
check("import without accepting: 409 PROBABLE_DUPLICATES_UNCONFIRMED, nothing written", err and d.get("http_status") == 409 and "PROBABLE_DUPLICATES_UNCONFIRMED" in json.dumps(d), d)
err, d = call(REVIEW, dict(reread, accept_probable_duplicates=True))
fp3 = d.get("intent_fingerprint")
check("review with accept_probable_duplicates: new fingerprint", not err and fp3 and fp3 != fp2, d)
err, d = call(IMPORT, dict(reread, accept_probable_duplicates=True, intent_fingerprint=fp3))
check("import after the user's line-by-line agreement: 1 movement", not err and d.get("status") == "imported" and d.get("counts", {}).get("imported") == 1, d)

print("\n%s: %d failure(s)  (statements %s* on account %d: reset the test account now)" % (
    "PASSED" if failures == 0 else "FAILED", failures, ref, ACCOUNT))
sys.exit(1 if failures else 0)
