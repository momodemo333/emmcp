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
 * \brief   The empayment_import_statement tool: routes, body, entity, refusals, guidance.
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

print "1. Review\n";
$next = array('http_code' => 200, 'content' => json_encode(array('status' => 'preview', 'counts' => array('lines' => 2, 'new' => 2), 'intent_fingerprint' => 'FP1')), 'error' => '');
list($out, $err) = attempt(function () use ($tools, $lines) {
	return $tools->importStatement(52341, 'VISA-2026-11', 'card', $lines, false, null, 'Relevé VISA.pdf', '2026-10-21', '2026-11-20', 'EUR', '-412.30', '-1288.95');
});
$c = end($calls);
check('review goes to POST empayment/statements/review', $c['url'] === 'https://erp.example.test/sub/api/index.php/empayment/statements/review', $c['url']);
check('body carries the contract fields', $c['body']['bank_account_id'] === 52341 && $c['body']['statement_ref'] === 'VISA-2026-11' && $c['body']['kind'] === 'card'
	&& $c['body']['period'] === array('from' => '2026-10-21', 'to' => '2026-11-20') && $c['body']['opening_balance'] === '-412.30' && $c['body']['closing_balance'] === '-1288.95'
	&& $c['body']['currency'] === 'EUR' && $c['body']['source_label'] === 'Relevé VISA.pdf', $c['raw']);
check('review sends neither confirm nor fingerprint', !isset($c['body']['confirm']) && !isset($c['body']['intent_fingerprint']));
check('amounts stay strings in the JSON body', strpos($c['raw'], '"amount":"-55.03"') !== false, $c['raw']);
check('lines passed unchanged (external_id, accents)', $c['body']['lines'][0]['external_id'] === '2310230001' && $c['body']['lines'][1]['communication'] === 'Prélèvement mensuel');
check('API key and entity headers sent', in_array('DOLAPIKEY: KEY123', $c['headers'], true) && in_array('DOLAPIENTITY: 7', $c['headers'], true), implode(' | ', $c['headers']));
$o = json_decode((string) $out, true);
check('review result returned with next_step naming the fingerprint', $err === null && $o['status'] === 'preview' && strpos($o['next_step'], 'FP1') !== false && strpos($o['next_step'], 'Show the user') === 0, (string) $err);

print "2. Optional fields\n";
attempt(function () use ($noEntity, $lines) {
	return $noEntity->importStatement(5, 'R1', 'account', $lines);
});
$c = end($calls);
check('no entity header without Multicompany entity', !preg_grep('/^DOLAPIENTITY/', $c['headers']));
check('absent optional fields are not sent', !array_key_exists('period', $c['body']) && !array_key_exists('opening_balance', $c['body']) && !array_key_exists('source_label', $c['body']), $c['raw']);

print "3. Import\n";
$before = count($calls);
list($out, $err) = attempt(function () use ($tools, $lines) {
	return $tools->importStatement(52341, 'VISA-2026-11', 'card', $lines, true, null);
});
check('confirm without fingerprint refused before any call', $out === null && strpos((string) $err, 'FINGERPRINT_REQUIRED') !== false && count($calls) === $before, (string) $err);
$next = array('http_code' => 200, 'content' => json_encode(array('status' => 'imported', 'statement_id' => 812, 'counts' => array('imported' => 2), 'screen_url' => '/sub/custom/empayment/coda/transactions.php?search_statement=812')), 'error' => '');
list($out, $err) = attempt(function () use ($tools, $lines) {
	return $tools->importStatement(52341, 'VISA-2026-11', 'card', $lines, true, 'FP1', 'Relevé VISA.pdf', '2026-10-21', '2026-11-20', 'EUR', '-412.30', '-1288.95');
});
$c = end($calls);
check('import goes to POST empayment/statements', $c['url'] === 'https://erp.example.test/sub/api/index.php/empayment/statements', $c['url']);
check('import body = review body + confirm true + fingerprint', $c['body']['confirm'] === true && $c['body']['intent_fingerprint'] === 'FP1' && $c['body']['lines'] === $calls[0]['body']['lines'] && $c['body']['period'] === $calls[0]['body']['period']);
$o = json_decode((string) $out, true);
check('screen link made absolute', $err === null && strpos($o['next_step'], 'https://erp.example.test/sub/custom/empayment/coda/transactions.php?search_statement=812') !== false, (string) ($o['next_step'] ?? $err));

