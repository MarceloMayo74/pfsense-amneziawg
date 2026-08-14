<?php
/*
 * vpn_awg_peers_edit.php
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
##|*MATCH=vpn_awg_peers_edit.php*
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

$pconfig = [];
$is_new = true;

if (isset($_REQUEST['tun'])) {
	$tun_name = $_REQUEST['tun'];
}

if (isset($_REQUEST['peer']) && is_numericint($_REQUEST['peer'])) {
	$peer_idx = $_REQUEST['peer'];
}

// All form save logic is in amneziawg/awg.inc
if ($_POST) {
	/*
	 * El boton de descarga es un submit del mismo formulario, cuyo act es
	 * 'save'. Se distingue por su propio campo en vez de pisar el act con
	 * javascript, que dejaria la descarga rota con el js deshabilitado.
	 */
	$act = isset($_POST['downloadconf']) ? 'download' : $_POST['act'];

	switch ($act) {
		case 'save':
			/*
			 * El par de claves del cliente se resuelve primero: la clave
			 * publica del peer se deriva de la privada del cliente, y sin eso
			 * la validacion nativa rechazaria el formulario por Public Key
			 * vacio antes de que nadie genere nada.
			 */
			$client_errors = array();
			$_POST = awg_client_prepare_post($_POST, $client_errors);

			$res = awg_do_peer_post($_POST);
			$input_errors = array_merge($client_errors, $res['input_errors']);
			$pconfig = $res['pconfig'];

			/*
			 * Los datos del cliente se guardan DESPUES y aparte: asi el camino
			 * de validacion y sincronizacion del peer, que ya esta probado,
			 * queda intacto y esto solo agrega un sub-elemento.
			 */
			if (empty($input_errors)) {
				$store = awg_client_store_from_post($_POST, $pconfig['tun'], $input_errors);

				if (empty($input_errors)) {
					awg_client_save_store($res['peer_idx'], $store);
				}
			}

			// Lo tipeado en la seccion de cliente tiene que volver a la
			// pantalla si algo fallo, y eso no lo devuelve awg_do_peer_post().
			foreach (array('client_enable', 'client_privatekey', 'client_address',
			               'client_dns', 'client_mtu', 'client_allowedips',
			               'client_endpoint', 'client_keepalive') as $field) {
				$pconfig[$field] = $_POST[$field] ?? '';
			}

			if (empty($input_errors)) {
				if (awg_is_service_running() && $res['changes']) {
					// Everything looks good so far, so mark the subsystem dirty
					mark_subsystem_dirty($awgg['subsystems']['awg']);

					// Add tunnel to the list to apply
					awg_apply_list_add('tunnels', $res['tuns_to_sync']);
				}

				// Save was successful
				header('Location: /awg/vpn_awg_peers.php');
			}
			
			break;

		case 'genpsk':
			// Process ajax call requesting new pre-shared key
			print(awg_gen_psk());
			exit;
			break;

		case 'download':
			/*
			 * Se rearma en el momento desde lo guardado en el peer, en vez de
			 * dejar el archivo en disco: la clave privada del cliente ya vive
			 * en config.xml y una segunda copia en el filesystem solo agregaria
			 * un lugar mas de donde se puede filtrar.
			 */
			$conf = awg_client_conf_from_peer($_POST['index'], $error);

			if ($conf === false) {
				$input_errors[] = $error;
				break;
			}

			[$dl_idx, $dl_peer, $dl_is_new] = awg_peer_get_config($_POST['index'], false);

			$filename = awg_client_conf_filename($dl_peer['descr']);

			header('Content-Type: application/octet-stream');
			header("Content-Disposition: attachment; filename=\"{$filename}\"");
			header('Content-Length: ' . strlen($conf));
			header('Cache-Control: no-store');

			print($conf);
			exit;
			break;

		default:
			// Shouldn't be here, so bail out.
			header('Location: /awg/vpn_awg_peers.php');
			break;
	}
}

if (is_numericint($peer_idx) && is_array(config_get_path("installedpackages/amneziawg/peers/item/{$peer_idx}"))) {
	// Looks like we are editing an existing peer
	$pconfig = config_get_path("installedpackages/amneziawg/peers/item/{$peer_idx}");
	$is_new = false;
} else {
	// Default to enabled
	$pconfig['enabled'] = 'yes';

	// Automatically choose a tunnel based on the request
	$pconfig['tun'] = $tun_name;

	// Default to a dynamic tunnel, so hide the endpoint form group
	$is_dynamic = true;
}

