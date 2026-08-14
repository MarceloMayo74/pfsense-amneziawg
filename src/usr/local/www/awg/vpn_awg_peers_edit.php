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
$peer_idx = null;
$tun_name = null;

if (isset($_REQUEST['tun'])) {
	$tun_name = $_REQUEST['tun'];
}

if (isset($_REQUEST['peer']) && is_numericint($_REQUEST['peer'])) {
	$peer_idx = $_REQUEST['peer'];
}

if ($_POST) {
	/*
	 * El boton de descarga es un submit del mismo formulario, cuyo act es
	 * 'save'. Se distingue por su propio campo en vez de pisar el act con
	 * javascript, que dejaria la descarga rota con el js deshabilitado.
	 */
	$act = isset($_POST['downloadconf']) ? 'download' : ($_POST['act'] ?? '');

	switch ($act) {
		case 'save':
			// La validacion y el guardado viven en awg_client.inc
			$res = awg_client_do_peer_post($_POST);

			$input_errors = $res['input_errors'];
			$pconfig = $res['pconfig'];

			if (empty($input_errors)) {
				if (awg_is_service_running() && $res['changes']) {
					// Everything looks good so far, so mark the subsystem dirty
					mark_subsystem_dirty($awgg['subsystems']['awg']);

					// Add tunnel to the list to apply
					awg_apply_list_add('tunnels', $res['tuns_to_sync']);

					if ($pconfig['applynow'] == 'yes') {
						awg_apply_list_apply('tunnels');
					}
				}

				// Save was successful
				header('Location: /awg/vpn_awg_peers.php');
			}

			break;

		case 'genkeys':
			// Un par nuevo para el cliente, pedido por ajax
			print(awg_gen_keypair(true));
			exit;

		case 'genpsk':
			// Process ajax call requesting new pre-shared key
			print(awg_gen_psk());
			exit;

		case 'nextaddr':
			// La proxima direccion libre del tunel elegido, pedida por ajax
			print((string) awg_client_next_address($_POST['tun'] ?? ''));
			exit;

		case 'download':
			/*
			 * Se rearma en el momento desde lo guardado en el peer, en vez de
			 * dejar el archivo en disco: la clave privada del cliente ya vive
			 * en config.xml y una segunda copia en el filesystem solo agregaria
			 * un lugar mas de donde se puede filtrar.
			 */
			$conf = awg_client_conf_from_peer($_POST['index'] ?? '', $error);

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

		default:
			// Shouldn't be here, so bail out.
			header('Location: /awg/vpn_awg_peers.php');
			break;
	}
}

if (is_numericint($peer_idx) && is_array(config_get_path("installedpackages/amneziawg/peers/item/{$peer_idx}"))) {
	$is_new = false;
}

/*
 * Estado del formulario cuando no viene de un post con errores.
 *
 * Un peer existente se lee de la configuracion; uno nuevo arranca con lo que el
 * tunel puede contestar por si mismo y con lo que se eligio para el cliente
 * anterior del mismo tunel, que casi siempre es lo que se quiere de nuevo.
 */
