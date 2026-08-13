<?php
/*
 * vpn_awg_tunnels_edit.php
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
##|*NAME=VPN: AmneziaWG: Edit
##|*DESCR=Allow access to the 'VPN: AmneziaWG' page.
##|*MATCH=vpn_awg_tunnels_edit.php*
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

$pconfig = [];

// Always assume we are creating a new tunnel
$is_new = true;

if (isset($_REQUEST['tun'])) {
	$tun = $_REQUEST['tun'];
	$tun_idx = awg_tunnel_get_array_idx($_REQUEST['tun']);
}

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
				$res = awg_do_tunnel_post($_POST);
				$input_errors = $res['input_errors'];
				$pconfig = $res['pconfig'];
		
				if (empty($input_errors)) {
					if (awg_is_service_running() && $res['changes']) {
						// Everything looks good so far, so mark the subsystem dirty
						mark_subsystem_dirty($awgg['subsystems']['awg']);

						// Add tunnel to the list to apply
						awg_apply_list_add('tunnels', $res['tuns_to_sync']);
					}
		
					// Save was successful
					header('Location: /awg/vpn_awg_tunnels.php');
				}

				break;

			case 'genkeys':
				// Process ajax call requesting new key pair
				print(awg_gen_keypair(true));
				exit;
				break;

			case 'genpubkey':
				// Process ajax call calculating the public key from a private key
				print(awg_gen_publickey($_POST['privatekey'], true));
				exit;
				break;

			default:
				// Shouldn't be here, so bail out.
				header('Location: /awg/vpn_awg_tunnels.php');
				break;
		}
	}

	if (isset($_POST['peer'])) {
		$peer_idx = $_POST['peer'];
		switch ($_POST['act']) {
			case 'toggle':
				$res = awg_toggle_peer($peer_idx);
				break;

			case 'delete':
				$res = awg_delete_peer($peer_idx);
				break;

			default:
				// Shouldn't be here, so bail out.
				header('Location: /awg/vpn_awg_tunnels.php');
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

// A dirty string hack
$s = fn($x) => $x;

// Looks like we are editing an existing tunnel
if (is_numericint($tun_idx) && is_array(config_get_path("installedpackages/amneziawg/tunnels/item/{$tun_idx}"))) {
	$pconfig = config_get_path("installedpackages/amneziawg/tunnels/item/{$tun_idx}");

	// Supress warning and allow peers to be added via the 'Add Peer' link
	$is_new = false;
// Looks like we are creating a new tunnel
} else {
	// Default to enabled
	$pconfig['enabled'] = 'yes';
	$pconfig['name'] = next_awg_if();

	/*
	 * Ofuscacion por defecto, solo al abrir el formulario vacio. En un POST
	 * que no valido, $pconfig ya trae lo que el usuario escribio y pisarlo le
	 * borraria el trabajo justo cuando tiene que corregirlo.
	 *
	 * Los junk packets y el relleno arrancan con los valores de referencia.
	 * H1-H4 se sortean en vez de tener un default fijo: un header constante
	 * seria una firma nueva, que es lo que este paquete existe para no dejar.
	 */
	if (!$_POST) {
		$pconfig['jc'] = $awgg['default_jc'];
		$pconfig['jmin'] = $awgg['default_jmin'];
		$pconfig['jmax'] = $awgg['default_jmax'];
		$pconfig['s1'] = $awgg['default_s1'];
		$pconfig['s2'] = $awgg['default_s2'];

		$pconfig = array_merge($pconfig, awg_gen_headers());
	}
}

// Save the MTU settings prior to re(saving)
$pconfig['mtu'] = get_interface_mtu($pconfig['name']);
if (!$is_new) {
	config_set_path("installedpackages/amneziawg/tunnels/item/{$tun_idx}/mtu", $pconfig['mtu']);
}

$shortcut_section = "amneziawg";

$pgtitle = array(gettext("VPN"), gettext("AmneziaWG"), gettext("Tunnels"), gettext("Edit"));
$pglinks = array("", "/awg/vpn_awg_tunnels.php", "/awg/vpn_awg_tunnels.php", "@self");

$tab_array = array();
$tab_array[] = array(gettext("Tunnels"), true, "/awg/vpn_awg_tunnels.php");
$tab_array[] = array(gettext("Peers"), false, "/awg/vpn_awg_peers.php");
$tab_array[] = array(gettext("Settings"), false, "/awg/vpn_awg_settings.php");
$tab_array[] = array(gettext("Status"), false, "/awg/status_amneziawg.php");

include("head.inc");

awg_print_service_warning();

if (isset($_POST['apply'])) {
	print_apply_result_box($ret_code);
}