/*
 * Estado de la seccion de cliente. Al editar sale de lo guardado en el peer; en
 * uno nuevo, de los valores que sirven para el caso normal -- todo el trafico
 * por el tunel, la proxima direccion libre, y keepalive puesto porque un
 * cliente que anda por ahi casi siempre esta detras de un NAT.
 */
if (!$_POST) {
	$store = awg_client_store($pconfig);

	$pconfig['client_enable']	= !empty($store['privatekey']) ? 'yes' : '';
	$pconfig['client_privatekey']	= $store['privatekey'] ?? '';
	$pconfig['client_dns']		= $store['dns'] ?? '';
	$pconfig['client_mtu']		= $store['mtu'] ?? '';
	$pconfig['client_endpoint']	= $store['endpoint'] ?? '';
	$pconfig['client_address']	= $store['address']
		?? (string) awg_client_next_address($pconfig['tun']);
	$pconfig['client_allowedips']	= $store['allowedips'] ?? '0.0.0.0/0, ::/0';
	$pconfig['client_keepalive']	= $store['persistentkeepalive'] ?? '25';
}

$client_exportable = !$is_new && awg_client_is_exportable(
	config_get_path("installedpackages/amneziawg/peers/item/{$peer_idx}", array()));

$shortcut_section = "amneziawg";

$pgtitle = array(gettext("VPN"), gettext("AmneziaWG"), gettext("Peers"), gettext("Edit"));
$pglinks = array("", "/awg/vpn_awg_tunnels.php", "/awg/vpn_awg_peers.php", "@self");

$tab_array = array();
$tab_array[] = array(gettext("Tunnels"), false, "/awg/vpn_awg_tunnels.php");
$tab_array[] = array(gettext("Peers"), true, "/awg/vpn_awg_peers.php");
$tab_array[] = array(gettext("Settings"), false, "/awg/vpn_awg_settings.php");
$tab_array[] = array(gettext("Status"), false, "/awg/status_amneziawg.php");

include("head.inc");

awg_print_service_warning();

if (!empty($input_errors)) {
	print_input_errors($input_errors);
}

display_top_tabs($tab_array);

$form = new Form(false);

$section = new Form_Section('Peer Configuration');

$form->addGlobal(new Form_Input(
	'index',
	'',
	'hidden',
	$peer_idx
));

$section->addInput(new Form_Checkbox(
	'enabled',
	'Enable',
	gettext('Enable Peer'),
	$pconfig['enabled'] == 'yes'
))->setHelp('<span class="text-danger">Note: </span>Uncheck this option to disable this peer without removing it from the list.');

$section->addInput($input = new Form_Select(
	'tun',
	'Tunnel',
	$pconfig['tun'],
	awg_get_tun_list()
))->setHelp("AmneziaWG tunnel for this peer. (<a href='vpn_awg_tunnels_edit.php'>Create a New Tunnel</a>)");

$section->addInput(new Form_Input(
	'descr',
	'Description',
	'text',
	$pconfig['descr'],
	['placeholder' => 'Description']
))->setHelp("Peer description for administrative reference (not parsed).");

$section->addInput(new Form_Checkbox(
	'dynamic',
	'Dynamic Endpoint',
	gettext('Dynamic'),
	empty($pconfig['endpoint']) || $is_dynamic
))->setHelp('<span class="text-danger">Note: </span>Uncheck this option to assign an endpoint address and port for this peer.');

$group = new Form_Group('Endpoint');

// Used for hiding/showing the group via JS
$group->addClass("endpoint");

