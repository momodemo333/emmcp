<?php

/*
 * Root OAuth discovery: the URLs a client builds itself (RFC 9728 / RFC 8414
 * path insertion) and the rewrite rules the Diagnostic tab hands out.
 *
 * Plain PHP, no Dolibarr needed:
 *   php tests/root_discovery_test.php
 *   php tests/root_discovery_test.php --emit apache|nginx <mcp url> <issuer>
 *
 * The rules themselves are proved against a real Apache behind nginx
 * (Plesk layout) with --emit; see the README, section ChatGPT.
 */

require __DIR__.'/../lib/emmcp.lib.php';

if (($argv[1] ?? '') === '--emit') {
	print emmcpRootDiscoveryRules($argv[3], $argv[4])[$argv[2]];
	exit(0);
}

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
	echo ($ok ? '  OK   ' : '  FAIL ').$label.($ok ? '' : "\n         ".$detail)."\n";
	$failures += $ok ? 0 : 1;
};

echo "Root OAuth discovery\n";

$u = emmcpRootDiscoveryUrls('https://compta.example.ch/custom/emmcp/mcp.php', 'https://compta.example.ch/custom/emmcp/oauth.php');
$check('protected resource URL is path-inserted', $u['prm'] === 'https://compta.example.ch/.well-known/oauth-protected-resource/custom/emmcp/mcp.php', $u['prm']);
$check('authorization server URL is path-inserted', $u['as'] === 'https://compta.example.ch/.well-known/oauth-authorization-server/custom/emmcp/oauth.php', $u['as']);

$u = emmcpRootDiscoveryUrls('https://example.com:8443/erp/htdocs/custom/emmcp/mcp.php', 'https://example.com:8443/erp/htdocs/custom/emmcp/oauth.php');
$check('sub-path and port are kept', $u['prm'] === 'https://example.com:8443/.well-known/oauth-protected-resource/erp/htdocs/custom/emmcp/mcp.php', $u['prm']);

$r = emmcpRootDiscoveryRules('https://example.com/erp/custom/emmcp/mcp.php', 'https://example.com/erp/custom/emmcp/oauth.php');
$check('apache rule targets the issuer by absolute path', strpos($r['apache'], ' /erp/custom/emmcp/oauth.php/.well-known/oauth-protected-resource [L]') !== false, $r['apache']);
$check('apache rule escapes the dots of the path', strpos($r['apache'], '(/erp/custom/emmcp/mcp\.php)?') !== false, $r['apache']);
$check('nginx rule rewrites internally (last), never redirects', substr_count($r['nginx'], ' last;') === 2 && strpos($r['nginx'], 'return 30') === false, $r['nginx']);
$check('acme-challenge is never matched', !preg_match('#acme#', $r['apache'].$r['nginx']));

// The patterns, read as PCRE, match what they must and nothing else
$pattern = '#^\.well-known/(oauth-authorization-server|openid-configuration)(/erp/custom/emmcp/oauth\.php)?/?$#';
foreach (array('.well-known/oauth-authorization-server/erp/custom/emmcp/oauth.php' => 1, '.well-known/openid-configuration' => 1,
	'.well-known/acme-challenge/x' => 0, '.well-known/oauth-authorization-server/other' => 0, '.well-known/openid-configuration/erp/custom/emmcp/oauthXphp' => 0) as $path => $expected) {
	$check('pattern '.($expected ? 'matches ' : 'ignores ').$path, preg_match($pattern, $path) === $expected);
}

echo $failures === 0 ? "PASSED\n" : "FAILED: $failures\n";
exit($failures === 0 ? 0 : 1);