if (!$_POST) {
	if (!$is_new) {
		$peer = config_get_path("installedpackages/amneziawg/peers/item/{$peer_idx}");
		$store = awg_client_store($peer);

		$pconfig['index']		= $peer_idx;
		$pconfig['enabled']		= $peer['enabled'];
		$pconfig['tun']			= $peer['tun'];
		$pconfig['descr']		= $peer['descr'];
		$pconfig['publickey']		= $peer['publickey'];
		$pconfig['presharedkey']	= $peer['presharedkey'];
		$pconfig['address']		= awg_client_addresses_to_line($peer['allowedips']['row'] ?? array());

		$pconfig['client_enable']	= awg_client_is_exportable($peer) ? 'yes' : 'no';
		$pconfig['privatekey']		= $store['privatekey'] ?? '';
		$pconfig['dns']			= $store['dns'] ?? '';
		$pconfig['mtu']			= $store['mtu'] ?? '';
		$pconfig['routing']		= $store['routing'] ?? 'custom';
		$pconfig['client_allowedips']	= $store['allowedips'] ?? '';
		$pconfig['endpoint']		= $store['endpoint'] ?? $peer['endpoint'];
		$pconfig['port']		= $store['port'] ?? $peer['port'];
		$pconfig['persistentkeepalive']	= $store['persistentkeepalive'] ?? $peer['persistentkeepalive'];
	} else {
		/*
		 * 'unassigned' encabeza la lista pero no es un tunel: es la opcion de
		 * dejar el peer sin atar a ninguno. Un peer nuevo tiene que abrir sobre
		 * el primer tunel de verdad, o todos los valores que se calculan del
		 * tunel saldrian vacios.
		 */
		$tun_list = array_diff_key(awg_get_tun_list(), array('unassigned' => ''));

		$pconfig['tun'] = array_key_exists((string) $tun_name, $tun_list)
					? $tun_name
					: (string) array_key_first($tun_list);

		$defaults = awg_client_tunnel_defaults($pconfig['tun']);

		$pconfig['enabled']		= 'yes';
		$pconfig['client_enable']	= 'yes';
		$pconfig['descr']		= '';
		$pconfig['publickey']		= '';
		$pconfig['presharedkey']	= '';
		$pconfig['privatekey']		= '';
		$pconfig['address']		= (string) $defaults['address'];
		$pconfig['dns']			= (string) $defaults['dns'];
		$pconfig['mtu']			= (string) $defaults['mtu'];
		$pconfig['routing']		= $defaults['routing'];
		$pconfig['client_allowedips']	= $defaults['client_allowedips'];
		$pconfig['endpoint']		= (string) $defaults['endpoint'];
		$pconfig['port']		= (string) $defaults['port'];
		$pconfig['persistentkeepalive']	= (string) $defaults['persistentkeepalive'];
	}

	$pconfig['applynow'] = 'no';
}

$client_exportable = !$is_new
	&& awg_client_is_exportable(config_get_path("installedpackages/amneziawg/peers/item/{$peer_idx}", array()));

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
	$pconfig['index'] ?? ''
));

$section->addInput(new Form_Checkbox(
	'enabled',
	'Enable',
	gettext('Enable Peer'),
	$pconfig['enabled'] == 'yes'
))->setHelp('<span class="text-danger">Note: </span>Uncheck this option to create the peer without enabling it.');

$section->addInput(new Form_Select(
	'tun',
	'*Tunnel',
	$pconfig['tun'],
	awg_get_tun_list()
))->setHelp("AmneziaWG tunnel for this peer. Its listen port, MTU and the 16 obfuscation parameters " .
	    "are taken from it. (<a href='vpn_awg_tunnels_edit.php'>Create a New Tunnel</a>)");

$section->addInput(new Form_Input(
	'descr',
	'*Description',
	'text',
	$pconfig['descr'],
	['placeholder' => 'Description']
))->setHelp('Peer description for administrative reference. It also names the client file, ' .
	    'keeping the first 15 characters of [a-zA-Z0-9_=+.-].');

/*
 * El interruptor entre los dos modos de la pagina. Tildado, el firewall arma el
 * cliente entero y guarda su clave privada. Destildado, esto es la pagina de
 * peer de siempre: se pega una clave publica y no se guarda nada del otro lado.
 * Ese segundo modo es el del peer site-to-site y el del cliente que genera sus
 * propias claves, que es la practica mas segura y no se puede perder.
 */
$section->addInput(new Form_Checkbox(
	'client_enable',
	'Client',
	gettext('Generate a client configuration for this peer'),
	$pconfig['client_enable'] == 'yes'
))->setHelp('Keeps what is needed to hand this peer a ready to import file, including the obfuscation ' .
	    'parameters of its tunnel.<br />' .
	    '<span class="text-danger">Note: </span>this stores the client private key in the firewall ' .
	    'configuration. Uncheck it to register a peer from a public key you were given, which is the ' .
	    'safer practice and the only option for a site to site peer.');