awg_print_config_apply_box();

if (!empty($input_errors)) {
	print_input_errors($input_errors);
}

display_top_tabs($tab_array);

$form = new Form(false);

$section = new Form_Section("Tunnel Configuration ({$pconfig['name']})");

$form->addGlobal(new Form_Input(
	'index',
	'',
	'hidden',
	$tun_idx
));

$tun_enable = new Form_Checkbox(
	'enabled',
	'Enable',
	gettext('Enable Tunnel'),
	$pconfig['enabled'] == 'yes'
);

$tun_enable->setHelp('<span class="text-danger">Note: </span>Tunnel must be <b>enabled</b> in order to be assigned to a pfSense interface.');	

// Disable the tunnel enabled button if interface is assigned in pfSense
if (is_awg_tunnel_assigned($pconfig['name'])) {
	$tun_enable->setDisabled();
	$tun_enable->setHelp('<span class="text-danger">Note: </span>Tunnel cannot be <b>disabled</b> when assigned to a pfSense interface.');

	// We still want to POST this field, make it a hidden field now
	$form->addGlobal(new Form_Input(
		'enabled',
		'',
		'hidden',
		'yes'
	));
}

$section->addInput($tun_enable);

$section->addInput(new Form_Input(
	'descr',
	'Description',
	'text',
	$pconfig['descr'],
	['placeholder' => 'Description']
))->setHelp('Description for administrative reference (not parsed).');

$section->addInput(new Form_Input(
	'listenport',
	'*Listen Port',
	'text',
	$pconfig['listenport'],
	['placeholder' => next_awg_port(), 'autocomplete' => 'new-password']
))->addClass('trim')
  ->setHelp('Port used by this tunnel to communicate with peers.');

$group = new Form_Group('*Interface Keys');

$group->add(new Form_Input(
	'privatekey',
	'Private Key',
	awg_secret_input_type(),
	$pconfig['privatekey'],
	['autocomplete' => 'new-password']
))->addClass('trim')
  ->setHelp('Private key for this tunnel. (Required)');

$group->add(new Form_Input(
	'publickey',
	'Public Key',
	'text',
	$pconfig['publickey']
))->addClass('trim')
  ->setHelp('Public key for this tunnel. (<a id="copypubkey" style="cursor: pointer;" data-success-text="Copied" data-timeout="3000">Copy</a>)')->setReadonly();

$group->add(new Form_Button(
	'genkeys',
	'Generate',
	null,
	'fa-solid fa-key'
))->addClass('btn-primary btn-sm')
  ->setHelp('New Keys')
  ->setWidth(1);

$section->add($group);

$form->add($section);

$section = new Form_Section("Interface Configuration ({$pconfig['name']})");

$section->setAttribute('id', 'addresses');

if (!is_awg_tunnel_assigned($pconfig['name'])) {
	$section->addInput(new Form_StaticText(
		'Assignment',
		"<i class='fa-solid fa-sitemap' style='vertical-align: middle;'></i><a style='padding-left: 3px' href='/interfaces_assign.php'>Interface Assignments</a>"
	));

	$section->addInput(new Form_StaticText(
		'Firewall Rules',
		"<i class='fa-solid fa-shield-alt' style='vertical-align: middle;'></i><a style='padding-left: 3px' href='/firewall_rules.php?if={$awgg['ifgroupentry']['ifname']}'>AmneziaWG Interface Group</a>"
	));

	$section->addInput(new Form_StaticText(
		'Hint',
		"These interface addresses are only applicable for unassigned AmneziaWG tunnel interfaces.</a>"
	));

	// Init the addresses array if necessary
	if (!is_array($pconfig['addresses'])
	    || !is_array($pconfig['addresses']['row'])
	    || empty($pconfig['addresses']['row'])) {
			array_init_path($pconfig, 'addresses/row/0');

			// Hack to ensure empty lists default to /128 mask
			$pconfig['addresses']['row'][0]['mask'] = '128';
		}

	$last = count($pconfig['addresses']['row']) - 1;

	foreach ($pconfig['addresses']['row'] as $counter => $item) {
		$group = new Form_Group($counter == 0 ? 'Interface Addresses' : '');

		$group->addClass('repeatable');

		$group->add(new Form_IpAddress(
			"address{$counter}",
			'Interface Address',
			$item['address'],
			'BOTH'
		))->addClass('trim')
		  ->setHelp($counter == $last ? 'IPv4 or IPv6 address assigned to the tunnel interface.' : '')
		  ->addMask("address_subnet{$counter}", $item['mask'])
		  ->setWidth(4);
		
		$group->add(new Form_Input(
			"address_descr{$counter}",
			'Description',
			'text',
			$item['descr']
		))->setHelp($counter == $last ? 'Description for administrative reference (not parsed).' : '')
		  ->setWidth(4);

		$group->add(new Form_Button(
			"deleterow{$counter}",
			'Delete',
			null,
			'fa-solid fa-trash-can'
		))->addClass('btn-warning btn-sm');
	
		$section->add($group);
	}

	$section->addInput(new Form_Button(
		'addrow',
		'Add Address',
		null,
		'fa-solid fa-plus'
	))->addClass('btn-success btn-sm addbtn');
} else {
	$awg_pfsense_if = awg_get_pfsense_interface_info($pconfig['name']);

	$section->addInput(new Form_StaticText(
		'Assignment',
		"<i class='fa-solid fa-sitemap' style='vertical-align: middle;'></i><a style='padding-left: 3px' href='/interfaces_assign.php'>{$s(htmlspecialchars($awg_pfsense_if['descr']))} ({$s(htmlspecialchars($awg_pfsense_if['name']))})</a>"
	));

	$section->addInput(new Form_StaticText(
		'Interface',
		"<i class='fa-solid fa-ethernet' style='vertical-align: middle;'></i><a style='padding-left: 3px' href='/interfaces.php?if={$s(htmlspecialchars($awg_pfsense_if['name']))}'>{$s(gettext('Interface Configuration'))}</a>"
	));

	$section->addInput(new Form_StaticText(
		'Firewall Rules',
		"<i class='fa-solid fa-shield-alt' style='vertical-align: middle;'></i><a style='padding-left: 3px' href='/firewall_rules.php?if={$s(htmlspecialchars($awg_pfsense_if['name']))}'>{$s(gettext('Firewall Configuration'))}</a>"
	));
}

