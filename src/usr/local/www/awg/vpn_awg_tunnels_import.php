<?php
/*
 * vpn_awg_tunnels_import.php
 *
 * part of pfSense (https://www.pfsense.org)
 * Copyright (c) 2021-2026 Rubicon Communications, LLC (Netgate)
 * All rights reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

##|+PRIV
##|*IDENT=page-vpn-amneziawg
##|*NAME=VPN: AmneziaWG: Import
##|*DESCR=Allow access to the 'VPN: AmneziaWG' page.
##|*MATCH=vpn_awg_tunnels_import.php*
##|-PRIV

// pfSense includes
require_once('functions.inc');
require_once('guiconfig.inc');

// AmneziaWG includes
require_once('amneziawg/includes/awg.inc');
require_once('amneziawg/includes/awg_guiconfig.inc');
require_once('amneziawg/includes/awg_import.inc');

global $awgg;

awg_globals();

$input_errors = array();
$pconfig = array('conf' => '', 'descr' => '');

if ($_POST) {
	$pconfig['descr'] = trim((string) ($_POST['descr'] ?? ''));
	$pconfig['conf']  = (string) ($_POST['conf'] ?? '');

	/*
	 * El archivo pisa al textarea, no al reves: si alguien elige un archivo Y
	 * pega texto, lo que quiso importar es el archivo.
	 */
	if (!empty($_FILES['conffile']['tmp_name']) && is_uploaded_file($_FILES['conffile']['tmp_name'])) {
		$pconfig['conf'] = (string) file_get_contents($_FILES['conffile']['tmp_name']);
	}

	if (trim($pconfig['conf']) === '') {
		$input_errors[] = gettext('Paste a configuration or choose a file.');
	}

	$parsed = false;

	if (empty($input_errors)) {
		$error = null;

		if (($parsed = awg_import_parse($pconfig['conf'], $error)) === false) {
			$input_errors[] = $error;
		}
	}

	$built = false;

	if (empty($input_errors)) {
		$error = null;

		if (($built = awg_import_build($parsed, $pconfig['descr'], $error)) === false) {
			$input_errors[] = $error;
		}
	}

	/*
	 * Se valida lo construido con el mismo validador que la pagina del tunel:
	 * un archivo ajeno puede traer cualquier cosa, y es mejor rechazarlo aca
	 * que guardar un tunel que no va a levantar.
	 */
	if (empty($input_errors)) {
		$input_errors = array_merge($input_errors, awg_validate_obfuscation($built['tunnel']));
	}

	if (empty($input_errors)) {
		$tunnels = config_get_path('installedpackages/amneziawg/tunnels/item', array());
		$peers = config_get_path('installedpackages/amneziawg/peers/item', array());

		$tunnels[] = $built['tunnel'];

		foreach ($built['peers'] as $peer) {
			$peers[] = $peer;
		}

		config_set_path('installedpackages/amneziawg/tunnels/item', $tunnels);
		config_set_path('installedpackages/amneziawg/peers/item', $peers);

		awg_write_config(sprintf(gettext('Imported AmneziaWG tunnel %s.'), $built['tunnel']['name']));

		if (awg_is_service_running()) {
			mark_subsystem_dirty($awgg['subsystems']['awg']);

			awg_apply_list_add('tunnels', array($built['tunnel']['name']));
		}

		header("Location: /awg/vpn_awg_tunnels.php");

		exit;
	}
}

$pgtitle = array(gettext('VPN'), gettext('AmneziaWG'), gettext('Tunnels'), gettext('Import'));
$pglinks = array('', '/awg/vpn_awg_tunnels.php', '/awg/vpn_awg_tunnels.php', '@self');

$shortcut_section = 'amneziawg';

include('head.inc');

awg_print_service_warning();

if (!empty($input_errors)) {
	print_input_errors($input_errors);
}

$tab_array = array();
$tab_array[] = array(gettext('Tunnels'), true, '/awg/vpn_awg_tunnels.php');
$tab_array[] = array(gettext('Peers'), false, '/awg/vpn_awg_peers.php');
$tab_array[] = array(gettext('Settings'), false, '/awg/vpn_awg_settings.php');
$tab_array[] = array(gettext('Status'), false, '/awg/status_amneziawg.php');

display_top_tabs($tab_array);

$ceiling = awg_version_ceiling();

$form = new Form(false);

// Sin esto el <input type="file"> llega vacio: el form se manda urlencoded
$form->setMultipartEncoding();

$section = new Form_Section('Import a configuration');

$section->addInput(new Form_StaticText(
	'',
	'<span class="text-muted">' .
	gettext('Paste the configuration file of a tunnel that already exists somewhere else — another firewall, a provider, or one this package exported. It becomes a tunnel here plus one peer for each <b>[Peer]</b> section, with the obfuscation parameters copied exactly, which is the part that has to match byte for byte and the part nobody wants to retype.') .
	'</span>'
));

$section->addInput(new Form_Textarea(
	'conf',
	'Configuration',
	$pconfig['conf']
))->setRows(14)
  ->setHelp('The [Interface] section becomes the tunnel, each [Peer] becomes a peer. <b>ListenPort</b> is not taken — a free one on this firewall is assigned instead, since the port in a client file is usually the far end\'s. <b>DNS</b> is not taken either: it configures the client\'s resolver and a pfSense tunnel has nowhere to put it.');

$section->addInput(new Form_Input(
	'conffile',
	'Or a file',
	'file',
	null
))->setHelp('Choosing a file ignores whatever is pasted above.');

$section->addInput(new Form_Input(
	'descr',
	'Description',
	'text',
	$pconfig['descr']
))->setHelp('Optional. Names the tunnel and its peers.');

$section->addInput(new Form_StaticText(
	'',
	'<span class="text-muted">' .
	sprintf(gettext('This firewall reaches <b>%s</b>. A file that uses parameters above that level is still imported and its values kept — the compatibility selector simply leaves them out of the configuration files until the level is available, which also means the tunnel will not come up against a server that expects them.'),
		$awgg['awg_versions'][$ceiling]['label']) .
	'</span>'
));

$form->add($section);

$form->addGlobal(new Form_Button(
	'import',
	'Import',
	null,
	'fa-solid fa-save'
))->addClass('btn-primary');

$form->addGlobal(new Form_Button(
	'cancel',
	'Cancel',
	'/awg/vpn_awg_tunnels.php',
	'fa-solid fa-undo'
))->addClass('btn-warning');

print($form);

include('foot.inc');
