<?php
/*
 * vpn_awg_settings.php
 *
 * part of pfSense (https://www.pfsense.org)
 * Copyright (c) 2021-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2021 R. Christian McDonald (https://github.com/rcmcdonald91)
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
##|*NAME=VPN: AmneziaWG: Settings
##|*DESCR=Allow access to the 'VPN: AmneziaWG' page.
##|*MATCH=vpn_awg_settings.php*
##|-PRIV

// pfSense includes
require_once('functions.inc');
require_once('guiconfig.inc');

// AmneziaWG includes
require_once('amneziawg/includes/awg.inc');
require_once('amneziawg/includes/awg_guiconfig.inc');

global $awgg;

// Initialize $awgg state
awg_globals();

$save_success = false;

if ($_POST) {
	if (isset($_POST['apply'])) {
		$ret_code = 0;

		if (is_subsystem_dirty($awgg['subsystems']['awg'])) {
			if (awg_is_service_running()) {
				$tunnels_to_apply = awg_apply_list_get('tunnels');
				$sync_status = awg_tunnel_sync($tunnels_to_apply, true, true);
				$ret_code |= $sync_status['ret_code'];
			}

			if ($ret_code == 0) {
				clear_subsystem_dirty($awgg['subsystems']['awg']);
			}
		}
	}

	if (isset($_POST['act'])) {
		switch ($_POST['act']) {
			case 'save':
				$res = awg_do_settings_post($_POST);
				$input_errors = $res['input_errors'];
				$pconfig = $res['pconfig'];

				if (empty($input_errors) && $res['changes']) {
					awg_toggle_amneziawg();
					mark_subsystem_dirty($awgg['subsystems']['awg']);
					$save_success = true;
				}

				break;

			default:
				// Shouldn't be here, so bail out.
				header('Location: /awg/vpn_awg_settings.php');
				break;
		}
	}
}

// A dirty string hack
$s = fn($x) => $x;

// Just to make sure defaults are properly assigned if anything is missing
awg_defaults_install();

// Grab current configuration from the XML
$pconfig = config_get_path('installedpackages/amneziawg/config/0');

$shortcut_section = 'amneziawg';

$pgtitle = array(gettext('VPN'), gettext('AmneziaWG'), gettext('Settings'));
$pglinks = array('', '/awg/vpn_awg_tunnels.php', '@self');

$tab_array = array();
$tab_array[] = array(gettext('Tunnels'), false, '/awg/vpn_awg_tunnels.php');
$tab_array[] = array(gettext('Peers'), false, '/awg/vpn_awg_peers.php');
$tab_array[] = array(gettext('Settings'), true, '/awg/vpn_awg_settings.php');
$tab_array[] = array(gettext('Status'), false, '/awg/status_amneziawg.php');

include('head.inc');

awg_print_service_warning();

if ($save_success) {
	//print_info_box(gettext('The changes have been applied successfully.'), 'success');
}

if (isset($_POST['apply'])) {
	print_apply_result_box($ret_code);
}

awg_print_config_apply_box();

if (!empty($input_errors)) {
	print_input_errors($input_errors);
}

display_top_tabs($tab_array);

$form = new Form(false);

$section = new Form_Section(gettext('General Settings'));

$awg_enable = new Form_Checkbox(
	'enable',
	gettext('Enable'),
	gettext('Enable AmneziaWG'),
	awg_is_service_enabled()
);

$awg_enable->setHelp("<span class=\"text-danger\">{$s(gettext('Note:'))} </span>
		     {$s(gettext('AmneziaWG cannot be disabled when one or more tunnels is assigned to a pfSense interface.'))}");

if (awg_is_awg_assigned()) {
	$awg_enable->setDisabled();

	// We still want to POST this field, make it a hidden field now
	$form->addGlobal(new Form_Input(
		'enable',
		'',
		'hidden',
		(awg_is_service_enabled() ? 'yes' : 'no')
	));
}

$section->addInput($awg_enable);

$section->addInput(new Form_Checkbox(
	'keep_conf',
	gettext('Keep Configuration'),
	gettext('Enable'),
	$pconfig['keep_conf'] == 'yes'
))->setHelp("<span class=\"text-danger\">{$s(gettext('Note:'))} </span>
	     {$s(gettext("With 'Keep Configurations' enabled (default), all tunnel configurations and package settings will persist on install/de-install."))}");

$group = new Form_Group(gettext('Endpoint Hostname Resolve Interval'));

$group->add(new Form_Input(
	'resolve_interval',
	gettext('Endpoint Hostname Resolve Interval'),
	'text',
	awg_get_endpoint_resolve_interval(),
	['placeholder' => awg_get_endpoint_resolve_interval()]
))->addClass('trim')
  ->setHelp("{$s(gettext('Interval (in seconds) for re-resolving endpoint host/domain names.'))}<br />
	     <span class=\"text-danger\">{$s(gettext('Note:'))} </span> {$s(sprintf('The default is %s seconds (0 to disable).', $awgg['default_resolve_interval']))}");

$group->add(new Form_Checkbox(
	'resolve_interval_track',
	null,
	gettext('Track System Resolve Interval'),
	($pconfig['resolve_interval_track'] == 'yes')
))->setHelp("{$s(gettext("Tracks the system 'Aliases Hostnames Resolve Interval' setting."))}<br />
	     <span class=\"text-danger\">{$s(gettext('Note:'))} </span> See System &gt; Advanced &gt; <a href=\"/system_advanced_firewall.php\">Firewall &amp; NAT</a>");

$section->add($group);

$interface_group_list = array('all' => gettext('All Tunnels'), 'unassigned' => gettext('Only Unassigned Tunnels'), 'none' => gettext('None'));

$section->addInput($input = new Form_Select(
	'interface_group',
	gettext('Interface Group Membership'),
	$pconfig['interface_group'],
	$interface_group_list
))->setHelp("{$s(gettext('Configures which AmneziaWG tunnels are members of the AmneziaWG interface group.'))}<br />
	     <span class=\"text-danger\">{$s(gettext('Note:'))} </span> {$s(sprintf(gettext("Group firewall rules are evaluated before interface firewall rules. Default is '%s.'"), $interface_group_list['all']))}");

/*
 * Watchdog.
 *
 * Cada tunel es un proceso, asi que puede caerse solo sin que se caiga nada
 * mas. El watchdog lo nota y lo vuelve a levantar sin tocar los otros.
 */