$form->add($section);

/*
 * Ofuscacion.
 *
 * Los 16 parametros que separan AmneziaWG de WireGuard. La criptografia es la
 * misma; esto cambia la forma de los paquetes para que un DPI no reconozca el
 * handshake por su firma.
 */
$section = new Form_Section('Obfuscation');

$awg2 = awg_backend_supports_awg2();

$section->addInput(new Form_StaticText(
	'Backend',
	$awg2
	    ? "<i class='fa-solid fa-circle-check' style='vertical-align: middle;'></i><span style='padding-left: 3px'>{$s(gettext('AmneziaWG 2.0. Every parameter below is supported.'))}</span>"
	    : "<i class='fa-solid fa-triangle-exclamation' style='vertical-align: middle;'></i><span style='padding-left: 3px'>{$s(gettext('AmneziaWG 1.x. S3, S4 and I1-I5 are not supported and are left out of the tunnel configuration, but any value already stored is kept.'))}</span>"
))->setHelp('Both ends of a tunnel must use the <b>same</b> obfuscation parameters. If they differ, the handshake never completes.');

// Paquetes basura antes del handshake
$group = new Form_Group('Junk Packets');

$group->add(new Form_Input(
	'jc',
	'Jc',
	'number',
	$pconfig['jc'],
	['min' => 1, 'max' => 128, 'placeholder' => $awgg['default_jc']]
))->setHelp('Jc — how many junk packets to send before the handshake. (1-128)')
  ->setWidth(3);

$group->add(new Form_Input(
	'jmin',
	'Jmin',
	'number',
	$pconfig['jmin'],
	['min' => 1, 'max' => 1280, 'placeholder' => $awgg['default_jmin']]
))->setHelp('Jmin — smallest junk packet, in bytes. (1-1280)')
  ->setWidth(3);

$group->add(new Form_Input(
	'jmax',
	'Jmax',
	'number',
	$pconfig['jmax'],
	['min' => 1, 'max' => 1280, 'placeholder' => $awgg['default_jmax']]
))->setHelp('Jmax — largest junk packet, in bytes. Must not be smaller than Jmin. (1-1280)')
  ->setWidth(3);

$section->add($group);

/*
 * Relleno de los paquetes del handshake.
 *
 * Los anchos van explicitos y suman <= Form::MAX_INPUT_WIDTH, que es 10 y no
 * 12: la etiqueta del grupo se come las otras dos columnas. Cuatro campos de
 * ancho 3 suman 12, y el cuarto se caia a una linea nueva sin la sangria de la
 * etiqueta, pegado al margen izquierdo.
 *
 * Dejar que Form_Group reparta solo tampoco sirve acá: hace
 * $spaceLeft / count($inputs), que con cuatro campos da col-sm-2.5, una clase
 * que no existe en el grid.
 */
