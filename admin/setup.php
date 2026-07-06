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
 * \file    admin/setup.php
 * \ingroup emmcp
 * \brief   emMCP setup page: endpoint URL, status, client examples
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

$langs->loadLangs(array('admin', 'emmcp@emmcp'));

if (!$user->admin) {
	accessforbidden();
}

$mcpEndpoint = emmcpPublicUrl('/emmcp/mcp.php'); // full URL (https)
$oauthMetadata = emmcpPublicUrl('/emmcp/oauth.php').'/.well-known/oauth-authorization-server';
$autoload = emmcpFindMcpAutoloader();
$apiEnabled = isModEnabled('api');

/*
 * View
 */

llxHeader('', $langs->trans('EmmcpSetup'));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('EmmcpSetup'), $linkback, 'title_setup');

$head = emmcpAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('EmmcpName'), -1, 'technic');

// Lightweight styling for the code blocks
print '<style>
.emmcp-code{background:var(--colorbacklinepair2,#f6f6f6);border:1px solid var(--bordercolor,#e0e0e0);border-radius:5px;padding:10px 12px;margin:6px 0 14px;overflow-x:auto;white-space:pre;font-size:0.85em;line-height:1.45;}
.emmcp-step{margin:0 0 22px;}
.emmcp-step h3{margin:0 0 4px;font-size:1.05em;}
</style>';

print '<span class="opacitymedium">'.$langs->trans('EmmcpSetupIntro').'</span><br><br>';

// Status board
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('EmmcpEndpoint').'</td>';
print '<td><strong>'.showValueWithClipboardCPButton($mcpEndpoint, 1, $mcpEndpoint).'</strong></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('EmmcpTransport').'</td>';
print '<td>Streamable HTTP (MCP) — POST JSON-RPC 2.0</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('EmmcpAuthMethods').'</td>';
print '<td>'.$langs->trans('EmmcpAuthMethodsValue').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('EmmcpOAuthMetadata').'</td>';
print '<td>'.showValueWithClipboardCPButton($oauthMetadata, 1, $oauthMetadata).'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('EmmcpServerPackage').'</td>';
print '<td>'.($autoload ? img_picto('', 'tick').' '.$langs->trans('EmmcpServerPackageOk') : img_picto('', 'error').' '.$langs->trans('EmmcpServerPackageMissing')).'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('EmmcpRestApiModule').'</td>';
print '<td>'.($apiEnabled ? img_picto('', 'tick').' '.$langs->trans('Enabled') : img_picto('', 'error').' '.$langs->trans('EmmcpRestApiMissing')).'</td></tr>';

print '</table>';
print '</div>';

print '<br>';

// Client configuration examples
print load_fiche_titre($langs->trans('EmmcpClientExamples'), '', '');

// --- Option 1: claude.ai custom connector (OAuth, recommended) ---
print '<div class="emmcp-step">';
print '<h3>'.img_picto('', 'fa-plug', 'class="pictofixedwidth"').$langs->trans('EmmcpClientClaudeAi').' <span class="badge badge-status4 badge-status">'.$langs->trans('EmmcpRecommended').'</span></h3>';
print '<div class="opacitymedium">'.$langs->trans('EmmcpConnectorOAuthHelp').'</div>';
print emmcpCodeBlock($mcpEndpoint, $langs->trans('EmmcpConnectorUrlLabel'));
print '</div>';

// --- Option 2: Claude Code (API key) ---
$claudeCodeCmd = 'claude mcp add dolibarr --transport http '.$mcpEndpoint.' --header "Authorization: Bearer '.$langs->trans('EmmcpYourApiKey').'"';
print '<div class="emmcp-step">';
print '<h3>'.img_picto('', 'fa-terminal', 'class="pictofixedwidth"').$langs->trans('EmmcpClientClaudeCode').'</h3>';
print '<div class="opacitymedium">'.$langs->trans('EmmcpAuthNote').'</div>';
print emmcpCodeBlock($claudeCodeCmd, $langs->trans('EmmcpCommandLabel'));
print '</div>';

// --- Option 3: generic MCP client (mcp.json) ---
$mcpJson = json_encode(array(
	'mcpServers' => array(
		'dolibarr' => array(
			'type' => 'http',
			'url' => $mcpEndpoint,
			'headers' => array('Authorization' => 'Bearer '.$langs->trans('EmmcpYourApiKey')),
		),
	),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
print '<div class="emmcp-step">';
print '<h3>'.img_picto('', 'fa-file-code', 'class="pictofixedwidth"').$langs->trans('EmmcpClientGeneric').'</h3>';
print emmcpCodeBlock($mcpJson, 'mcp.json');
print '</div>';

print '<div class="info">'.$langs->trans('EmmcpApiKeyHelp').'</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
