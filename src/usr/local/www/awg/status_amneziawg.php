<?php
/*
 * status_amneziawg.php
 *
 * part of pfSense (https://www.pfsense.org)
 * Copyright (c) 2021 R. Christian McDonald (https://github.com/rcmcdonald91)
 * Copyright (c) 2021 Vajonam
 * Copyright (c) 2020 Ascrod
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
##|*IDENT=page-status-amneziawg
##|*NAME=Status: AmneziaWG
##|*DESCR=Allow access to the 'Status: AmneziaWG' page.
##|*MATCH=status_amneziawg.php*
##|-PRIV

// pfSense includes
require_once('guiconfig.inc');
require_once('util.inc');

// AmneziaWG includes
require_once('amneziawg/includes/awg.inc');
require_once('amneziawg/includes/awg_guiconfig.inc');
require_once('amneziawg/includes/awg_client.inc');

global $awgg;

// Initialize $awgg state
awg_globals();

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
}

$shortcut_section = "amneziawg";

$pgtitle = array(gettext("Status"), gettext("AmneziaWG"));
$pglinks = array("", "@self");

$tab_array = array();
$tab_array[] = array(gettext("Tunnels"), false, "/awg/vpn_awg_tunnels.php");
$tab_array[] = array(gettext("Peers"), false, "/awg/vpn_awg_peers.php");
$tab_array[] = array(gettext("Settings"), false, "/awg/vpn_awg_settings.php");
$tab_array[] = array(gettext("Status"), true, "/awg/status_amneziawg.php");

include("head.inc");

awg_print_service_warning();

if (isset($_POST['apply'])) {
	print_apply_result_box($ret_code);
}

awg_print_config_apply_box();

display_top_tabs($tab_array);

$a_devices = awg_get_status();

$peers_hidden = awg_status_peers_hidden();
?>

<?php if ($peers_hidden): ?>
<style> tr[class^='treegrid-parent-'] { display: none; } </style>
<?php endif; ?>

<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext('AmneziaWG Status')?></h2>
	</div>
	<div class="table-responsive panel-body">
		<table class="table table-hover table-striped table-condensed tree" style="overflow-x: visible;">
			<thead>
				<th><?=gettext('Tunnel')?></th>
				<th><?=gettext('Description')?></th>
				<th><?=gettext('Peers')?></th>
				<th><?=gettext('Public Key')?></th>
				<th><?=gettext('Address')?> / <?=gettext('Assignment')?></th>
				<th><?=gettext('MTU')?></th>
				<th><?=gettext('Listen Port')?></th>
				<th><?=gettext('RX')?></th>
				<th><?=gettext('TX')?></th>
			</thead>
			<tbody>
<?php
if (!empty($a_devices)):
	foreach ($a_devices as $device_name => $device):
?>
				<tr class="<?="treegrid-{$device_name}"?>">
					<td>
						<?=awg_interface_status_icon($device['status'])?>
						<a href="vpn_awg_tunnels_edit.php?tun=<?=htmlspecialchars($device_name)?>"><?=htmlspecialchars($device_name)?></a>
					</td>
					<td><?=htmlspecialchars(awg_truncate_pretty($device['config']['descr'], 16))?></td>
					<td><?=count($device['peers'])?></td>
					<td title="<?=htmlspecialchars($device['public_key'])?>">
						<?=htmlspecialchars(awg_truncate_pretty($device['public_key'], 16))?>
					</td>
					<td><?=awg_generate_tunnel_address_popover_link($device_name)?></td>
					<td><?=htmlspecialchars($device['mtu'])?></td>
					<td><?=htmlspecialchars($device['listen_port'])?></td>
					<td><?=htmlspecialchars(format_bytes($device['transfer_rx']))?></td>
					<td><?=htmlspecialchars(format_bytes($device['transfer_tx']))?></td>
				</tr>
				<tr class="<?="treegrid-parent-{$device_name}"?>">
					<td style="font-weight: bold;"><?=gettext('Peers')?></td>
					<td colspan="8" class="contains-table">
						<table class="table table-hover table-condensed">
							<thead>
								<th><?=gettext('Description')?></th>
								<th><?=gettext('Latest Handshake')?></th>
								<th><?=gettext('Public Key')?></th>
								<th><?=gettext('Endpoint')?></th>
								<th><?=gettext('Connected from')?></th>
								<th><?=gettext('Allowed IPs')?></th>
								<th><?=gettext('RX')?></th>
								<th><?=gettext('TX')?></th>
							</thead>
							<tbody>
<?php
		if (count($device['peers']) > 0):
			foreach($device['peers'] as $peer):
?>
								<tr>
									<td>
										<?=awg_handshake_status_icon("@{$peer['latest_handshake']}")?>
										<?=htmlspecialchars(awg_truncate_pretty($peer['config']['descr'], 16))?>
									</td>
									<td><?=htmlspecialchars(awg_human_time_diff("@{$peer['latest_handshake']}"))?></td>
									<td title="<?=htmlspecialchars($peer['public_key'])?>">
										<?=htmlspecialchars(awg_truncate_pretty($peer['public_key'], 16))?>
									</td>
<?php
					/*
					 * Endpoint es a donde disca el cliente: lo que se cargo al
					 * crear el peer y lo que dice su archivo. "Connected from"
					 * es de donde llego, que lo aprende el servidor del
					 * handshake y cambia en cada reconexion. Mostrar el segundo
					 * bajo el titulo del primero -- que es lo que hace la
					 * pagina nativa -- hace parecer que el puerto configurado
					 * se perdio.
					 */
					$dialin = awg_client_dialin_endpoint($peer['config'], true);
