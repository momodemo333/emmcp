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
 * \file    admin/about.php
 * \ingroup dolimcp
 * \brief   DoliMCP "About" page: version, editor, license, capabilities.
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
dol_include_once('/dolimcp/core/modules/modDoliMcp.class.php');

$langs->loadLangs(array('admin', 'dolimcp@dolimcp'));

if (!$user->admin) {
	accessforbidden();
}

$module = new modDoliMcp($db);

/*
 * View
 */

llxHeader('', $langs->trans('DoliMcpName'));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('DoliMcpName'), $linkback, 'title_setup');

$head = dolimcpAdminPrepareHead();
print dol_get_fiche_head($head, 'about', $langs->trans('DoliMcpName'), -1, 'technic');

// Description
print '<div class="opacitymedium">'.$langs->trans('DoliMcpDescriptionDetail').'</div><br>';

// Identity card
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('Version').'</td><td><strong>'.dol_escape_htmltag($module->version).'</strong></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DoliMcpEditor').'</td><td><a href="'.dol_escape_htmltag($module->editor_url).'" target="_blank" rel="noopener">'.dol_escape_htmltag($module->editor_name).'</a></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('License').'</td><td>GPL-3.0-or-later</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DoliMcpCompatibility').'</td><td>Dolibarr 16.x → 21.x · PHP 8.1+</td></tr>';

print '</table>';
print '</div>';

print '<br>';

// Capabilities
print load_fiche_titre($langs->trans('DoliMcpCapabilities'), '', '');
print '<ul>';
print '<li>'.$langs->trans('DoliMcpCapaEndpoint').'</li>';
print '<li>'.$langs->trans('DoliMcpCapaTools').'</li>';
print '<li>'.$langs->trans('DoliMcpCapaGuides').'</li>';
print '<li>'.$langs->trans('DoliMcpCapaApiKey').'</li>';
print '<li>'.$langs->trans('DoliMcpCapaOauth').'</li>';
print '</ul>';

// Links
print '<br>';
print load_fiche_titre($langs->trans('DoliMcpResources'), '', '');
print '<ul>';
$changelog = dol_buildpath('/dolimcp/CHANGELOG.md', 1);
print '<li><a href="'.dol_escape_htmltag($changelog).'" target="_blank" rel="noopener">'.$langs->trans('DoliMcpChangelog').'</a></li>';
print '<li><a href="https://modelcontextprotocol.io" target="_blank" rel="noopener">'.$langs->trans('DoliMcpAboutMcp').'</a></li>';
print '<li><a href="https://github.com/momodemo333/dolibarr-mcp-server" target="_blank" rel="noopener">'.$langs->trans('DoliMcpServerPackage').'</a></li>';
print '</ul>';

print dol_get_fiche_end();

llxFooter();
$db->close();
