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
 * \ingroup dolimcp
 * \brief   DoliMCP setup page: endpoint URL, status, client examples
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
dol_include_once('/dolimcp/lib/dolimcp.lib.php');

$langs->loadLangs(array('admin', 'dolimcp@dolimcp'));

if (!$user->admin) {
	accessforbidden();
}

$mcpEndpoint = dol_buildpath('/dolimcp/mcp.php', 2); // full URL
$autoload = dolimcpFindMcpAutoloader();
$apiEnabled = isModEnabled('api');

/*
 * View
 */

llxHeader('', $langs->trans('DoliMcpSetup'));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('DoliMcpSetup'), $linkback, 'title_setup');

$head = dolimcpAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('DoliMcpName'), -1, 'technic');

print '<span class="opacitymedium">'.$langs->trans('DoliMcpSetupIntro').'</span><br><br>';

// Status board
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DoliMcpEndpoint').'</td>';
print '<td><strong><a href="'.dol_escape_htmltag($mcpEndpoint).'" target="_blank" rel="noopener">'.dol_escape_htmltag($mcpEndpoint).'</a></strong></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DoliMcpTransport').'</td>';
print '<td>Streamable HTTP (MCP) — POST JSON-RPC 2.0</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DoliMcpServerPackage').'</td>';
print '<td>'.($autoload ? img_picto('', 'tick').' '.dol_escape_htmltag($autoload) : img_picto('', 'error').' '.$langs->trans('DoliMcpServerPackageMissing')).'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DoliMcpRestApiModule').'</td>';
print '<td>'.($apiEnabled ? img_picto('', 'tick').' '.$langs->trans('Enabled') : img_picto('', 'error').' '.$langs->trans('Disabled')).'</td></tr>';

print '</table>';

print '<br>';

// Client configuration examples
print load_fiche_titre($langs->trans('DoliMcpClientExamples'), '', '');

print '<div class="opacitymedium">'.$langs->trans('DoliMcpAuthNote').'</div><br>';

print '<strong>Claude Code :</strong>';
print '<pre class="dolimcp-code" style="background:#f6f6f6;padding:8px;border-radius:4px;overflow-x:auto;">';
print dol_escape_htmltag('claude mcp add dolibarr --transport http '.$mcpEndpoint.' --header "Authorization: Bearer VOTRE_CLE_API"');
print '</pre>';

print '<strong>'.$langs->trans('DoliMcpGenericClient').' (mcp.json) :</strong>';
print '<pre class="dolimcp-code" style="background:#f6f6f6;padding:8px;border-radius:4px;overflow-x:auto;">';
print dol_escape_htmltag(json_encode(array(
	'mcpServers' => array(
		'dolibarr' => array(
			'type' => 'http',
			'url' => $mcpEndpoint,
			'headers' => array('Authorization' => 'Bearer VOTRE_CLE_API'),
		),
	),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
print '</pre>';

print '<div class="info">'.$langs->trans('DoliMcpApiKeyHelp').'</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