print "4. Replay\n";
$next = array('http_code' => 200, 'content' => json_encode(array('status' => 'already_imported', 'statement_id' => null)), 'error' => '');
list($out, $err) = attempt(function () use ($tools, $lines) {
	return $tools->importStatement(52341, 'VISA-2026-11', 'card', $lines);
});
$o = json_decode((string) $out, true);
check('already_imported is a success telling not to alter lines', $err === null && $o['status'] === 'already_imported' && strpos($o['next_step'], 'do not change any line') !== false);

print "5. Refusals\n";
$errors = array();
for ($i = 0; $i < 3; $i++) {
	$errors[] = array('code' => 'LINE_AMOUNT_INVALID', 'index' => $i, 'field' => 'amount', 'message' => 'Montant invalide ligne '.$i.' '.str_repeat('x', 150));
}
$next = array('http_code' => 400, 'content' => json_encode(array('error' => array('code' => 400, 'message' => 'Bad Request', 'errors' => $errors))), 'error' => '');
list($out, $err) = attempt(function () use ($tools, $lines) {
	return $tools->importStatement(52341, 'VISA-2026-11', 'card', $lines);
});
$e = json_decode((string) $err, true);
check('400 is a tool error carrying EVERY faulty line, untruncated', $out === null && count($e['error']['errors']) === 3 && $e['error']['errors'][2]['index'] === 2 && strlen($e['error']['errors'][2]['message']) > 150, (string) $err);
check('400 tells to fix the reading and review again', strpos($e['what_to_do'], 'review again') !== false);

$cases = array(
	array(409, 'STALE_INTENT', 'Run the review again'),
	array(422, 'BALANCE_MISMATCH', 'never adjust a balance'),
	array(422, 'CURRENCY_MISMATCH', 'Ask the user which account'),
	array(404, 'ACCOUNT_NOT_FOUND', 'never guess'),
	array(503, null, 'Do NOT repeat the import'),
);
foreach ($cases as $case) {
	$next = array('http_code' => $case[0], 'content' => json_encode(array('error' => array('code' => $case[0], 'message' => 'x', 'errors' => $case[1] ? array(array('code' => $case[1])) : array()))), 'error' => '');
	list($out, $err) = attempt(function () use ($tools, $lines) {
		return $tools->importStatement(52341, 'VISA-2026-11', 'card', $lines, true, 'FP1');
	});
	$e = json_decode((string) $err, true);
	check($case[0].' '.($case[1] ?: '').': what_to_do says "'.$case[2].'"', $out === null && $e['http_status'] === $case[0] && strpos($e['what_to_do'], $case[2]) !== false, (string) $err);
}
$next = array('http_code' => 403, 'content' => '{"error":{"code":403,"message":"Forbidden"}}', 'error' => '');
list($out, $err) = attempt(function () use ($tools, $lines) {
	return $tools->importStatement(52341, 'VISA-2026-11', 'card', $lines, true, 'FP1');
});
check('403 on import names the import rights', strpos((string) $err, 'coda_setup') !== false, (string) $err);
$next = array('http_code' => 0, 'content' => '', 'error' => 'Operation timed out');
list($out, $err) = attempt(function () use ($tools, $lines) {
	return $tools->importStatement(52341, 'VISA-2026-11', 'card', $lines, true, 'FP1');
});
check('transport failure on import: do not repeat, review again', strpos((string) $err, 'Do NOT repeat the import') !== false, (string) $err);

