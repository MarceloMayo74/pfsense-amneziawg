<?php
/*
 * vpn_awg_peers.php
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
##|*NAME=VPN: AmneziaWG
##|*DESCR=Allow access to the 'VPN: AmneziaWG' page.
##|*MATCH=vpn_awg_peers.php*
##|-PRIV

// pfSense includes
require_once('functions.inc');
require_once('guiconfig.inc');

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

	if (isset($_POST['peer'])) {
		$peer_idx = $_POST['peer'];

		switch ($_POST['act']) {
			case 'download':
				$client_conf = awg_client_conf_from_peer($peer_idx, $error);

				if ($client_conf === false) {
					$input_errors[] = $error;
					break;
				}

				[$dl_idx, $dl_peer, $dl_is_new] = awg_peer_get_config($peer_idx, false);

				$conf_filename = awg_client_conf_filename($dl_peer['descr']);

				/*
				 * Siempre comprimido. Todo cliente acepta un archivo, y un .zip
				 * sobrevive a que lo pasen por mail o por un mensajero, cosa
				 * que un .conf pelado muchas veces no: se lo renombra, se lo
				 * abre como texto o directamente se lo bloquea.
				 */
				send_user_download('data',
					awg_client_build_zip(array($conf_filename => $client_conf)),
					awg_client_zip_filename($conf_filename));
				exit;

			case 'qrdata':
				// El QR se dibuja en el navegador; aca solo viaja el contenido.
				$client_conf = awg_client_conf_from_peer($peer_idx, $error);

				header('Content-Type: application/json');
				header('Cache-Control: no-store');

				print(json_encode(($client_conf === false)
					? array('error' => $error)
					: array('conf' => $client_conf,
					        'name' => awg_client_conf_filename(
							awg_peer_get_config($peer_idx, false)[1]['descr']))));
				exit;

			case 'toggle':
				$res = awg_toggle_peer($peer_idx);
				break;

			case 'delete':
				$res = awg_delete_peer($peer_idx);
				break;

			default:
				// Shouldn't be here, so bail out.
				header('Location: /awg/vpn_awg_peers.php');
				break;
		}

		$input_errors = $res['input_errors'];

		if (empty($input_errors)) {
			if (awg_is_service_running() && $res['changes']) {
				mark_subsystem_dirty($awgg['subsystems']['awg']);

				// Add tunnel to the list to apply
				awg_apply_list_add('tunnels', $res['tuns_to_sync']);
			}
		}
	}
}

$shortcut_section = 'amneziawg';

$pgtitle = array(gettext('VPN'), gettext('AmneziaWG'), gettext('Peers'));
$pglinks = array('', '/awg/vpn_awg_tunnels.php', '@self');

$tab_array = array();
$tab_array[] = array(gettext('Tunnels'), false, '/awg/vpn_awg_tunnels.php');
$tab_array[] = array(gettext('Peers'), true, '/awg/vpn_awg_peers.php');
$tab_array[] = array(gettext('Settings'), false, '/awg/vpn_awg_settings.php');
$tab_array[] = array(gettext('Status'), false, '/awg/status_amneziawg.php');

include('head.inc');

awg_print_service_warning();

if (isset($_POST['apply'])) {

	print_apply_result_box($ret_code);

}

awg_print_config_apply_box();

if (!empty($input_errors)) {

	print_input_errors($input_errors);

}

display_top_tabs($tab_array);

?>

<form name="mainform" method="post">
	<div class="panel panel-default">
		<div class="panel-heading"><h2 class="panel-title"><?=gettext('AmneziaWG Peers')?></h2></div>
		<div class="panel-body table-responsive">
			<table class="table table-hover table-striped table-condensed">
				<thead>
					<tr>
						<th><?=gettext('Description')?></th>
						<th><?=gettext('Public key')?></th>
						<th><?=gettext('Tunnel')?></th>
						<th><?=gettext('Allowed IPs')?></th>
						<th><?=htmlspecialchars(awg_format_endpoint(true))?></th>
						<th><?=gettext('Actions')?></th>
					</tr>
				</thead>
				<tbody>
<?php
if (count(config_get_path('installedpackages/amneziawg/peers/item', [])) > 0):

		foreach (config_get_path('installedpackages/amneziawg/peers/item', []) as $peer_idx => $peer):
