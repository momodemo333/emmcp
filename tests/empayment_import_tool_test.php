<?php
/* Copyright (C) 2026 E-dem
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    tests/integration_readonly.php
 * \file    tests/empayment_import_tool_test.php
 * \ingroup emmcp
 * \brief   The EMPayment statement tools (review, import): routes, body, entity, refusals, guidance.
 *
 * HTTP is replaced by a recording transport: nothing reaches EMPayment, no
 * database row is written. Also checks that the tool is offered only with
 * EMPayment 2.7.0+ and the review rights, and that the MCP schema the client
 * sees forces amounts to strings.
 *
 * Run: make php s=/var/www/html/custom/emmcp/tests/empayment_import_tool_test.php
 */

if (!defined('NOLOGIN')) {
	define('NOLOGIN', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOSESSION')) {
	define('NOSESSION', '1');
}

$res = 0;
if (!$res && file_exists("/var/www/html/main.inc.php")) {
	$res = @include "/var/www/html/main.inc.php";
}
if (!$res) {
	die("Main include failed\n");
}

dol_include_once('/emmcp/lib/emmcp.lib.php');
dol_include_once('/emmcp/lib/emmcp_bootstrap.php');
emmcp_mcp_oauth_autoload();
$autoload = emmcpFindMcpAutoloader();
if (!$autoload) {
	die("MCP runtime not found\n");
}
require_once $autoload;
dol_include_once('/emmcp/class/EmmcpEmpaymentExtension.php');

$failures = 0;

/**
 * @param string $label  Check description
 * @param bool   $ok     Outcome
 * @param string $detail Extra context
 * @return void
 */
function check($label, $ok, $detail = '')
{
	global $failures;
	if ($ok) {
		print "  OK   ".$label."\n";
	} else {
		$failures++;
		print "  FAIL ".$label.($detail !== '' ? ' -- '.$detail : '')."\n";
	}
}

$calls = array();
$next = array('http_code' => 200, 'content' => '{}', 'error' => '');
$transport = function ($url, $json, $headers) use (&$calls, &$next) {
	$calls[] = array('url' => $url, 'body' => json_decode($json, true), 'raw' => $json, 'headers' => $headers);
	return $next;
};
$tools = new EmmcpEmpaymentTools('https://erp.example.test/sub', 'KEY123', 7, $transport);
$noEntity = new EmmcpEmpaymentTools('https://erp.example.test/sub', 'KEY123', null, $transport);

$lines = array(
	array('date' => '2026-10-23', 'amount' => '-55.03', 'counterparty' => 'AMAZON MKTP FR', 'external_id' => '2310230001'),
	array('date' => '2026-11-05', 'amount' => '412.30', 'communication' => 'Prélèvement mensuel'),
);

/**
 * @param callable $fn Call
 * @return array{0: ?string, 1: ?string} [result, error message]
 */
function attempt($fn)
{
	try {
		return array($fn(), null);
	} catch (\Mcp\Exception\ToolCallException $e) {
		return array(null, $e->getMessage());
	}
}

/**
 * @param int   $code   HTTP status
 * @param array $errors error.errors
 * @param array $extra  Extra keys of error
 * @return array
 */
function refusal($code, array $errors, array $extra = array())
{
	return array('http_code' => $code, 'content' => json_encode(array('error' => array('code' => $code, 'message' => 'x', 'errors' => $errors) + $extra)), 'error' => '');
}

print "1. Review tool\n";
$next = array('http_code' => 200, 'content' => json_encode(array('status' => 'preview', 'counts' => array('lines' => 2, 'new' => 2), 'requires_probable_duplicates_confirmation' => false, 'dolibarr_balance' => array('status' => 'ok'), 'intent_fingerprint' => 'FP1')), 'error' => '');
list($out, $err) = attempt(function () use ($tools, $lines) {
	return $tools->reviewStatement(52341, 'VISA-2026-11', 'card', $lines, false, 'Relevé VISA.pdf', '2026-10-21', '2026-11-20', 'EUR', '-412.30', '-1288.95');
});
$c = end($calls);
$reviewBody = $c['body'];
check('review goes to POST empayment/statements/review', $c['url'] === 'https://erp.example.test/sub/api/index.php/empayment/statements/review', $c['url']);
check('body carries the contract fields', $c['body']['bank_account_id'] === 52341 && $c['body']['statement_ref'] === 'VISA-2026-11' && $c['body']['kind'] === 'card'
	&& $c['body']['period'] === array('from' => '2026-10-21', 'to' => '2026-11-20') && $c['body']['opening_balance'] === '-412.30' && $c['body']['closing_balance'] === '-1288.95'
	&& $c['body']['currency'] === 'EUR' && $c['body']['source_label'] === 'Relevé VISA.pdf', $c['raw']);
check('review sends neither confirm, fingerprint nor accept_probable_duplicates', !isset($c['body']['confirm']) && !isset($c['body']['intent_fingerprint']) && !isset($c['body']['accept_probable_duplicates']));
check('amounts stay strings in the JSON body', strpos($c['raw'], '"amount":"-55.03"') !== false, $c['raw']);
check('lines passed unchanged (external_id, accents)', $c['body']['lines'][0]['external_id'] === '2310230001' && $c['body']['lines'][1]['communication'] === 'Prélèvement mensuel');
check('API key and entity headers sent', in_array('DOLAPIKEY: KEY123', $c['headers'], true) && in_array('DOLAPIENTITY: 7', $c['headers'], true), implode(' | ', $c['headers']));
$o = json_decode((string) $out, true);
check('next_step: show the user, then empayment_import_statement with the fingerprint', $err === null && strpos($o['next_step'], 'Show the user') === 0 && strpos($o['next_step'], 'empayment_import_statement') !== false && strpos($o['next_step'], 'FP1') !== false, (string) ($o['next_step'] ?? $err));

print "2. Review: probable duplicates, unavailable Dolibarr balance\n";
$next = array('http_code' => 200, 'content' => json_encode(array('status' => 'preview', 'requires_probable_duplicates_confirmation' => true, 'dolibarr_balance' => array('status' => 'unavailable'), 'intent_fingerprint' => 'FP2')), 'error' => '');
list($out, $err) = attempt(function () use ($tools, $lines) {
	return $tools->reviewStatement(52341, 'R', 'card', $lines);
});
$o = json_decode((string) $out, true);
check('probable duplicates: show each, review again with accept_probable_duplicates=true, not this fingerprint', strpos($o['next_step'], 'PROBABLE DUPLICATES') !== false && strpos($o['next_step'], 'accept_probable_duplicates=true') !== false && strpos($o['next_step'], 'Do not call empayment_import_statement with this fingerprint') !== false, $o['next_step']);
check('unavailable Dolibarr balance: do not conclude a statement is missing', strpos($o['next_step'], 'do not conclude that a statement is missing') !== false);
attempt(function () use ($tools, $lines) {
	return $tools->reviewStatement(52341, 'R', 'card', $lines, true);
});
$c = end($calls);
check('accept_probable_duplicates=true sent when given', ($c['body']['accept_probable_duplicates'] ?? null) === true);

print "3. Optional fields\n";
attempt(function () use ($noEntity, $lines) {
	return $noEntity->reviewStatement(5, 'R1', 'account', $lines);
});
$c = end($calls);
check('no entity header without Multicompany entity', !preg_grep('/^DOLAPIENTITY/', $c['headers']));
check('absent optional fields are not sent', !array_key_exists('period', $c['body']) && !array_key_exists('opening_balance', $c['body']) && !array_key_exists('source_label', $c['body']), $c['raw']);

print "4. Import tool\n";
$before = count($calls);
list($out, $err) = attempt(function () use ($tools, $lines) {
	return $tools->importStatement(52341, 'VISA-2026-11', 'card', $lines, '  ');
});
check('blank fingerprint refused before any call', $out === null && strpos((string) $err, 'FINGERPRINT_REQUIRED') !== false && count($calls) === $before, (string) $err);
$next = array('http_code' => 200, 'content' => json_encode(array('status' => 'imported', 'statement_id' => 812, 'counts' => array('imported' => 2), 'bank_lines' => 2, 'screen_url' => '/sub/custom/empayment/coda/transactions.php?search_statement=812')), 'error' => '');
list($out, $err) = attempt(function () use ($tools, $lines) {
	return $tools->importStatement(52341, 'VISA-2026-11', 'card', $lines, 'FP1', false, 'Relevé VISA.pdf', '2026-10-21', '2026-11-20', 'EUR', '-412.30', '-1288.95');
});
$c = end($calls);
check('import goes to POST empayment/statements', $c['url'] === 'https://erp.example.test/sub/api/index.php/empayment/statements', $c['url']);
$expected = $reviewBody + array('confirm' => true, 'intent_fingerprint' => 'FP1');
check('import body = review body + confirm true + fingerprint', $c['body'] == $expected, $c['raw']);
$o = json_decode((string) $out, true);
check('screen link made absolute, no bank-line warning when complete', $err === null && strpos($o['next_step'], 'https://erp.example.test/sub/custom/empayment/coda/transactions.php?search_statement=812') !== false && strpos($o['next_step'], 'WARNING') === false, (string) ($o['next_step'] ?? $err));
$next = array('http_code' => 200, 'content' => json_encode(array('status' => 'imported', 'counts' => array('imported' => 3), 'bank_lines' => 1, 'warnings' => array(array('code' => 'BANK_LINES_MISSING')))), 'error' => '');
list($out, $err) = attempt(function () use ($tools, $lines) {
	return $tools->importStatement(52341, 'R', 'card', $lines, 'FP1');
});
$o = json_decode((string) $out, true);
check('bank_lines < imported: next_step warns, tells to inform the user, not to import again', strpos($o['next_step'], 'WARNING: 2 of the 3 imported movements have NO bank line') !== false && strpos($o['next_step'], 'Do not import again') !== false, $o['next_step']);
$next = array('http_code' => 200, 'content' => json_encode(array('status' => 'imported', 'counts' => array('imported' => 2), 'warnings' => array(array('code' => 'BANK_LINES_MISSING')))), 'error' => '');
list($out) = attempt(function () use ($tools, $lines) {
	return $tools->importStatement(52341, 'R', 'card', $lines, 'FP1');
});
$o = json_decode((string) $out, true);
check('BANK_LINES_MISSING warning alone also triggers the warning', strpos($o['next_step'], 'NO bank line') !== false);

print "5. Replay\n";
$next = array('http_code' => 200, 'content' => json_encode(array('status' => 'already_imported', 'statement_id' => null)), 'error' => '');
list($out, $err) = attempt(function () use ($tools, $lines) {
	return $tools->reviewStatement(52341, 'VISA-2026-11', 'card', $lines);
});
$o = json_decode((string) $out, true);
check('already_imported is a success telling not to alter lines', $err === null && $o['status'] === 'already_imported' && strpos($o['next_step'], 'do not change any line') !== false);

print "6. Refusals\n";
$errors = array();
for ($i = 0; $i < 3; $i++) {
	$errors[] = array('code' => 'LINE_AMOUNT_INVALID', 'index' => $i, 'field' => 'amount', 'message' => 'Montant invalide ligne '.$i.' '.str_repeat('x', 150));
}
$next = refusal(400, $errors);
list($out, $err) = attempt(function () use ($tools, $lines) {
	return $tools->reviewStatement(52341, 'VISA-2026-11', 'card', $lines);
});
$e = json_decode((string) $err, true);
check('400 is a tool error carrying EVERY faulty line, untruncated', $out === null && count($e['error']['errors']) === 3 && $e['error']['errors'][2]['index'] === 2 && strlen($e['error']['errors'][2]['message']) > 150, (string) $err);
check('400 tells to fix the reading and run empayment_review_statement again', strpos($e['what_to_do'], 'run empayment_review_statement again') !== false);

$hint = "Balances are signed from the account's point of view: an amount owed on a card is NEGATIVE even if the statement prints it positive.";
$next = refusal(422, array(array('code' => 'BALANCE_MISMATCH', 'index' => null, 'field' => null, 'message' => 'diff', 'hint' => $hint)));
list($out, $err) = attempt(function () use ($tools, $lines) {
	return $tools->reviewStatement(52341, 'R', 'card', $lines, false, null, null, null, null, '412.30', '1288.95');
});
$e = json_decode((string) $err, true);
check('BALANCE_MISMATCH: opposite of BOTH balances first, never flip the lines', strpos($e['what_to_do'], 'try the opposite of BOTH balances first') !== false && strpos($e['what_to_do'], 'Never flip the sign of the lines') !== false, $e['what_to_do']);
check('BALANCE_MISMATCH: server hint relayed', ($e['server_hint'] ?? '') === $hint && strpos($e['what_to_do'], 'Server hint: '.$hint) !== false, (string) $err);
$next = refusal(422, array(), array('hint' => $hint, 'errors' => array(array('code' => 'BALANCE_MISMATCH'))));
list(, $err) = attempt(function () use ($tools, $lines) {
	return $tools->reviewStatement(52341, 'R', 'card', $lines);
});
$e = json_decode((string) $err, true);
check('hint at error level also relayed', ($e['server_hint'] ?? '') === $hint, (string) $err);

$cases = array(
	array(422, 'LINE_EXTERNAL_ID_CONFLICT', 'Remove external_id from the lines concerned (from ALL lines if it is a counter'),
	array(422, 'LINE_EXTERNAL_ID_CONFLICT', 'Never change the date or the amount'),
	array(409, 'PROBABLE_DUPLICATES_UNCONFIRMED', 'run empayment_review_statement again with accept_probable_duplicates=true'),
	array(409, 'PROBABLE_DUPLICATES_UNCONFIRMED', 'line by line'),
	array(422, 'LINE_FIELD_UNKNOWN', 'express a debit by a negative amount, never by an extra key'),
	array(422, 'FIELD_UNKNOWN', 'send only the tool\'s parameters'),
	array(409, 'STALE_INTENT', 'Run empayment_review_statement again'),
	array(422, 'CURRENCY_MISMATCH', 'Ask the user which account'),
	array(404, 'ACCOUNT_NOT_FOUND', 'never guess'),
	array(503, null, 'Do NOT repeat the import'),
);
foreach ($cases as $case) {
	$next = refusal($case[0], $case[1] ? array(array('code' => $case[1])) : array());
	list($out, $err) = attempt(function () use ($tools, $lines) {
		return $tools->importStatement(52341, 'VISA-2026-11', 'card', $lines, 'FP1');
	});
	$e = json_decode((string) $err, true);
	check($case[0].' '.($case[1] ?: '').': what_to_do says "'.$case[2].'"', $out === null && $e['http_status'] === $case[0] && strpos($e['what_to_do'], $case[2]) !== false, (string) $err);
}
$next = refusal(422, array(array('code' => 'LINE_FIELD_UNKNOWN', 'index' => 0, 'field' => 'type'), array('code' => 'LINE_EXTERNAL_ID_CONFLICT', 'index' => 1)));
list(, $err) = attempt(function () use ($tools, $lines) {
	return $tools->reviewStatement(52341, 'R', 'card', $lines);
});
$e = json_decode((string) $err, true);
check('several codes: one guidance per code', strpos($e['what_to_do'], 'LINE_FIELD_UNKNOWN') !== false && strpos($e['what_to_do'], 'LINE_EXTERNAL_ID_CONFLICT') !== false);
$next = array('http_code' => 403, 'content' => '{"error":{"code":403,"message":"Forbidden"}}', 'error' => '');
list(, $err) = attempt(function () use ($tools, $lines) {
	return $tools->importStatement(52341, 'VISA-2026-11', 'card', $lines, 'FP1');
});
check('403 on import names the import rights', strpos((string) $err, 'coda_setup') !== false, (string) $err);
list(, $err) = attempt(function () use ($tools, $lines) {
	return $tools->reviewStatement(52341, 'VISA-2026-11', 'card', $lines);
});
check('403 on review names the review rights', strpos((string) $err, 'coda_read') !== false, (string) $err);
$next = array('http_code' => 0, 'content' => '', 'error' => 'Operation timed out');
list(, $err) = attempt(function () use ($tools, $lines) {
	return $tools->importStatement(52341, 'VISA-2026-11', 'card', $lines, 'FP1');
});
check('transport failure on import: do not repeat, review again', strpos((string) $err, 'Do NOT repeat the import') !== false, (string) $err);

print "6b. Redirect to https\n";
$seq = array();
$redirecting = function ($url, $json, $headers) use (&$seq) {
	$seq[] = $url;
	return count($seq) === 1 ? array('http_code' => 308, 'content' => '', 'error' => '') : array('http_code' => 200, 'content' => '{"status":"preview","intent_fingerprint":"F"}', 'error' => '');
};
$httpTools = new EmmcpEmpaymentTools('http://erp.example.test', 'KEY123', null, $redirecting);
list($out, $err) = attempt(function () use ($httpTools, $lines) {
	return $httpTools->reviewStatement(1, 'R', 'card', $lines);
});
check('308 from an http base: retried once over https, same host and path', $err === null && $seq === array('http://erp.example.test/api/index.php/empayment/statements/review', 'https://erp.example.test/api/index.php/empayment/statements/review'), implode(' , ', $seq));
$seq = array();
$always = function ($url, $json, $headers) use (&$seq) {
	$seq[] = $url;
	return array('http_code' => 308, 'content' => '', 'error' => '');
};
$httpsTools = new EmmcpEmpaymentTools('https://erp.example.test', 'KEY123', null, $always);
attempt(function () use ($httpsTools, $lines) {
	return $httpsTools->reviewStatement(1, 'R', 'card', $lines);
});
check('no retry from an https base', count($seq) === 1);

print "7. MCP schemas seen by the client\n";
$sessionDir = sys_get_temp_dir().'/emmcp-empayment-test-'.uniqid();
$requestClass = class_exists('GuzzleHttp\Psr7\ServerRequest') ? 'GuzzleHttp\Psr7\ServerRequest' : 'EmmcpVendor\GuzzleHttp\Psr7\ServerRequest';
$rpc = function ($method, $params, $session = null) use ($tools, $sessionDir, $requestClass) {
	$headers = array('Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream');
	if ($session !== null) {
		$headers['Mcp-Session-Id'] = $session;
	}
	$request = new $requestClass('POST', 'https://erp.example.test/mcp.php', $headers, json_encode(array('jsonrpc' => '2.0', 'id' => 2, 'method' => $method, 'params' => $params)));
	$response = \DolibarrMcp\Bootstrap::handleHttpRequest($request, $sessionDir, new \DolibarrMcp\Config\ConnectionConfig('https://erp.example.test', 'KEY123'), null, null, array(new EmmcpEmpaymentExtension($tools)));
	return array(json_decode((string) $response->getBody(), true), $response->getHeaderLine('Mcp-Session-Id'));
};
list(, $session) = $rpc('initialize', array('protocolVersion' => '2025-06-18', 'capabilities' => (object) array(), 'clientInfo' => array('name' => 'test', 'version' => '1')));
list($body) = $rpc('tools/list', array(), $session);
$listed = array();
foreach ($body['result']['tools'] ?? array() as $t) {
	$listed[$t['name']] = $t;
}
list($callBody) = $rpc('tools/call', array('name' => 'empayment_review_statement', 'arguments' => array('bank_account_id' => 1, 'statement_ref' => 'R', 'kind' => 'card', 'lines' => array(array('date' => '2026-11-01', 'amount' => '-1.00', 'counterparty' => 'X', 'type' => 'debit')))), $session);
array_map('unlink', glob($sessionDir.'/*') ?: array());
@rmdir($sessionDir);

$review = $listed['empayment_review_statement'] ?? null;
$import = $listed['empayment_import_statement'] ?? null;
check('both tools registered', $review !== null && $import !== null, implode(',', array_keys($listed)));
if ($review !== null && $import !== null) {
	check('review: readOnlyHint true, idempotentHint true', ($review['annotations']['readOnlyHint'] ?? null) === true && ($review['annotations']['idempotentHint'] ?? null) === true, json_encode($review['annotations'] ?? null));
	check('import: readOnlyHint false, not destructive', ($import['annotations']['readOnlyHint'] ?? null) === false && ($import['annotations']['destructiveHint'] ?? null) === false, json_encode($import['annotations'] ?? null));
	foreach (array('review' => $review, 'import' => $import) as $label => $tool) {
		$schema = $tool['inputSchema'];
		$items = $schema['properties']['lines']['items'] ?? array();
		check($label.': lines items closed (additionalProperties false), amount a string', ($items['additionalProperties'] ?? null) === false && ($items['properties']['amount']['type'] ?? null) === 'string', json_encode($items));
		check($label.': kind limited to card / account', ($schema['properties']['kind']['enum'] ?? null) === array('card', 'account'));
		check($label.': balances described as signed from the account, never "as printed"', strpos($schema['properties']['opening_balance']['description'] ?? '', 'NEGATIVE even if printed positive') !== false && stripos(json_encode($tool), 'as printed"') === false && stripos($tool['description'], 'balance') !== false && strpos($tool['description'], 'try the opposite of BOTH balances first') !== false);
		check($label.': external_id never a line number or counter', strpos($items['properties']['external_id']['description'] ?? '', 'Never a line number') !== false && strpos($tool['description'], 'Never a line number, a counter of the statement or a short authorization code') !== false);
		check($label.': replay rule is conditional, probable duplicates explained', strpos($tool['description'], 'only if its lines are read identically') !== false && strpos($tool['description'], 'almost always a real duplicate') !== false);
		check($label.': accept_probable_duplicates parameter', isset($schema['properties']['accept_probable_duplicates']) && strpos($schema['properties']['accept_probable_duplicates']['description'], 'line by line') !== false);
	}
	$req = $import['inputSchema']['required'] ?? array();
	check('import requires intent_fingerprint, review does not have it', in_array('intent_fingerprint', $req, true) && !isset($review['inputSchema']['properties']['intent_fingerprint']), json_encode($req));
	check('import description: only after the user explicitly approved the review', strpos($import['description'], 'ONLY after the user explicitly approved the review') !== false);
	check('review description: writes nothing, show before import', strpos($review['description'], 'WRITES NOTHING') !== false && strpos($review['description'], 'ALWAYS show the user this review') !== false);
	check('no tool exposes a confirm parameter', !isset($review['inputSchema']['properties']['confirm']) && !isset($import['inputSchema']['properties']['confirm']));
}
$callError = isset($callBody['error']) || !empty($callBody['result']['isError']);
check('a line with an unknown key ("type") is refused by the MCP schema before any call', $callError, json_encode($callBody));

print "8. Availability\n";
$admin = new User($db);
$admin->fetch(1);
$admin->getrights();
$version = EmmcpEmpaymentTools::empaymentVersion($db);
print "  --   EMPayment installed: ".($version !== '' ? $version : 'no').", enabled: ".(isModEnabled('empayment') ? 'yes' : 'no')."\n";
$expectedAvail = isModEnabled('empayment') && $version !== '' && version_compare($version, '2.7.0', '>=');
check('offered to an administrator iff EMPayment >= 2.7.0 is enabled', EmmcpEmpaymentTools::isAvailable($db, $admin) === $expectedAvail);
if (isModEnabled('empayment')) {
	check('not offered with EMPayment 2.6.1 (no import routes)', EmmcpEmpaymentTools::isAvailable($db, $admin, '2.6.1') === false);
	check('offered with EMPayment 2.7.0 and later', EmmcpEmpaymentTools::isAvailable($db, $admin, '2.7.0') && EmmcpEmpaymentTools::isAvailable($db, $admin, '2.10.0'));
}
$nobody = new User($db);
$nobody->id = 999999;
check('not offered to a user without the review rights', EmmcpEmpaymentTools::isAvailable($db, $nobody) === false);
$names = array_map(function ($x) {
	return $x->name();
}, emmcp_mcp_extensions($db, $admin, 'KEY123', null));
check('emmcp_mcp_extensions includes it exactly when available', in_array('emmcp-empayment', $names, true) === $expectedAvail, implode(',', $names));
check('no API key, no built-in tool', !in_array('emmcp-empayment', array_map(function ($x) {
	return $x->name();
}, emmcp_mcp_extensions($db, $admin)), true));

print "\n".($failures === 0 ? 'ALL CHECKS PASSED' : $failures.' FAILURE(S)')."\n";
exit($failures === 0 ? 0 : 1);
