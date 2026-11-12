<?php
/* Copyright (C) 2026 E-dem — GPL-3.0-or-later */

/**
 * \file    tests/integration_oauth_purge.php
 * \ingroup emmcp
 * \brief   The purge drops abandoned registrations, never an idle client the AI still uses.
 *
 * Until dolibarr-mcp-oauth 1.2.0 a client left idle for a month lost its token
 * rows, then was deleted with them; the AI client kept its client_id and every
 * new sign-in failed with "unknown client_id". Runs on the real database since
 * the purge is a DELETE with a subquery the fake database cannot express.
 *
 * Run: make php s=/var/www/html/custom/emmcp/tests/integration_oauth_purge.php
 */

define('NOLOGIN', '1');
define('NOCSRFCHECK', '1');
define('NOREQUIREMENU', '1');
define('NOREQUIREHTML', '1');
define('NOSESSION', '1');

$res = @include "/var/www/html/main.inc.php";
if (!$res) {
	die("Main include failed\n");
}
dol_include_once('/emmcp/lib/emmcp_bootstrap.php');
emmcp_mcp_oauth_autoload();

$failures = 0;
function check($label, $ok)
{
	global $failures;
	print ($ok ? '  OK   ' : '  FAIL ').$label."\n";
	if (!$ok) {
		$failures++;
	}
}

$t = MAIN_DB_PREFIX.'emmcp_oauth_client';
$tag = 'emcp_client_purgetest_';
$insert = function ($suffix, $ageDays) use ($db, $t, $tag) {
	$db->query("INSERT INTO ".$t." (client_id, redirect_uris, token_endpoint_auth_method, entity, datec)"
		." VALUES ('".$tag.$suffix."', '[\"https://claude.ai/api/mcp/auth_callback\"]', 'none', 1, '".$db->idate(dol_now() - $ageDays * 86400)."')");
};
$exists = function ($suffix) use ($db, $t, $tag) {
	$resql = $db->query("SELECT rowid FROM ".$t." WHERE client_id = '".$tag.$suffix."'");

	return $resql && $db->num_rows($resql) === 1;
};

$db->query("DELETE FROM ".$t." WHERE client_id LIKE '".$tag."%'");
$insert('fresh', 0);      // registered just now, consent may still come
$insert('abandoned', 3);  // never got a token
$insert('idle', 45);      // used once, tokens long purged

$server = new \DolibarrMcpOAuth\OAuthServer($db, new \DolibarrMcpOAuth\ExposureConfig('emmcp', 'emcp_', 'EMMCP'));
$server->purgeExpired();

check('a registration of the day is kept', $exists('fresh'));
check('an abandoned registration (3 days, no token) is purged', !$exists('abandoned'));
check('an idle client (45 days, tokens purged) is kept', $exists('idle'));

$db->query("DELETE FROM ".$t." WHERE client_id LIKE '".$tag."%'");

print $failures === 0 ? "PASSED\n" : "FAILED: $failures\n";
exit($failures === 0 ? 0 : 1);