?>
									<td title="<?=htmlspecialchars(awg_client_dialin_endpoint($peer['config']) ?? '')?>">
										<?=htmlspecialchars($dialin ?? awg_format_endpoint(false, $peer['config']))?>
									</td>
									<td><?=htmlspecialchars(empty($peer['endpoint']) ? gettext('not connected') : $peer['endpoint'])?></td>
									<td><?=awg_generate_peer_allowedips_popup_link(awg_peer_get_array_idx($peer['config']['publickey'], $peer['config']['tun']))?></td>
									<td><?=htmlspecialchars(format_bytes($peer['transfer_rx']))?></td>
									<td><?=htmlspecialchars(format_bytes($peer['transfer_tx']))?></td>
								</tr>
<?php	
			endforeach;
		else:
?>
								<tr>
									<td colspan="8"><?=gettext('No peers have been configured')?></td>
								</tr>
<?php		
		endif;
?>

							</tbody>
						</table>
					</td>
				</tr>
<?php
	endforeach;
elseif (empty(config_get_path('installedpackages/amneziawg/tunnels/item'))):
?>
				<tr>
					<td colspan="9"><?php print_info_box(gettext('No AmneziaWG tunnels have been configured.'), 'warning', null); ?></td>
				</tr>
<?php
else:
?>
				<tr>
					<td colspan="9"><?php print_info_box(gettext('No AmneziaWG status information is available.'), 'warning', null); ?></td>
				</tr>
<?php
endif;
?>
			</tbody>
		</table>
    	</div>
</div>

<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext('Package Versions')?></h2>
	</div>
	<div class="table-responsive panel-body">
		<table class="table table-hover table-striped table-condensed">
			<thead>
				<tr>
					<th><?=gettext('Name')?></th>
					<th><?=gettext('Version')?></th>
    					<th><?=gettext('Comment')?></th>
				</tr>
			</thead>
			<tbody>
<?php
			foreach (awg_pkg_info() as ['name' => $name, 'version' => $version, 'comment' => $comment]):
?>
    				<tr>
					<td><?=htmlspecialchars($name)?></td>
					<td><?=htmlspecialchars($version)?></td>
					<td><?=htmlspecialchars($comment)?></td>

				</tr>
<?php
			endforeach;
?>

			</tbody>
		</table>
	</div>
</div>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	$('.tree').treegrid({
		expanderExpandedClass: 'fa-solid fa fa-chevron-down',
		expanderCollapsedClass: 'fa-solid fa fa-chevron-right',
		initialState: (<?=json_encode($peers_hidden)?> ? 'collapsed' : 'expanded')
	});
});
//]]>
</script>

<?php
include('amneziawg/includes/awg_foot.inc');
include('foot.inc');
?>