$pad_width = $awg2 ? 2 : 3;

$group = new Form_Group('Handshake Padding');

$group->add(new Form_Input(
	's1',
	'S1',
	'number',
	$pconfig['s1'],
	['min' => 0, 'max' => 1280, 'placeholder' => $awgg['default_s1']]
))->setHelp('S1 — init packet')
  ->setWidth($pad_width);

$group->add(new Form_Input(
	's2',
	'S2',
	'number',
	$pconfig['s2'],
	['min' => 0, 'max' => 1280, 'placeholder' => $awgg['default_s2']]
))->setHelp('S2 — response')
  ->setWidth($pad_width);

if ($awg2) {
	$group->add(new Form_Input(
		's3',
		'S3',
		'number',
		$pconfig['s3'],
		['min' => 0, 'max' => 1280]
	))->setHelp('S3 — cookie reply')
	  ->setWidth($pad_width);

	$group->add(new Form_Input(
		's4',
		'S4',
		'number',
		$pconfig['s4'],
		['min' => 0, 'max' => 1280]
	))->setHelp('S4 — transport')
	  ->setWidth($pad_width);
}

$section->add($group);

$section->addInput(new Form_StaticText(
	'',
	"<span class='text-muted'>{$s(gettext('Bytes of padding added to each handshake packet, 0 to 1280.'))}</span>"
));

/*
 * H1-H4 son TEXTO, no enteros: admiten un valor suelto o un rango con guion
 * (787134324-1593815189). Un Form_Input numerico los rompe en silencio contra
 * configuraciones reales. Ver docs/arquitectura.md, seccion 7.
 */
$group = new Form_Group('Magic Headers');

foreach (array('h1' => 'init packet', 'h2' => 'response', 'h3' => 'cookie reply', 'h4' => 'transport') as $header => $what) {
	$group->add(new Form_Input(
		$header,
		strtoupper($header),
		'text',
		$pconfig[$header]
	))->addClass('trim')
	  ->setHelp(sprintf('%1$s — %2$s', strtoupper($header), $what))
	  ->setWidth(2);
}

$section->add($group);

$section->addInput(new Form_StaticText(
	'',
	"<span class='text-muted'>{$s(gettext('Each of these replaces the message type number of one kind of packet, and is either a single number or a hyphenated range such as 787134324-1593815189. Values must be 5 or greater and the four must not overlap: 1 through 4 are the standard WireGuard message types, which any header left empty keeps using. New tunnels get random headers, because a fixed value would itself be a signature.'))}</span>"
));

/*
 * I1-I5 son paquetes basura con contenido elegido, no relleno aleatorio, y
 * solo los entiende un backend 2.0.
 */
if ($awg2) {
	$group = new Form_Group('Junk Payloads');

	foreach (array('i1', 'i2', 'i3', 'i4', 'i5') as $payload) {
		$group->add(new Form_Input(
			$payload,
			strtoupper($payload),
			'text',
			$pconfig[$payload]
		))->addClass('trim')
		  ->setHelp(strtoupper($payload))
		  ->setWidth(2);
	}

	$section->add($group);

	$section->addInput(new Form_StaticText(
		'',
		"<span class='text-muted'>{$s(gettext('Optional junk packets with content you choose, sent before the handshake. Leave empty unless you are matching a configuration that already uses them.'))}</span>"
	));
}

$form->add($section);

/*
 * Los campos de AWG 2.0 no se dibujan contra un backend 1.x, pero lo que ya
 * este guardado viaja igual en el POST: si no, editar cualquier otra cosa del
 * tunel borraria valores que el usuario nunca vio, y que vuelven a servir en
 * cuanto el backend se actualice.
 */
if (!$awg2) {
	foreach ($awgg['obfuscation_fields'] as $field => $spec) {
		if ($spec['awg2']) {
			$form->addGlobal(new Form_Input(
				$field,
				'',
				'hidden',
				$pconfig[$field]
			));
		}
	}
}

$form->addGlobal(new Form_Input(
	'mtu',
	'',
	'hidden',
	$pconfig['mtu']
));

$form->addGlobal(new Form_Input(
	'is_new',
	'',
	'hidden',
	$is_new
));

$form->addGlobal(new Form_Input(
	'act',
	'',
	'hidden',
	'save'
));

print($form);

?>

<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext('Peer Configuration')?></h2>
	</div>
	<div id="mainarea" class="table-responsive panel-body">
		<table id="peertable" class="table table-hover table-striped table-condensed" style="overflow-x: visible;">
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
	if (!$is_new):
		foreach (awg_tunnel_get_peers_config($pconfig['name']) as [$peer_idx, $peer, $is_new]):