?>
					<tr ondblclick="document.location='<?="vpn_awg_peers_edit.php?peer={$peer_idx}"?>';" class="<?=awg_peer_status_class($peer)?>">
						<td><?=htmlspecialchars(awg_truncate_pretty($peer['descr'], 16))?></td>
						<td style="cursor: pointer;" class="pubkey" title="<?=htmlspecialchars($peer['publickey'])?>">
							<?=htmlspecialchars(awg_truncate_pretty($peer['publickey'], 16))?>
						</td>
						<td><?=htmlspecialchars($peer['tun'])?></td>
						<td><?=awg_generate_peer_allowedips_popup_link($peer_idx)?></td>
						<td><?=htmlspecialchars(awg_format_endpoint(false, $peer))?></td>
						<td style="cursor: pointer;">
							<a class="fa-solid fa-pencil" title="<?=gettext('Edit Peer')?>" href="<?="vpn_awg_peers_edit.php?peer={$peer_idx}"?>"></a>
							<?=awg_generate_toggle_icon_link(($peer['enabled'] == 'yes'), 'peer', "?act=toggle&peer={$peer_idx}")?>
<?php		if (awg_client_is_exportable($peer)): ?>
							<a class="fa-solid fa-download" title="<?=gettext('Download the client configuration')?>" href="<?="?act=download&peer={$peer_idx}"?>" usepost></a>
							<a class="fa-solid fa-qrcode awg-qr" title="<?=gettext('Download the QR code')?>" href="#" data-peer="<?=htmlspecialchars($peer_idx)?>"></a>
<?php		endif; ?>
							<a class="fa-solid fa-trash-can text-danger" title="<?=gettext('Delete Peer')?>" href="<?="?act=delete&peer={$peer_idx}"?>" usepost></a>
						</td>
					</tr>

<?php
		endforeach;

else:
?>
					<tr>
						<td colspan="6">
							<?php print_info_box(gettext('No AmneziaWG peers have been configured. Click the "Add Peer" button below to create one.'), 'warning', null); ?>
						</td>
					</tr>
<?php
endif;
?>
				</tbody>
			</table>
		</div>
	</div>
	<nav class="action-buttons">
		<a href="vpn_awg_peers_edit.php" class="btn btn-success btn-sm">
			<i class="fa-solid fa-plus icon-embed-btn"></i>
			<?=gettext('Add Peer')?>
		</a>
	</nav>
</form>

<script type="text/javascript">
//<![CDATA[
events.push(function() {

	$('.pubkey').click(function () {

		var publicKey = $(this).attr('title');

		try {
			// The 'modern' way...
			navigator.clipboard.writeText(publicKey);
		} catch {
			console.warn("Failed to copy text using navigator.clipboard, falling back to commands");

			// Convert the TD contents to an input with pub key
			var pubKeyInput = $('<input/>', {val: publicKey});
			var oldText = $(this).text();

			// Add to DOM
			$(this).html(pubKeyInput);

			// copy
			pubKeyInput.select();
			document.execCommand("copy");

			// revert back to just text
			$(this).html(oldText);
		}

	});

<?php if (!is_null($qrcode_js = awg_client_qrcode_js_url())): ?>
	awgQr.size	= <?=(int) $awgg['qr_size']?>;
	awgQr.quietZone	= <?=(int) $awgg['qr_quiet_zone']?>;
	awgQr.level	= <?=json_encode($awgg['qr_level'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;

	/*
	 * El archivo no viaja en la pagina: se pide al hacer clic. Son claves
	 * privadas de todos los clientes, y no hay razon para tenerlas en el HTML
	 * de una lista que se abre para cualquier otra cosa.
	 */
	$('.awg-qr').click(function(event) {
		event.preventDefault();

		var peer = $(this).attr('data-peer');

		$.ajax({
			url: "/awg/vpn_awg_peers.php",
			type: "post",
			dataType: "json",
			data: { act: "qrdata", peer: peer },
			success: function(resp) {
				if (resp.error) {
					alert(resp.error);

					return;
				}

				awgQr.download(resp.conf, resp.name.replace(/\.conf$/, ''));
			}
		});
	});
<?php endif; ?>

});
//]]>
</script>

<?php if (!is_null($qrcode_js)): ?>
<script src="<?=htmlspecialchars($qrcode_js)?>"></script>
<script src="<?=htmlspecialchars(awg_client_asset_url('/awg/js/awg_qr.js'))?>"></script>
<?php endif; ?>

<?php
include('amneziawg/includes/awg_foot.inc');
include('foot.inc');
?>