$group = new Form_Group('*Endpoint');

$group->addClass('clientonly');

$group->add(new Form_Input(
	'endpoint',
	'Endpoint',
	'text',
	$pconfig['endpoint'],
	['placeholder' => 'vpn.example.com']
))->addClass('trim')
  ->setHelp('Hostname, IPv4, or IPv6 address of this firewall as reachable by the client. ' .
	    'Written to the client file and stored on this peer.')
  ->setWidth(4);

/*
 * Un select al lado del campo y no un datalist encima: un datalist filtra sus
 * sugerencias contra lo que ya este escrito, y este campo llega con algo puesto,
 * asi que el resto de la lista quedaria escondida.
 */
$group->add(new Form_Select(
	'endpoint_detected',
	'Detected',
	'',
	array('' => gettext('Detected on this firewall...'))
))->setHelp('Dynamic DNS hostnames and interface addresses already configured here. ' .
	    'Picking one fills the endpoint. (<a href="/services_dyndns.php">' . gettext('Dynamic DNS') . '</a>)')
  ->setWidth(4);

$group->add(new Form_Input(
	'port',
	'Endpoint Port',
	'text',
	$pconfig['port']
))->addClass('trim')
  ->setHelp('Listen port of the tunnel. Required: a client rejects an endpoint without one.')
  ->setWidth(2);

$section->add($group);

$section->addInput(new Form_Input(
	'persistentkeepalive',
	'Keep Alive',
	'text',
	$pconfig['persistentkeepalive'],
	['placeholder' => 'Keep Alive']
))->addClass('trim')
  ->setHelp('Interval (in seconds) for Keep Alive packets sent by this client.<br />
	     Recommended for clients behind NAT. Leave blank to disable.');

$group = new Form_Group('*Client Keys');

$group->addClass('clientonly');

$group->add(new Form_Input(
	'privatekey',
	'Client Private Key',
	awg_secret_input_type(),
	$pconfig['privatekey'],
	['autocomplete' => 'new-password']
))->addClass('trim')
  ->setHelp('Private key for this client, kept with the peer so the file can be handed out again. ' .
	    '(<a id="copypriv" style="cursor: pointer;" data-success-text="Copied" data-timeout="3000">Copy</a>)');

$group->add(new Form_Button(
	'genkeys',
	'Generate',
	null,
	'fa-solid fa-key'
))->addClass('btn-primary btn-sm')
  ->setHelp('New Key Pair');

$section->add($group);

/*
 * Un solo campo para la clave publica, no dos.
 *
 * Generando es de solo lectura y lo llena el boton de arriba, porque la publica
 * se deriva de la privada y escribirla a mano solo puede desincronizarlas. En
 * modo manual es editable y es el unico dato que hay del otro extremo. Alternar
 * readonly sale mas barato y mas claro que esconder un campo y mostrar otro.
 */
$section->addInput(new Form_Input(
	'publickey',
	'*Public Key',
	'text',
	$pconfig['publickey'],
	['placeholder' => 'Public Key', 'autocomplete' => 'new-password']
))->addClass('trim')
  ->setHelp('Generating a client configuration derives this from the private key above. ' .
	    'Otherwise, paste here the public key the other side gave you. ' .
	    '(<a id="copypub" style="cursor: pointer;" data-success-text="Copied" data-timeout="3000">Copy</a>)');

if (!$is_new && ($pconfig['client_enable'] == 'yes') && empty($pconfig['privatekey'])) {
	$section->addInput(new Form_StaticText(
		gettext('Note'),
		gettext('This peer was not created here, so its private key is not on this firewall and no client ' .
			'file can be produced for it. Generating a new key pair above would re-key the client, and ' .
			'the old configuration would stop working.')
	));
}

$group = new Form_Group('Pre-shared Key');

$group->add(new Form_Input(
	'presharedkey',
	'Pre-shared Key',
	awg_secret_input_type(),
	$pconfig['presharedkey'],
	['autocomplete' => 'new-password']
))->addClass('trim')
  ->setHelp('Optional pre-shared key, written to both this peer and the client file. ' .
	    '(<a id="copypsk" style="cursor: pointer;" data-success-text="Copied" data-timeout="3000">Copy</a>)');

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
	gettext('The address entered here is assigned to the client and saved as the Allowed IPs of this peer. ' .
		'These entries must be unique between multiple peers on the same tunnel. Otherwise, traffic to the ' .
		'conflicting networks will only be routed to the last peer in the list.')
));

