<?php
/* Copyright (C) 2026 Morgan Demoulin <morgan@e-dem.com>
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
 * \file    admin/mcp_access.php
 * \ingroup emmcp
 * \brief   Accesses granted through OAuth: who connected which client, and
 *          the button that ends it. Also the self-test of the Authorization
 *          header, the first thing to check when a client signs in and then
 *          gets a 401.
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
require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
dol_include_once('/emmcp/lib/emmcp.lib.php');
dol_include_once('/emmcp/lib/emmcp_bootstrap.php');

$langs->loadLangs(array('admin', 'users', 'emmcp@emmcp'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

$oauthServer = null;
if (emmcp_mcp_oauth_autoload() !== null) {
	$oauthServer = new \DolibarrMcpOAuth\OAuthServer($db, new \DolibarrMcpOAuth\ExposureConfig('emmcp', 'emcp_', 'EMMCP'));
}

// --- Revoke -----------------------------------------------------------------
if ($action == 'revoke' && $oauthServer !== null) {
	$clientRowid = GETPOSTINT('client');
	$userId = GETPOSTINT('userid');
	$nb = $oauthServer->revokeGrant($clientRowid, $userId);
	if ($nb < 0) {
		setEventMessages($oauthServer->error, null, 'errors');
	} else {
		dol_syslog('[EMMCP] OAuth access of client '.$clientRowid.' for user '.$userId.' revoked by '.$user->login, LOG_NOTICE);
		setEventMessages($langs->trans('EmmcpAccessRevoked'), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// --- Authorization header self-test ------------------------------------------
$probe = '';
$probeDetail = '';
if ($action == 'probe') {
	$probeUrl = emmcpPublicUrl('/emmcp/mcp.php');
	// Local addresses are allowed on purpose: the server calls its own URL.
	$r = getURLContent($probeUrl, 'POST', '{}', 1, array('Content-Type: application/json', 'Authorization: Bearer emmcp-probe', 'X-Dolibarr-Probe: 1'), array('http', 'https'), 2, -1);
	$json = (empty($r['curl_error_no']) && !empty($r['content'])) ? json_decode($r['content'], true) : null;
	if (is_array($json) && isset($json['authorization_seen'])) {
		$probe = $json['authorization_seen'] ? 'seen' : 'lost';
	} else {
		$probe = 'unreachable';
		$probeDetail = !empty($r['curl_error_msg']) ? $r['curl_error_msg'] : 'HTTP '.(isset($r['http_code']) ? $r['http_code'] : '?');
	}
}

// --- View ---------------------------------------------------------------------
llxHeader('', $langs->trans('EmmcpAccessTab'));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('EmmcpSetup'), $linkback, 'title_setup');

$head = emmcpAdminPrepareHead();
print dol_get_fiche_head($head, 'mcpaccess', $langs->trans('EmmcpSetup'), -1, 'technic');

print '<span class="opacitymedium">'.$langs->trans('EmmcpAccessIntro').'</span><br><br>';

// Granted accesses
print load_fiche_titre($langs->trans('EmmcpAccessGranted'), '', '');
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('EmmcpAccessClient').'</td>';
print '<td>'.$langs->trans('User').'</td>';
print '<td class="center">'.$langs->trans('EmmcpAccessSince').'</td>';
print '<td class="center">'.$langs->trans('EmmcpAccessLastRenewal').'</td>';
print '<td class="center">'.$langs->trans('EmmcpAccessExpires').'</td>';
print '<td class="right"></td>';
print '</tr>';

$grants = ($oauthServer !== null) ? $oauthServer->listGrants() : array();
if (empty($grants)) {
	print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans('EmmcpAccessNone').'</span></td></tr>';
}
foreach ($grants as $g) {
	$name = $g->client_name !== '' && $g->client_name !== null ? $g->client_name : $g->client_id;
	print '<tr class="oddeven">';
	print '<td>'.dol_escape_htmltag($name).'</td>';
	print '<td>'.dol_escape_htmltag((string) $g->login).'</td>';
	print '<td class="center">'.dol_print_date($db->jdate($g->granted), 'dayhour').'</td>';
	print '<td class="center">'.dol_print_date($db->jdate($g->last_used), 'dayhour').'</td>';
	print '<td class="center">'.dol_print_date($db->jdate($g->expires), 'dayhour').'</td>';
	print '<td class="right">';
	print '<a class="button smallpaddingimp" href="'.$_SERVER['PHP_SELF'].'?action=revoke&client='.((int) $g->fk_client).'&userid='.((int) $g->fk_user).'&token='.newToken().'">'.$langs->trans('EmmcpAccessRevoke').'</a>';
	print '</td>';
	print '</tr>';
}
print '</table>';
print '</div>';
print '<span class="opacitymedium small">'.$langs->trans('EmmcpAccessRevokeHelp').'</span>';

// Authorization header
print '<br><br>';
print load_fiche_titre($langs->trans('EmmcpProbeAuth'), '', '');
print '<span class="opacitymedium">'.$langs->trans('EmmcpProbeAuthHelp').'</span><br><br>';
print '<a class="button smallpaddingimp" href="'.$_SERVER['PHP_SELF'].'?action=probe&token='.newToken().'">'.$langs->trans('EmmcpProbeAuthRun').'</a>';
if ($probe == 'seen') {
	print ' &nbsp; <span class="badge badge-status4 badge-status">'.$langs->trans('EmmcpProbeAuthOk').'</span>';
} elseif ($probe == 'lost') {
	print '<div class="warning" style="margin-top: 8px;">'.$langs->trans('EmmcpProbeAuthLost').'<br>';
	print '<code>CGIPassAuth On</code> &nbsp;<span class="opacitymedium">'.$langs->trans('EmmcpProbeAuthApache').'</span><br>';
	print '<code>SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1</code> &nbsp;<span class="opacitymedium">'.$langs->trans('EmmcpProbeAuthApacheAlt').'</span><br>';
	print '<code>fastcgi_param HTTP_AUTHORIZATION $http_authorization;</code> &nbsp;<span class="opacitymedium">'.$langs->trans('EmmcpProbeAuthNginx').'</span>';
	print '</div>';
} elseif ($probe == 'unreachable') {
	print ' &nbsp; <span class="opacitymedium">'.$langs->trans('EmmcpProbeAuthUnreachable', dol_escape_htmltag($probeDetail)).'</span>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