$group = new Form_Group(gettext('Watchdog'));

$group->add(new Form_Checkbox(
	'watchdog',
	gettext('Watchdog'),
	gettext('Restart tunnels that stop on their own'),
	($pconfig['watchdog'] == 'yes')
))->setHelp("{$s(gettext('A cron job checks each enabled tunnel and brings back any whose process is gone.'))}<br />
	     <span class=\"text-danger\">{$s(gettext('Note:'))} </span> {$s(gettext('Tunnels you disabled, and everything else while the service is stopped, are left alone.'))}")
  ->setWidth(5);

$group->add(new Form_Input(
	'watchdog_interval',
	gettext('Check every'),
	'number',
	$pconfig['watchdog_interval'],
	['min' => 1, 'max' => 60, 'placeholder' => $awgg['default_watchdog_interval']]
))->setHelp("{$s(gettext('Minutes between checks, 1 to 60.'))}<br />
	     {$s(sprintf(gettext('The default is %s. A tunnel that will not start is retried less and less often, up to an hour apart.'), $awgg['default_watchdog_interval']))}")
  ->setWidth(4);

$section->add($group);

$form->add($section);

$section = new Form_Section(gettext('User Interface Settings'));

$section->addInput(new Form_Checkbox(
	'hide_secrets',
	gettext('Hide Secrets'),
    	gettext('Enable'),
    	$pconfig['hide_secrets'] == 'yes'
))->setHelp("<span class=\"text-danger\">{$s(gettext('Note:'))} </span>
		{$s(gettext("With 'Hide Secrets' enabled, all secrets (private and pre-shared keys) are hidden in the user interface."))}");

$section->addInput(new Form_Checkbox(
	'hide_peers',
	gettext('Hide Peers'),
	gettext('Enable'),
	$pconfig['hide_peers'] == 'yes'
))->setHelp("<span class=\"text-danger\">{$s(gettext('Note:'))} </span>
		{$s(gettext("With 'Hide Peers' enabled (default), all peers for all tunnels will initially be hidden on the status page."))}");
		
$form->add($section);

$form->addGlobal(new Form_Input(
	'act',
	'',
	'hidden',
	'save'
));

/*
 * El boton de guardar va ADENTRO del formulario, como en la pagina de peers.
 *
 * Venia del paquete nativo como un <button type="submit"> suelto en un <nav>
 * impreso despues de print($form) -- fuera de todo formulario, donde un submit
 * no envia nada-- y lo hacia andar un $(form).submit() por javascript. Ese
 * "form" no es una variable declarada en ningun lado: ni en el arbol de
 * pfSense, ni en su JS, ni en el nuestro, y el formulario que imprime la clase
 * Form no lleva id ni name. Con dos formularios en la pagina el que se envia
 * pasa a ser cualquiera: en peers terminaba pidiendo el mail para guardar.
 *
 * Un Form_Button queda adentro del <form> --Form::__toString los imprime antes
 * del cierre-- asi que envia solo, sin depender de javascript ni de que esta
 * siga siendo la unica pagina con un formulario.
 */
$form->addGlobal(new Form_Button(
	'saveform',
	'Save',
	null,
	'fa-solid fa-save'
))->addClass('btn-primary');

print($form);

?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	awgRegTrimHandler();

	$('#resolve_interval_track').click(function () {
		updateResolveInterval(this.checked);
	});

	function updateResolveInterval(state) {
		$('#resolve_interval').prop( "disabled", state);
	}

	updateResolveInterval($('#resolve_interval_track').prop('checked'));
});
//]]>
</script>

<?php
include('amneziawg/includes/awg_foot.inc');
include('foot.inc');
?>