$group = new Form_Group('*Allowed IPs');

/*
 * autocomplete off acá y en Tunneled Networks: se calculan del tunel en cada
 * render, y un navegador restaurando lo que recuerda de una visita anterior
 * volveria a poner en pantalla la direccion de una red que ya cambio.
 */
$group->add(new Form_Input(
	'address',
	'Allowed IPs',
	'text',
	$pconfig['address'],
	['placeholder' => '10.6.0.2/32', 'autocomplete' => 'off']
))->addClass('trim')
  ->setHelp('Address assigned to this client, in CIDR notation. Separate multiple addresses with commas.<br />
	     Written as <code>Address</code> in the client file and as the peer <code>AllowedIPs</code> here.')
  ->setWidth(5);

$group->add(new Form_Button(
	'nextaddr',
	'Suggest',
	null,
	'fa-solid fa-wand-magic-sparkles'
))->addClass('btn-primary btn-sm')
  ->setHelp('Next free address on this tunnel');

$section->add($group);

$form->add($section);

$section = new Form_Section('Client Configuration');

$section->addClass('clientonly');

$section->addInput(new Form_Select(
	'routing',
	'Routing',
	$pconfig['routing'],
	array(
		'full'		=> gettext('Full tunnel (send all traffic through the tunnel)'),
		'split'		=> gettext('Split tunnel (only the tunnel networks)'),
		'custom'	=> gettext('Custom'))
))->setHelp('Preset for the networks below.');

$section->addInput(new Form_Input(
	'client_allowedips',
	'*Tunneled Networks',
	'text',
	$pconfig['client_allowedips'],
	['placeholder' => '0.0.0.0/0, ::/0', 'autocomplete' => 'off']
))->addClass('trim')
  ->setHelp('Networks the client routes into the tunnel. Written as <code>AllowedIPs</code> in the client file.');

$section->addInput(new Form_Input(
	'dns',
	'DNS Servers',
	'text',
	$pconfig['dns'],
	['placeholder' => 'DNS Servers']
))->addClass('trim')
  ->setHelp('Optional. Separate multiple entries with commas.<br />
	     Leave blank to keep the DNS servers already configured on the client.');

$section->addInput(new Form_Input(
	'mtu',
	'MTU',
	'text',
	$pconfig['mtu'],
	['placeholder' => 'MTU']
))->addClass('trim')
  ->setHelp('Optional. Filled in from the tunnel when it does not run on the default of ' .
	    "{$awgg['default_mtu']}, which is what a client assumes on its own. Leave blank to let the client decide.");

$section->addInput(new Form_Checkbox(
	'applynow',
	'Apply',
	gettext('Apply the changes immediately'),
	$pconfig['applynow'] == 'yes'
))->setHelp('<span class="text-danger">Note: </span>This action may momentarily suspend active AmneziaWG peer connections on the changed tunnels.');

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

<?php
$genkeywarning = gettext("Overwrite pre-shared key? Click 'ok' to overwrite key.");
$genkeyswarning = gettext("Overwrite the client key pair? The client configuration already handed out would stop working. Click 'ok' to overwrite.");

// Lo que cada tunel puede contestar por si mismo, para no volver al servidor
// cada vez que se cambia la seleccion.
$tunnel_hints = awg_client_tunnel_hints();
?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	var awgHints = <?=json_encode($tunnel_hints)?>;

	awgRegTrimHandler();

	function copyHandler(link, field) {
		$(link).click(function () {
			var $this = $(this);
			var originalText = $this.text();

			navigator.clipboard.writeText($(field).val());

			$this.text($this.attr('data-success-text'));

			setTimeout(function() {
				$this.text(originalText);
			}, $this.attr('data-timeout'));

			// Prevents the browser from scrolling
			return false;
		});
	}

	copyHandler('#copypsk', '#presharedkey');
	copyHandler('#copypriv', '#privatekey');
	copyHandler('#copypub', '#publickey');

	// These are action buttons, not submit buttons
	$('#genpsk').prop('type', 'button');
	$('#genkeys').prop('type', 'button');
	$('#nextaddr').prop('type', 'button');

	$('#genpsk').click(function(event) {
		if ($('#presharedkey').val().length == 0 || confirm(<?=json_encode($genkeywarning)?>)) {
			$.ajax({
				url: "/awg/vpn_awg_peers_edit.php",
				type: "post",
				data: { act: "genpsk" },
				success: function(response) {
					$('#presharedkey').val(response);
				}
			});
		}
	});

	$('#genkeys').click(function(event) {
		if ($('#privatekey').val().length == 0 || confirm(<?=json_encode($genkeyswarning)?>)) {
			$.ajax({
				url: "/awg/vpn_awg_peers_edit.php",
				type: "post",
				dataType: "json",
				data: { act: "genkeys" },
				success: function(response) {
					$('#privatekey').val(response.privkey_clamped);
					$('#publickey').val(response.pubkey);
				}
			});
		}
	});

	$('#nextaddr').click(function(event) {
		$.ajax({
			url: "/awg/vpn_awg_peers_edit.php",
			type: "post",
			data: { act: "nextaddr", tun: $('#tun').val() },
			success: function(response) {
				if (response.length > 0) {
					$('#address').val(response);
				}
			}
		});
	});

	// El detectado solo se copia al endpoint; el servidor lo ignora
	$('#endpoint_detected').change(function() {
		if ($(this).val().length > 0) {
			$('#endpoint').val($(this).val());
		}
	});

	/*
	 * Lo que cambia al elegir otro tunel. Solo se pisan los campos que salen
	 * del tunel -- puerto, MTU, DNS, redes y la direccion sugerida -- y nunca
	 * lo que el operador ya escribio a mano.
	 */
	function updateTunnelDefaults() {
		var hint = awgHints[$('#tun').val()];

		if (!hint) {
			return;
		}

		$('#port').val(hint.port);
		$('#address').val(hint.address);
		$('#mtu').val(hint.defaults.mtu);
		$('#routing').val(hint.defaults.routing);

		applyRouting();

		if (hint.defaults.dns !== null) {
			$('#dns').val(hint.defaults.dns);
		}
	}

	function applyRouting() {
		var hint = awgHints[$('#tun').val()];

		if (!hint) {
			return;
		}

		switch ($('#routing').val()) {
			case 'full':
				$('#client_allowedips').val('<?=$awgg['default_allowedips']?>');
				break;

			case 'split':
				$('#client_allowedips').val(hint.networks);
				break;
		}
	}

	$('#tun').change(updateTunnelDefaults);
	$('#routing').change(applyRouting);

	/*
	 * Los dos modos de la pagina. Generando, la clave publica sale del par y no
	 * se toca a mano; en modo manual es el unico dato del otro extremo y todo
	 * lo que describe al cliente sobra.
	 */
	function updateClientMode() {
		var generating = $('#client_enable').prop('checked');

		hideClass('clientonly', !generating);

		$('#publickey').prop('readonly', generating);
	}

	$('#client_enable').click(updateClientMode);

	updateClientMode();

	// Save the form
	$('#saveform').click(function () {
		$(form).submit();
	});
});
//]]>
</script>

<?php
include('amneziawg/includes/awg_foot.inc');
include('foot.inc');
?>
