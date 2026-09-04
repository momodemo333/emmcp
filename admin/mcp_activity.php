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
 * \file    admin/mcp_activity.php
 * \ingroup emmcp
 * \brief   MCP call history, rate limit and alerting.
 *
 * One page rather than three, because they answer one question in sequence:
 * what has been called, is anyone calling too much, and who should hear about
 * it. An administrator investigating usage should not have to navigate.
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
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
dol_include_once('/emmcp/lib/emmcp.lib.php');
dol_include_once('/emmcp/lib/emmcp_bootstrap.php');
dol_include_once('/emmcp/class/emmcpmigrations.class.php');

$langs->loadLangs(array('admin', 'users', 'other', 'emmcp@emmcp'));

if (!$user->admin) {
	accessforbidden();
}

$schemaReady = EmmcpMigrations::runIfNeeded($db);

$libLoaded = (emmcp_mcp_audit_autoload() !== null);
$config = $libLoaded ? emmcp_mcp_audit_config() : null;

$action = GETPOST('action', 'aZ09');

// --- Settings ---------------------------------------------------------------

if ($action == 'update' && $user->admin) {
	// An unticked checkbox posts nothing, so these two are read as 0/1 rather
	// than left absent — otherwise turning logging off would silently do nothing.
	$settings = array(
		'LOG_ENABLED' => GETPOSTINT('log_enabled') ? 1 : 0,
		'LOG_ARGUMENTS' => GETPOSTINT('log_arguments') ? 1 : 0,
		'LOG_RETENTION_DAYS' => GETPOSTINT('retention'),
		'RATE_LIMIT' => GETPOSTINT('rate_limit'),
		'RATE_WINDOW' => GETPOSTINT('rate_window'),
		'ALERT_THRESHOLD' => GETPOSTINT('alert_threshold'),
		'ALERT_COOLDOWN' => GETPOSTINT('alert_cooldown'),
	);
	foreach ($settings as $suffix => $value) {
		dolibarr_set_const($db, 'EMMCP_MCP_'.$suffix, (string) max(0, (int) $value), 'chaine', 0, '', $conf->entity);
	}

	$email = trim((string) GETPOST('alert_email', 'alphanohtml'));
	if ($email === '' || isValidEmail($email)) {
		dolibarr_set_const($db, 'EMMCP_MCP_ALERT_EMAIL', $email, 'chaine', 0, '', $conf->entity);
		setEventMessages($langs->trans('EmmcpMcpSettingsSaved'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('EmmcpMcpAlertEmailInvalid'), null, 'errors');
	}

	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action == 'purge' && $user->admin && $libLoaded) {
	$removed = (new \DolibarrMcpAudit\McpAudit($db, $config))->purge();
	if ($removed >= 0) {
		setEventMessages($langs->trans('EmmcpMcpPurged', $removed), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('EmmcpMcpPurgeFailed'), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// --- Page -------------------------------------------------------------------

llxHeader('', $langs->trans('EmmcpMcpActivityTab'));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('EmmcpSetup'), $linkback, 'title_setup');

$head = emmcpAdminPrepareHead();
print dol_get_fiche_head($head, 'mcpactivity', $langs->trans('EmmcpSetup'), -1, 'technic');

print '<span class="opacitymedium">'.$langs->trans('EmmcpMcpActivityIntro').'</span><br><br>';

if (!$libLoaded) {
	print '<div class="error" style="padding:12px;margin:10px 0;">'.$langs->trans('EmmcpMcpAuditMissing').'</div>';
	print dol_get_fiche_end();
	llxFooter();
	$db->close();
	exit;
}
if (!$schemaReady) {
	print '<div class="error" style="padding:12px;margin:10px 0;">'.$langs->trans('EmmcpMcpSchemaOutOfDate').'</div>';
}

$rateLimit = getDolGlobalInt('EMMCP_MCP_RATE_LIMIT');
$rateWindow = getDolGlobalInt('EMMCP_MCP_RATE_WINDOW') ?: 60;
$threshold = getDolGlobalInt('EMMCP_MCP_ALERT_THRESHOLD');

// --- Settings form ----------------------------------------------------------

print load_fiche_titre($langs->trans('EmmcpMcpSettings'), '', '');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('EmmcpMcpLogEnabled');
print '<br><span class="opacitymedium small">'.$langs->trans('EmmcpMcpLogEnabledDesc').'</span></td>';
print '<td><input type="checkbox" name="log_enabled" value="1"'.(getDolGlobalString('EMMCP_MCP_LOG_ENABLED') !== '0' ? ' checked' : '').'></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('EmmcpMcpLogArguments');
print '<br><span class="opacitymedium small">'.$langs->trans('EmmcpMcpLogArgumentsDesc').'</span></td>';
print '<td><input type="checkbox" name="log_arguments" value="1"'.(getDolGlobalString('EMMCP_MCP_LOG_ARGUMENTS') !== '0' ? ' checked' : '').'></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('EmmcpMcpRetention');
print '<br><span class="opacitymedium small">'.$langs->trans('EmmcpMcpRetentionDesc').'</span></td>';
print '<td><input type="number" min="1" max="3650" name="retention" value="'.(getDolGlobalInt('EMMCP_MCP_LOG_RETENTION_DAYS') ?: 90).'"> '.$langs->trans('days').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('EmmcpMcpRateLimit');
print '<br><span class="opacitymedium small">'.$langs->trans('EmmcpMcpRateLimitDesc').'</span></td>';
print '<td><input type="number" min="0" name="rate_limit" value="'.$rateLimit.'"> '.$langs->trans('EmmcpMcpCallsPer').' ';
print '<input type="number" min="1" max="1440" name="rate_window" value="'.$rateWindow.'"> '.$langs->trans('minutes').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('EmmcpMcpAlertThreshold');
print '<br><span class="opacitymedium small">'.$langs->trans('EmmcpMcpAlertThresholdDesc').'</span></td>';
print '<td><input type="number" min="0" name="alert_threshold" value="'.$threshold.'"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('EmmcpMcpAlertEmail');
print '<br><span class="opacitymedium small">'.$langs->trans('EmmcpMcpAlertEmailDesc').'</span></td>';
print '<td><input type="email" class="minwidth200" name="alert_email" value="'.dol_escape_htmltag(getDolGlobalString('EMMCP_MCP_ALERT_EMAIL')).'"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('EmmcpMcpAlertCooldown');
print '<br><span class="opacitymedium small">'.$langs->trans('EmmcpMcpAlertCooldownDesc').'</span></td>';
print '<td><input type="number" min="1" name="alert_cooldown" value="'.(getDolGlobalInt('EMMCP_MCP_ALERT_COOLDOWN') ?: 720).'"> '.$langs->trans('minutes').'</td></tr>';

print '<tr class="oddeven"><td colspan="2" class="center">';
print '<input type="submit" class="button button-save" value="'.$langs->trans('Save').'">';
print '</td></tr>';
print '</table></div></form>';

print '<br>';

// --- Per-user usage over the current window ---------------------------------

print load_fiche_titre($langs->trans('EmmcpMcpUsage'), '', '');
print '<span class="opacitymedium">'.$langs->trans('EmmcpMcpUsageIntro', $rateWindow).'</span><br><br>';

$since = dol_now() - ($rateWindow * 60);
$sql = "SELECT l.fk_user, u.login, COUNT(*) AS nb,";
$sql .= " SUM(CASE WHEN l.success = 0 THEN 1 ELSE 0 END) AS refused";
$sql .= " FROM ".MAIN_DB_PREFIX."emmcp_mcp_log as l";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = l.fk_user";
$sql .= " WHERE l.entity = ".((int) $conf->entity)." AND l.method = 'tools/call'";
$sql .= " AND l.date_creation >= '".$db->idate($since)."'";
$sql .= " GROUP BY l.fk_user, u.login ORDER BY nb DESC";

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('User').'</td>';
print '<td class="center">'.$langs->trans('EmmcpMcpToolCalls').'</td>';
print '<td class="center">'.$langs->trans('EmmcpMcpRefused').'</td>';
print '<td class="center">'.$langs->trans('Status').'</td></tr>';

$resql = $db->query($sql);
$rows = 0;
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$rows++;
		print '<tr class="oddeven"><td>'.dol_escape_htmltag($obj->login ?: ('#'.$obj->fk_user)).'</td>';
		print '<td class="center">'.((int) $obj->nb).'</td>';
		print '<td class="center">'.((int) $obj->refused ?: '-').'</td>';
		print '<td class="center">';
		if ($rateLimit > 0 && (int) $obj->nb >= $rateLimit) {
			print '<span class="badge badge-status8 badge-status">'.$langs->trans('EmmcpMcpAtLimit').'</span>';
		} elseif ($threshold > 0 && (int) $obj->nb >= $threshold) {
			print '<span class="badge badge-status1 badge-status">'.$langs->trans('EmmcpMcpAboveThreshold').'</span>';
		} else {
			print '<span class="opacitymedium">'.$langs->trans('EmmcpMcpNormal').'</span>';
		}
		print '</td></tr>';
	}
}
if ($rows === 0) {
	print '<tr class="oddeven"><td colspan="4" class="opacitymedium center">'.$langs->trans('EmmcpMcpNoUsage').'</td></tr>';
}
print '</table></div>';

print '<br>';

// --- Recent calls -----------------------------------------------------------

print load_fiche_titre($langs->trans('EmmcpMcpRecentCalls'), '', '');

$filterUser = GETPOSTINT('filter_user');
$filterTool = GETPOST('filter_tool', 'alphanohtml');

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" class="inline-block">';
print $langs->trans('User').' ';
print $form->select_dolusers($filterUser, 'filter_user', 1, null, 0, '', '', 0, 0, 0, '', 0, '', 'minwidth150');
print ' '.$langs->trans('EmmcpMcpTool').' <input type="text" name="filter_tool" value="'.dol_escape_htmltag($filterTool).'">';
print ' <input type="submit" class="button small" value="'.$langs->trans('Search').'">';
print ' <a class="button button-cancel small" href="'.$_SERVER['PHP_SELF'].'">'.$langs->trans('Reset').'</a>';
print '</form>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block" style="float:right">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="purge">';
print '<input type="submit" class="button small" value="'.$langs->trans('EmmcpMcpPurgeNow').'">';
print '</form>';

$sql = "SELECT l.rowid, l.fk_user, u.login, l.date_creation, l.method, l.tool_name,";
$sql .= " l.arguments, l.duration_ms, l.success, l.error_message, l.client_name";
$sql .= " FROM ".MAIN_DB_PREFIX."emmcp_mcp_log as l";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = l.fk_user";
$sql .= " WHERE l.entity = ".((int) $conf->entity);
if ($filterUser > 0) {
	$sql .= " AND l.fk_user = ".((int) $filterUser);
}
if ($filterTool !== '') {
	$sql .= " AND l.tool_name LIKE '%".$db->escape($filterTool)."%'";
}
$sql .= " ORDER BY l.rowid DESC LIMIT 100";

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td>';
print '<td>'.$langs->trans('User').'</td>';
print '<td>'.$langs->trans('EmmcpMcpMethod').'</td>';
print '<td>'.$langs->trans('EmmcpMcpTool').'</td>';
print '<td class="center">'.$langs->trans('EmmcpMcpDuration').'</td>';
print '<td class="center">'.$langs->trans('Status').'</td>';
print '<td>'.$langs->trans('EmmcpMcpArguments').'</td></tr>';

$resql = $db->query($sql);
$rows = 0;
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$rows++;
		print '<tr class="oddeven">';
		print '<td class="nowraponall">'.dol_print_date($db->jdate($obj->date_creation), 'dayhoursec').'</td>';
		print '<td>'.dol_escape_htmltag($obj->login ?: ('#'.$obj->fk_user)).'</td>';
		print '<td>'.dol_escape_htmltag($obj->method).'</td>';
		print '<td>'.dol_escape_htmltag($obj->tool_name ?: '-').'</td>';
		print '<td class="center">'.((int) $obj->duration_ms).' ms</td>';
		print '<td class="center">';
		if ((int) $obj->success === 1) {
			print img_picto($langs->trans('EmmcpMcpSuccess'), 'tick');
		} else {
			print '<span class="error" title="'.dol_escape_htmltag((string) $obj->error_message).'">';
			print dol_escape_htmltag(dol_trunc((string) $obj->error_message, 28));
			print '</span>';
		}
		print '</td>';
		print '<td><span class="opacitymedium small">'.dol_escape_htmltag(dol_trunc((string) $obj->arguments, 60)).'</span></td>';
		print '</tr>';
	}
}
if ($rows === 0) {
	print '<tr class="oddeven"><td colspan="7" class="opacitymedium center">'.$langs->trans('EmmcpMcpNoCalls').'</td></tr>';
}
print '</table></div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
