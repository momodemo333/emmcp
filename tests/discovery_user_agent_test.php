<?php
/*
 * Verdicts of the ChatGPT User-Agent discovery probe (Diagnostic tab).
 * Pure function, no Dolibarr needed: php tests/discovery_user_agent_test.php
 */

require __DIR__.'/../lib/emmcp.lib.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
	echo ($ok ? '  OK   ' : '  FAIL ').$label.($ok ? '' : "\n         ".$detail)."\n";
	$failures += $ok ? 0 : 1;
};

echo "Discovery with ChatGPT's User-Agent\n";

$mcp = 'https://compta.example.ch/custom/emmcp/mcp.php';
$prm = json_encode(array('resource' => $mcp, 'authorization_servers' => array('https://compta.example.ch/custom/emmcp/oauth.php')));

$check('User-Agent is the one seen in ChatGPT discovery logs', strpos(emmcpChatgptDiscoveryUserAgent(), 'aiohttp/') !== false);

$v = emmcpClassifyDiscoveryAnswer(200, $prm, '', $mcp);
$check('200 naming this endpoint is ok', $v['status'] === 'ok', json_encode($v));

$v = emmcpClassifyDiscoveryAnswer(406, '<html>Not Acceptable</html>', '', $mcp);
$check('406 (ModSecurity) is reported as blocked', $v['status'] === 'blocked' && $v['detail'] === 'HTTP 406', json_encode($v));

$v = emmcpClassifyDiscoveryAnswer(403, '', '', $mcp);
$check('403 is reported as blocked', $v['status'] === 'blocked', json_encode($v));

$v = emmcpClassifyDiscoveryAnswer(200, json_encode(array('resource' => 'https://other.example/mcp')), '', $mcp);
$check('200 naming another resource is unexpected, with the value', $v['status'] === 'unexpected' && strpos($v['detail'], 'other.example') !== false, json_encode($v));

$v = emmcpClassifyDiscoveryAnswer(404, '', '', $mcp);
$check('404 is unexpected', $v['status'] === 'unexpected' && $v['detail'] === 'HTTP 404', json_encode($v));

$v = emmcpClassifyDiscoveryAnswer(200, '<html>login</html>', '', $mcp);
$check('200 with HTML is unexpected', $v['status'] === 'unexpected', json_encode($v));

$v = emmcpClassifyDiscoveryAnswer(0, '', 'Could not resolve host', $mcp);
$check('transport error is unreachable', $v['status'] === 'unreachable' && $v['detail'] === 'Could not resolve host', json_encode($v));

echo $failures === 0 ? "PASSED\n" : "FAILED: $failures\n";
exit($failures === 0 ? 0 : 1);
