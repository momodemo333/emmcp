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
 * \file    admin/diagnostic.php
 * \ingroup emmcp
 * \brief   What blocks a connection: checks, connection log, report for support.
 *
 * Built for the moment an AI client says "authorization failed" and hands the
 * user a reference only its vendor can read. Every check here is read-only,
 * and the support report carries no secret: no key, token or password.
 */

$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Main include failed");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/emmcp/lib/emmcp.lib.php');
dol_include_once('/emmcp/lib/emmcp_bootstrap.php');
dol_include_once('/emmcp/lib/emmcp_runtime.php');
dol_include_once('/emmcp/class/emmcpmigrations.class.php');
dol_include_once('/emmcp/class/emmcpauthlog.class.php');
dol_include_once('/emmcp/core/modules/modEmmcp.class.php');

$langs->loadLangs(array('admin', 'users', 'emmcp@emmcp'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

// Same entry point as mcp.php: an admin looking here after a files-only
// upgrade gets the schema brought up to date first.
EmmcpMigrations::runIfNeeded($db);

if ($action == 'clearruntime') {
	emmcp_runtime_clear_error();
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

$probe = null;
$uaProbe = null;
if ($action == 'probe') {
	$probe = emmcpProbeAuthorizationHeader();
	$uaProbe = emmcpProbeDiscoveryUserAgent(emmcpPublicUrl('/emmcp/oauth.php'), emmcpPublicUrl('/emmcp/mcp.php'));
}

/*
 * Gather
 */

$checks = array(); // each: label, status (ok|warning|error|info), value, help

$module = new modEmmcp($db);
$migrations = new EmmcpMigrations($db);
$storedSchema = $migrations->getStoredVersion();

$checks[] = array('label' => $langs->trans('EmmcpDiagModuleVersion'), 'status' => 'info', 'value' => $module->version, 'help' => '');
$checks[] = array(
	'label' => $langs->trans('EmmcpDiagSchema'),
	'status' => $migrations->isUpgradeNeeded() ? 'error' : 'ok',
	'value' => ($storedSchema !== '' ? $storedSchema : '-').' / '.EmmcpMigrations::MODULE_VERSION,
	'help' => $migrations->isUpgradeNeeded() ? $langs->trans('EmmcpDiagSchemaHelp') : '',
);
$checks[] = array('label' => 'Dolibarr / PHP', 'status' => 'info', 'value' => DOL_VERSION.' / '.PHP_VERSION, 'help' => '');
$checks[] = array(
	'label' => $langs->trans('EmmcpDiagApiModule'),
	'status' => isModEnabled('api') ? 'ok' : 'error',
	'value' => isModEnabled('api') ? $langs->trans('Enabled') : $langs->trans('Disabled'),
	'help' => isModEnabled('api') ? '' : $langs->trans('EmmcpDiagApiModuleHelp'),
);

// Runtime and its dependencies
$autoload = emmcpFindMcpAutoloader();
$runtimeDir = $autoload ? emmcp_runtime_package_dir($autoload) : '';
$runtimeVersion = '';
if ($runtimeDir !== '' && is_file($runtimeDir.'/src/Config/Version.php')) {
	if (preg_match("/const SERVER = '([^']+)'/", (string) file_get_contents($runtimeDir.'/src/Config/Version.php'), $m)) {
		$runtimeVersion = $m[1];
	}
}
$checks[] = array(
	'label' => $langs->trans('EmmcpDiagRuntime'),
	'status' => $autoload ? 'ok' : 'error',
	'value' => $autoload ? ($runtimeVersion !== '' ? $runtimeVersion.' — ' : '').emmcp_runtime_short_path($runtimeDir) : $langs->trans('EmmcpDiagRuntimeMissing'),
	'help' => '',
);
$prefix = $runtimeDir !== '' ? emmcp_runtime_prefix($runtimeDir) : '';
$checks[] = array(
	'label' => $langs->trans('EmmcpDiagIsolation'),
	'status' => $prefix !== '' ? 'ok' : 'warning',
	'value' => $prefix !== '' ? $langs->trans('EmmcpDiagIsolationOn', $prefix) : $langs->trans('EmmcpDiagIsolationOff'),
	'help' => $prefix !== '' ? '' : $langs->trans('EmmcpDiagIsolationHelp'),
);
// Read-only here: only what this page already loaded is inspected, nothing
// is loaded, so the check cannot itself trigger the fatal error it looks for.
$conflicts = $runtimeDir !== '' ? emmcp_runtime_conflicts($runtimeDir, false) : array();
$checks[] = array(
	'label' => $langs->trans('EmmcpDiagConflicts'),
	'status' => empty($conflicts) ? 'ok' : 'error',
	'value' => empty($conflicts) ? $langs->trans('EmmcpDiagNoConflict') : emmcp_runtime_conflict_message($conflicts[0]),
	'help' => empty($conflicts) ? '' : $langs->trans('EmmcpDiagConflictsHelp'),
);
$runtimeError = emmcp_runtime_last_error();
$checks[] = array(
	'label' => $langs->trans('EmmcpDiagLastRuntimeError'),
	'status' => $runtimeError ? 'error' : 'ok',
	'value' => $runtimeError
		? dol_print_date((int) $runtimeError['date'], 'dayhour').' — '.$runtimeError['message'].($runtimeError['file'] !== '' ? ' ('.$runtimeError['file'].':'.$runtimeError['line'].')' : '')
		: $langs->trans('None'),
	'help' => $runtimeError ? $langs->trans('EmmcpDiagLastRuntimeErrorHelp') : '',
	'clear' => (bool) $runtimeError,
);

// OAuth
$issuer = emmcpPublicUrl('/emmcp/oauth.php');
$mcpUrl = emmcpPublicUrl('/emmcp/mcp.php');
$checks[] = array(
	'label' => $langs->trans('EmmcpDiagMcpUrl'),
	'status' => strpos($mcpUrl, 'https://') === 0 ? 'ok' : 'warning',
	'value' => $mcpUrl,
	'help' => strpos($mcpUrl, 'https://') === 0 ? '' : $langs->trans('EmmcpDiagHttpsHelp'),
);
$checks[] = array('label' => $langs->trans('EmmcpDiagIssuer'), 'status' => 'info', 'value' => $issuer, 'help' => '');

$grantProblems = array();
$nbGrants = 0;
$nbClients = 0;
if (emmcp_mcp_oauth_autoload() !== null) {
	$oauthServer = new \DolibarrMcpOAuth\OAuthServer($db, new \DolibarrMcpOAuth\ExposureConfig('emmcp', 'emcp_', 'EMMCP'));
	$grants = $oauthServer->listGrants();
	$nbGrants = count($grants);
	foreach ($grants as $g) {
		if ($oauthServer->getUserApiKey((int) $g->fk_user) === null) {
			$grantProblems[] = $langs->trans('EmmcpDiagGrantNoKey', dol_escape_htmltag((string) $g->login), dol_escape_htmltag((string) ($g->client_name ?: $g->client_id)));
		}
	}
	$resql = $db->query("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."emmcp_oauth_client WHERE entity = ".((int) $conf->entity));
	if ($resql && ($obj = $db->fetch_object($resql))) {
		$nbClients = (int) $obj->nb;
	}
}
$checks[] = array(
	'label' => $langs->trans('EmmcpDiagGrants'),
	'status' => empty($grantProblems) ? 'info' : 'error',
	'value' => $langs->trans('EmmcpDiagGrantsValue', $nbGrants, $nbClients).(empty($grantProblems) ? '' : '<br>'.implode('<br>', $grantProblems)),
	'help' => empty($grantProblems) ? '' : $langs->trans('EmmcpDiagGrantNoKeyHelp'),
	'html' => true,
);

if ($probe !== null) {
	$checks[] = array(
		'label' => $langs->trans('EmmcpProbeAuth'),
		'status' => $probe['status'] === 'seen' ? 'ok' : ($probe['status'] === 'lost' ? 'error' : 'warning'),
		'value' => $probe['status'] === 'seen' ? $langs->trans('EmmcpProbeAuthOk')
			: ($probe['status'] === 'lost' ? $langs->trans('EmmcpDiagProbeLost') : $langs->trans('EmmcpProbeAuthUnreachable', $probe['detail'])),
		'help' => '',
	);
}

// ChatGPT follows the WWW-Authenticate header like every other client, but
// its discovery requests carry an aiohttp User-Agent that some hosting
// firewalls (ModSecurity) refuse with 403/406 while letting browsers through.
if ($uaProbe !== null) {
	$ua = dol_escape_htmltag(emmcpChatgptDiscoveryUserAgent());
	$line = dol_escape_htmltag($uaProbe['url']).($uaProbe['detail'] !== '' ? ' <span class="opacitymedium">('.dol_escape_htmltag($uaProbe['detail']).')</span>' : '');
	if ($uaProbe['status'] === 'ok') {
		$value = $langs->trans('EmmcpDiagUaProbeOk', $ua).'<br>'.$line;
	} elseif ($uaProbe['status'] === 'blocked') {
		$value = $langs->trans('EmmcpDiagUaProbeBlocked', $ua).'<br>'.$line;
	} elseif ($uaProbe['status'] === 'unexpected') {
		$value = $langs->trans('EmmcpDiagUaProbeUnexpected', $ua).'<br>'.$line;
	} else {
		$value = $langs->trans('EmmcpDiagUaProbeUnreachable', dol_escape_htmltag($uaProbe['detail']));
	}
	$checks[] = array(
		'label' => $langs->trans('EmmcpDiagUaProbe'),
		'status' => $uaProbe['status'] === 'ok' ? 'ok' : 'warning',
		'value' => $value,
		'help' => $uaProbe['status'] === 'blocked' ? dol_escape_htmltag($langs->transnoentities('EmmcpDiagUaProbeHelp', $uaProbe['url'])) : '',
		'html' => true,
	);
}

$events = EmmcpAuthLog::fetchRecent($db, 50);

/*
 * View
 */

llxHeader('', $langs->trans('EmmcpDiagTab'));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('EmmcpSetup'), $linkback, 'title_setup');

$head = emmcpAdminPrepareHead();
print dol_get_fiche_head($head, 'diagnostic', $langs->trans('EmmcpSetup'), -1, 'technic');

print '<span class="opacitymedium">'.$langs->trans('EmmcpDiagIntro').'</span><br><br>';

$badge = array('ok' => 'badge-status4', 'warning' => 'badge-status1', 'error' => 'badge-status8', 'info' => 'badge-status0');
$badgeLabel = array('ok' => 'OK', 'warning' => $langs->trans('Warning'), 'error' => $langs->trans('Error'), 'info' => '');

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('EmmcpDiagCheck').'</td><td class="center" style="width: 90px;"></td><td>'.$langs->trans('Value').'</td></tr>';
foreach ($checks as $c) {
	print '<tr class="oddeven">';
	print '<td class="tdtop">'.dol_escape_htmltag($c['label']).'</td>';
	print '<td class="center tdtop">'.($badgeLabel[$c['status']] !== '' ? '<span class="badge '.$badge[$c['status']].' badge-status">'.$badgeLabel[$c['status']].'</span>' : '').'</td>';
	print '<td class="tdtop">'.(!empty($c['html']) ? $c['value'] : dol_escape_htmltag((string) $c['value']));
	if ($c['help'] !== '') {
		print '<br><span class="opacitymedium small">'.$c['help'].'</span>';
	}
	if (!empty($c['clear'])) {
		print ' &nbsp; <a class="button smallpaddingimp" href="'.$_SERVER['PHP_SELF'].'?action=clearruntime&token='.newToken().'">'.$langs->trans('EmmcpDiagClear').'</a>';
	}
	print '</td></tr>';
}
print '</table>';
print '</div>';

print '<br><a class="button smallpaddingimp" href="'.$_SERVER['PHP_SELF'].'?action=probe&token='.newToken().'">'.$langs->trans('EmmcpDiagRunProbe').'</a>';
print ' <span class="opacitymedium small">'.$langs->trans('EmmcpDiagRunProbeHelp').'</span>';

// Connection log
print '<br><br>';
print load_fiche_titre($langs->trans('EmmcpDiagLog'), '', '');
print '<span class="opacitymedium">'.$langs->trans('EmmcpDiagLogIntro').'</span><br><br>';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Date').'</td><td>'.$langs->trans('EmmcpDiagEvent').'</td><td class="center">'.$langs->trans('EmmcpDiagResult').'</td>';
print '<td>'.$langs->trans('EmmcpDiagReason').'</td><td>'.$langs->trans('EmmcpAccessClient').'</td><td>'.$langs->trans('User').'</td><td>IP</td>';
print '</tr>';
if (empty($events)) {
	print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium">'.$langs->trans('EmmcpDiagLogEmpty').'</span></td></tr>';
}
foreach ($events as $e) {
	print '<tr class="oddeven">';
	print '<td class="nowraponall">'.dol_print_date($db->jdate($e->datec), 'dayhoursec').'</td>';
	print '<td class="nowraponall">'.dol_escape_htmltag($langs->trans('EmmcpDiagEvent_'.$e->event) !== 'EmmcpDiagEvent_'.$e->event ? $langs->trans('EmmcpDiagEvent_'.$e->event) : $e->event).'</td>';
	print '<td class="center">'.($e->success ? '<span class="badge badge-status4 badge-status">OK</span>' : '<span class="badge badge-status8 badge-status">'.$langs->trans('Error').'</span>').'</td>';
	print '<td>'.dol_escape_htmltag((string) $e->reason).'</td>';
	print '<td class="tdoverflowmax150" title="'.dol_escape_htmltag((string) $e->client_id).'">'.dol_escape_htmltag((string) ($e->client_name ?: $e->client_id)).'</td>';
	print '<td>'.dol_escape_htmltag((string) $e->login).'</td>';
	print '<td class="nowraponall">'.dol_escape_htmltag((string) $e->ip).'</td>';
	print '</tr>';
}
print '</table>';
print '</div>';

// Report for support: plain text, nothing secret.
$report = array();
$report[] = 'emMCP diagnostic report — '.dol_print_date(dol_now(), 'dayhour', 'gmt').' UTC';
foreach ($checks as $c) {
	$report[] = '- '.$c['label'].': ['.strtoupper($c['status']).'] '.dol_string_nohtmltag(str_replace('<br>', ' | ', (string) $c['value']));
}
$report[] = '';
$report[] = 'Connection log (latest first):';
foreach (array_slice($events, 0, 30) as $e) {
	$report[] = dol_print_date($db->jdate($e->datec), 'dayhoursec').' '.$e->event.' '.($e->success ? 'OK' : 'FAIL').' '.(string) $e->reason
		.($e->client_name || $e->client_id ? ' [client '.($e->client_name ?: $e->client_id).']' : '')
		.($e->login ? ' [user '.$e->login.']' : '');
}

print '<br><br>';
print load_fiche_titre($langs->trans('EmmcpDiagReport'), '', '');
print '<span class="opacitymedium">'.$langs->trans('EmmcpDiagReportHelp').'</span><br>';
print '<textarea class="quatrevingtpercent" rows="14" readonly onclick="this.select()" style="font-family: monospace; font-size: 0.9em;">'.dol_escape_htmltag(implode("\n", $report), 0, 1).'</textarea>';

print dol_get_fiche_end();

llxFooter();
$db->close();
