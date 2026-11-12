<?php

/*
 * Proves the embedded MCP runtime survives another module's copy of its
 * libraries, the way it failed in production in November 2026:
 *
 *   "Declaration of Psr\Log\NullLogger::log($level, Stringable|string $message,
 *    array $context = []): void must be compatible with
 *    Psr\Log\LoggerInterface::log($level, $message, array $context = [])"
 *
 * Each scenario runs in its own PHP process, since the failure being tested is
 * a fatal error nobody can catch.
 *
 * Usage (plain PHP, no Dolibarr needed; run by `make build-release`):
 *   php tests/runtime_isolation_test.php <runtime package dir> [<unscoped package dir>]
 *
 * <runtime package dir> must be a scoped build (vendor/emmcp-scope-prefix.txt).
 * With the optional unscoped package, the test first reproduces the original
 * fatal error on it, so a pass is known to mean something.
 */

$fixture = __DIR__.'/fixtures/psr-log-1/LoggerInterface.php';

// --- child scenarios -------------------------------------------------------------

if (($argv[1] ?? '') === '--child') {
	$mode = $argv[2];
	$dir = rtrim($argv[3], '/');

	// What another module does first: load its own psr/log 1.x interface, and
	// its own guzzle class under the very same name ours has unprefixed.
	require $fixture;
	if (!class_exists('GuzzleHttp\Psr7\ServerRequest', false)) {
		eval('namespace GuzzleHttp\Psr7; class ServerRequest { public static function fromGlobals() { throw new \RuntimeException("foreign guzzle used"); } }');
	}

	require $dir.'/vendor/autoload.php';

	if ($mode === 'unscoped') {
		new \Psr\Log\NullLogger(); // the production fatal error
		echo "NO-FATAL\n";
		exit(0);
	}

	$prefix = trim((string) @file_get_contents($dir.'/vendor/emmcp-scope-prefix.txt'));
	if ($prefix === '') {
		echo "NOT-SCOPED\n";
		exit(3);
	}
	$requestClass = $prefix.'\GuzzleHttp\Psr7\ServerRequest';
	$streamUtils = $prefix.'\GuzzleHttp\Psr7\Utils';
	$nullLogger = $prefix.'\Psr\Log\NullLogger';
	new $nullLogger();

	$sessionDir = sys_get_temp_dir().'/emmcp-isolation-'.getmypid();
	@mkdir($sessionDir);
	$body = json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array(
		'protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(),
		'clientInfo' => array('name' => 'isolation-test', 'version' => '1'),
	)));
	$request = (new $requestClass('POST', 'https://example.test/mcp.php', array(
		'Content-Type' => 'application/json',
		'Accept' => 'application/json, text/event-stream',
	)))->withBody($streamUtils::streamFor($body));

	$config = new \DolibarrMcp\Config\ConnectionConfig('https://example.test', 'test-key');
	$response = \DolibarrMcp\Bootstrap::handleHttpRequest($request, $sessionDir, $config);
	$payload = (string) $response->getBody();
	$sid = $response->getHeaderLine('Mcp-Session-Id');

	// tools/list exercises discovery (attributes, symfony/finder, json schema)
	$list = (new $requestClass('POST', 'https://example.test/mcp.php', array(
		'Content-Type' => 'application/json',
		'Accept' => 'application/json, text/event-stream',
		'Mcp-Session-Id' => $sid,
	)))->withBody($streamUtils::streamFor(json_encode(array('jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'))));
	$toolsPayload = (string) \DolibarrMcp\Bootstrap::handleHttpRequest($list, $sessionDir, $config)->getBody();

	array_map('unlink', glob($sessionDir.'/*') ?: array());
	@rmdir($sessionDir);

	$ok = $response->getStatusCode() === 200 && strpos($payload, 'serverInfo') !== false
		&& substr_count($toolsPayload, '"name"') > 5;
	echo $ok ? "SCOPED-OK\n" : "SCOPED-BAD ".$response->getStatusCode().' '.substr($payload, 0, 300).' | '.substr($toolsPayload, 0, 300)."\n";
	exit($ok ? 0 : 4);
}

// --- driver ----------------------------------------------------------------------

if ($argc < 2) {
	fwrite(STDERR, "Usage: php tests/runtime_isolation_test.php <scoped package dir> [<unscoped package dir>]\n");
	exit(2);
}

$failures = 0;
$run = function ($mode, $dir) {
	$cmd = escapeshellarg(PHP_BINARY).' -d display_errors=stderr -d error_reporting=-1 '.escapeshellarg(__FILE__)
		.' --child '.escapeshellarg($mode).' '.escapeshellarg($dir).' 2>&1';
	exec($cmd, $out, $code);

	return array($code, implode("\n", $out));
};
$check = function ($label, $ok, $detail = '') use (&$failures) {
	echo ($ok ? '  OK   ' : '  FAIL ').$label.($ok || $detail === '' ? '' : "\n         ".substr($detail, 0, 600))."\n";
	if (!$ok) {
		$failures++;
	}
};

echo "Runtime isolation against a foreign psr/log 1.x and guzzle\n";

if (isset($argv[2])) {
	list($code, $out) = $run('unscoped', $argv[2]);
	$check('unscoped runtime reproduces the production fatal error', $code !== 0 && strpos($out, 'must be compatible') !== false, $out);
}

list($code, $out) = $run('scoped', $argv[1]);
$check('scoped runtime initializes and lists its tools next to the foreign copies', $code === 0 && strpos($out, 'SCOPED-OK') !== false, $out);

echo $failures === 0 ? "PASSED\n" : "FAILED: $failures\n";
exit($failures === 0 ? 0 : 1);