$group->add(new Form_Input(
	'endpoint',
	'Endpoint',
	'text',
	$pconfig['endpoint']
))->addClass('trim')
  ->setHelp('Hostname, IPv4, or IPv6 address of this peer.<br />
	     Leave endpoint and port blank if unknown (dynamic endpoints).')
  ->setWidth(5);

$group->add(new Form_Input(
	'port',
	'Endpoint Port',
	'text',
	$pconfig['port']
))->addClass('trim')
  ->setHelp("Port used by this peer.<br />
	     Leave blank for default ({$awgg['default_port']}).")
  ->setWidth(3);

$section->add($group);

$section->addInput(new Form_Input(
	'persistentkeepalive',
	'Keep Alive',
	'text',
	$pconfig['persistentkeepalive'],
	['placeholder' => 'Keep Alive']
))->addClass('trim')
  ->setHelp('Interval (in seconds) for Keep Alive packets sent to this peer.<br />
	     Default is empty (disabled).');

$section->addInput(new Form_Input(
	'publickey',
	'*Public Key',
	'text',
	$pconfig['publickey'],
	['placeholder' => 'Public Key', 'autocomplete' => 'new-password']
))->addClass('trim')
  ->setHelp('AmneziaWG public key for this peer.');

$group = new Form_Group('Pre-shared Key');

$group->add(new Form_Input(
	'presharedkey',
	'Pre-shared Key',
	awg_secret_input_type(),
	$pconfig['presharedkey'],
	['autocomplete' => 'new-password']
))->addClass('trim')
  ->setHelp('Optional pre-shared key for this tunnel. (<a id="copypsk" style="cursor: pointer;" data-success-text="Copied" data-timeout="3000">Copy</a>)');

$group->add(new Form_Button(
	'genpsk',
	'Generate',
	null,
	'fa-solid fa-key'
))->addClass('btn-primary btn-sm')
  ->setHelp('New Pre-shared Key');

$section->add($group);

$form->add($section);

$section = new Form_Section('Address Configuration');

$section->addInput(new Form_StaticText(
	gettext('Hint'),
	gettext('Allowed IP entries here will be transformed into proper subnet start boundaries prior to validating and saving. ' .
	        'These entries must be unique between multiple peers on the same tunnel. Otherwise, traffic to the conflicting ' .
	        'networks will only be routed to the last peer in the list.')
));

// Init the addresses array if necessary
if (!is_array($pconfig['allowedips'])
    || !is_array($pconfig['allowedips']['row'])
    || empty($pconfig['allowedips']['row'])) {
		array_init_path($pconfig, 'allowedips/row/0');
	
		// Hack to ensure empty lists default to /128 mask
		$pconfig['allowedips']['row'][0]['mask'] = '128';
		if (!$is_new) {
			config_set_path("installedpackages/amneziawg/peers/item/{$peer_idx}/allowedips/row/0/mask", $pconfig['allowedips']['row'][0]['mask']);
		}
}

$last = count($pconfig['allowedips']['row']) - 1;

foreach ($pconfig['allowedips']['row'] as $counter => $item) {
	$group = new Form_Group($counter == 0 ? 'Allowed IPs' : null);

	$group->addClass('repeatable');

	$group->add(new Form_IpAddress(
		"address{$counter}",
		'Allowed Subnet or Host',
		$item['address'],
		'BOTH'
	))->addClass('trim')
	  ->setHelp($counter == $last ? 'IPv4 or IPv6 subnet or host reachable via this peer.' : '')
	  ->addMask("address_subnet{$counter}", $item['mask'], 128, 0)
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
	'Add Allowed IP',
	null,
	'fa-solid fa-plus'
))->addClass('btn-success btn-sm addbtn');

$form->add($section);

/*
 * Configuracion del cliente.
 *
 * Esta aca adentro y no en una pagina aparte porque la pagina de peer es de
 * este paquete: crear el peer y obtener el archivo del cliente son el mismo
 * acto, y separarlos obliga a repetir el tunel, la direccion y las claves.
 *
 * La clave privada del cliente queda guardada en config.xml, que es lo que
 * permite volver a bajar el archivo despues. Es una decision con costo: quien
 * pueda leer la configuracion del firewall puede suplantar al cliente. La
 * alternativa -- generarla y no guardarla -- da una sola oportunidad de
 * descargar y ninguna de rehacerlo.
 */
$section = new Form_Section('Client Configuration');

$section->addInput(new Form_Checkbox(
	'client_enable',
	'Generate',
	gettext('Generate a client configuration for this peer'),
	$pconfig['client_enable'] == 'yes'
))->setHelp('Keeps the client side settings needed to hand this peer a ready to import file, ' .
            'including the obfuscation parameters of its tunnel.<br />' .
            '<span class="text-danger">Note: </span>this stores the client private key in the firewall configuration.');

$section->addInput(new Form_Input(
	'client_privatekey',
	'Client Private Key',
	awg_secret_input_type(),
	$pconfig['client_privatekey'],
	['autocomplete' => 'new-password']
))->addClass('trim')
  ->setHelp('Leave blank to generate one. The matching public key goes in the Public Key field above.');

$section->addInput(new Form_Input(
	'client_address',
	'Client Address',
	'text',
	$pconfig['client_address'],
	['placeholder' => '10.0.0.2/32']
))->addClass('trim')
  ->setHelp('Address the client assigns to its own interface. Leave blank for the next free one on the tunnel.');

$section->addInput(new Form_Input(
	'client_allowedips',
	'Client Allowed IPs',
	'text',
	$pconfig['client_allowedips'],
	['placeholder' => '0.0.0.0/0, ::/0']
))->addClass('trim')
  ->setHelp('What the client routes into the tunnel. Everything by default; narrow it for a split tunnel.');

$section->addInput(new Form_Input(
	'client_endpoint',
	'Client Endpoint',
	'text',
	$pconfig['client_endpoint'],
	['placeholder' => 'vpn.example.com:51820']
))->addClass('trim')
  ->setHelp('Host and port the client uses to reach this firewall, as seen from the outside.');

$section->addInput(new Form_Input(
	'client_dns',
	'Client DNS',
	'text',
	$pconfig['client_dns'],
	['placeholder' => 'DNS']
))->addClass('trim')
  ->setHelp('Optional. Comma separated.');

$section->addInput(new Form_Input(
	'client_mtu',
	'Client MTU',
	'text',
	$pconfig['client_mtu'],
	['placeholder' => $awgg['default_mtu']]
))->addClass('trim')
  ->setHelp('Optional.');

$section->addInput(new Form_Input(
	'client_keepalive',
	'Client Keep Alive',
	'text',
	$pconfig['client_keepalive'],
	['placeholder' => '25']
))->addClass('trim')
  ->setHelp('Interval in seconds the client uses to hold a NAT mapping open. Blank or 0 disables it.');

if ($client_exportable) {
	$section->addInput(new Form_StaticText(
		gettext('Download'),
		'<button type="submit" name="downloadconf" id="downloadconf" class="btn btn-primary btn-sm" ' .
		'value="download" formnovalidate><i class="fa-solid fa-download icon-embed-btn"></i>' .
		gettext('Download') . '</button>'
	))->setHelp('Downloads the .conf as it is saved right now, not as it is shown above. Save first to include any change.');
}

$form->add($section);

$form->addGlobal(new Form_Input(
	'act',
	'',
	'hidden',
	'save'
));

print($form);

?>

<nav class="action-buttons">
	<button type="submit" id="saveform" name="saveform" class="btn btn-primary btn-sm" value="save" title="<?=gettext('Save Peer')?>">
		<i class="fa-solid fa-save icon-embed-btn"></i>
		<?=gettext("Save Peer")?>
	</button>
</nav>

<?php $genkeywarning = gettext("Overwrite pre-shared key? Click 'ok' to overwrite key."); ?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	// Supress "Delete" button if there are fewer than two rows
	checkLastRow();

	awgRegTrimHandler();

	$('#copypsk').click(function () {
		var $this = $(this);
		var originalText = $this.text();

		// The 'modern' way...
		navigator.clipboard.writeText($('#presharedkey').val());

		$this.text($this.attr('data-success-text'));

		setTimeout(function() {
			$this.text(originalText);
		}, $this.attr('data-timeout'));

		// Prevents the browser from scrolling
		return false;
	});

	// These are action buttons, not submit buttons
	$('#genpsk').prop('type','button');

	// Request a new pre-shared key
	$('#genpsk').click(function(event) {
		if ($('#presharedkey').val().length == 0 || confirm(<?=json_encode($genkeywarning)?>)) {
			ajaxRequest = $.ajax({
				url: "/awg/vpn_awg_peers_edit.php",
				type: "post",
				data: {
					act: "genpsk"
				},
				success: function(response, textStatus, jqXHR) {
					$('#presharedkey').val(response);
				}
			});
		}
	});

	// Save the form
	$('#saveform').click(function () {
		$(form).submit();
	});

	$('#dynamic').click(function () {
		updateDynamicSection(this.checked);
	});

	function updateDynamicSection(hide) {
		hideClass('endpoint', hide);
	}

	updateDynamicSection($('#dynamic').prop('checked'));
});
//]]>
</script>

<?php
include('amneziawg/includes/awg_foot.inc');
include('foot.inc');
?>