?>
				<tr ondblclick="document.location='<?="vpn_awg_peers_edit.php?peer={$peer_idx}"?>';" class="<?=awg_peer_status_class($peer)?>">
					<td><?=htmlspecialchars($peer['descr'])?></td>
					<td title="<?=htmlspecialchars($peer['publickey'])?>">
						<?=htmlspecialchars(substr($peer['publickey'], 0, 16).'...')?>
					</td>
					<td><?=htmlspecialchars($peer['tun'])?></td>
					<td><?=awg_generate_peer_allowedips_popup_link($peer_idx)?></td>
					<td><?=htmlspecialchars(awg_format_endpoint(false, $peer))?></td>
					<td style="cursor: pointer;">
						<a class="fa-solid fa-pencil" title="<?=gettext('Edit Peer')?>" href="<?="vpn_awg_peers_edit.php?peer={$peer_idx}"?>"></a>
						<?=awg_generate_toggle_icon_link(($peer['enabled'] == 'yes'), 'peer', "?act=toggle&peer={$peer_idx}&tun={$tun}")?>
						<a class="fa-solid fa-trash-can text-danger" title="<?=gettext('Delete Peer')?>" href="<?="?act=delete&peer={$peer_idx}&tun={$tun}"?>" usepost></a>
					</td>
				</tr>

<?php
		endforeach;
	else:
?>
				<tr>
					<td colspan="6">
						<?php print_info_box('New tunnels must be saved before adding or assigning peers.', 'warning', null); ?>
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
<?php
// We cheat here and show disabled buttons for a better user experience
if ($is_new):
?>
	<button class="btn btn-success btn-sm" title="<?=gettext('Add Peer')?>" disabled>
		<i class="fa-solid fa-plus icon-embed-btn"></i>
		<?=gettext('Add Peer')?>
	</button>
<?php
// Now we show the actual links once the tunnel is actually saved
else:
?>
	<a href="<?="vpn_awg_peers_edit.php?tun={$pconfig['name']}"?>" class="btn btn-success btn-sm">
		<i class="fa-solid fa-plus icon-embed-btn"></i>
		<?=gettext('Add Peer')?>
	</a>
<?php
endif;
?>
	<button type="submit" id="saveform" name="saveform" class="btn btn-primary btn-sm" value="save" title="<?=gettext('Save tunnel')?>">
		<i class="fa-solid fa-save icon-embed-btn"></i>
		<?=gettext('Save Tunnel')?>
	</button>
</nav>

<?php $genKeyWarning = gettext("Overwrite key pair? Click 'ok' to overwrite keys."); ?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	// Supress "Delete" button if there are fewer than two rows
	checkLastRow();

	awgRegTrimHandler();

	$('#copypubkey').click(function () {
		var $this = $(this);
		var originalText = $this.text();

		try {
			// The 'modern' way, this only works with https
			navigator.clipboard.writeText($('#publickey').val());
		} catch {
			console.warn("Failed to copy text using navigator.clipboard, falling back to commands");
			$('#publickey').select();
			document.execCommand("copy");
		}

		$this.text($this.attr('data-success-text'));

		setTimeout(function() {
			$this.text(originalText);
		}, $this.attr('data-timeout'));

		// Prevents the browser from scrolling
		return false;
	});

	// These are action buttons, not submit buttons
	$("#genkeys").prop('type', 'button');

	// Request a new public/private key pair
	$('#genkeys').click(function(event) {
		if ($('#privatekey').val().length == 0 || confirm(<?=json_encode($genKeyWarning)?>)) {
			ajaxRequest = $.ajax({
				url: '/awg/vpn_awg_tunnels_edit.php',
				type: 'post',
				data: {act: 'genkeys'},
				success: function(response, textStatus, jqXHR) {
					resp = JSON.parse(response);
					$('#publickey').val(resp.pubkey);
					$('#privatekey').val(resp.privkey);
				}
			});
		}
	});

	// Request a new public key when private key is changed
	$('#privatekey').change(function(event) {
		ajaxRequest = $.ajax(
			{
				url: '/awg/vpn_awg_tunnels_edit.php',
				type: 'post',
				data: {
					act: 'genpubkey',
					privatekey: $('#privatekey').val()
				},
			success: function(response, textStatus, jqXHR) {
				resp = JSON.parse(response);
				$('#publickey').val(resp.pubkey);
			}
		});
	});


	// Save the form
	$('#saveform').click(function(event) {
		$(form).submit();
	});

});
//]]>
</script>

<?php
include('amneziawg/includes/awg_foot.inc');
include('foot.inc');
?>