print "5b. Redirect to https\n";
$seq = array();
$redirecting = function ($url, $json, $headers) use (&$seq) {
	$seq[] = $url;
	return count($seq) === 1 ? array('http_code' => 308, 'content' => '', 'error' => '') : array('http_code' => 200, 'content' => '{"status":"preview","intent_fingerprint":"F"}', 'error' => '');
};
$httpTools = new EmmcpEmpaymentTools('http://erp.example.test', 'KEY123', null, $redirecting);
list($out, $err) = attempt(function () use ($httpTools, $lines) {
	return $httpTools->importStatement(1, 'R', 'card', $lines);
});
check('308 from an http base: retried once over https, same host and path', $err === null && $seq === array('http://erp.example.test/api/index.php/empayment/statements/review', 'https://erp.example.test/api/index.php/empayment/statements/review'), implode(' , ', $seq));
$seq = array();
$always = function ($url, $json, $headers) use (&$seq) {
	$seq[] = $url;
	return array('http_code' => 308, 'content' => '', 'error' => '');
};
$httpsTools = new EmmcpEmpaymentTools('https://erp.example.test', 'KEY123', null, $always);
attempt(function () use ($httpsTools, $lines) {
	return $httpsTools->importStatement(1, 'R', 'card', $lines);
});
check('no retry from an https base', count($seq) === 1);

print "6. MCP schema seen by the client\n";
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
$tool = null;
foreach ($body['result']['tools'] ?? array() as $t) {
	if ($t['name'] === 'empayment_import_statement') {
		$tool = $t;
	}
}
array_map('unlink', glob($sessionDir.'/*') ?: array());
@rmdir($sessionDir);
check('tool registered under its name', $tool !== null);
if ($tool !== null) {
	$schema = $tool['inputSchema'];
	check('amount is a string in the lines schema', ($schema['properties']['lines']['items']['properties']['amount']['type'] ?? null) === 'string', json_encode($schema['properties']['lines'] ?? null));
	check('kind limited to card / account', ($schema['properties']['kind']['enum'] ?? null) === array('card', 'account'));
	check('required: bank account, ref, kind, lines', array_values(array_intersect(array('bank_account_id', 'statement_ref', 'kind', 'lines'), $schema['required'] ?? array())) === array('bank_account_id', 'statement_ref', 'kind', 'lines'), json_encode($schema['required'] ?? null));
	check('description asks to show the review first', strpos($tool['description'], 'ALWAYS show the user this review') !== false);
}

print "7. Availability\n";
$admin = new User($db);
$admin->fetch(1);
$admin->getrights();
$version = EmmcpEmpaymentTools::empaymentVersion($db);
print "  --   EMPayment installed: ".($version !== '' ? $version : 'no').", enabled: ".(isModEnabled('empayment') ? 'yes' : 'no')."\n";
$expected = isModEnabled('empayment') && $version !== '' && version_compare($version, '2.7.0', '>=');
check('offered to an administrator iff EMPayment >= 2.7.0 is enabled', EmmcpEmpaymentTools::isAvailable($db, $admin) === $expected);
if (isModEnabled('empayment')) {
	check('not offered with EMPayment 2.6.1 (no import routes)', EmmcpEmpaymentTools::isAvailable($db, $admin, '2.6.1') === false);
	check('offered with EMPayment 2.7.0 and later', EmmcpEmpaymentTools::isAvailable($db, $admin, '2.7.0') && EmmcpEmpaymentTools::isAvailable($db, $admin, '2.10.0'));
}
$nobody = new User($db);
$nobody->id = 999999;
check('not offered to a user without the review rights', EmmcpEmpaymentTools::isAvailable($db, $nobody) === false);
$ext = emmcp_mcp_extensions($db, $admin, 'KEY123', null);
$names = array_map(function ($x) {
	return $x->name();
}, $ext);
check('emmcp_mcp_extensions includes it exactly when available', in_array('emmcp-empayment', $names, true) === $expected, implode(',', $names));
check('no API key, no built-in tool', !in_array('emmcp-empayment', array_map(function ($x) {
	return $x->name();
}, emmcp_mcp_extensions($db, $admin)), true));

print "\n".($failures === 0 ? 'ALL CHECKS PASSED' : $failures.' FAILURE(S)')."\n";
exit($failures === 0 ? 0 : 1);
