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
require_once('amneziawg/includes/awg_mail.inc');

global $awgg;

// Initialize $awgg state
awg_globals();

$pconfig = [];
$is_new = true;
$peer_idx = null;
$tun_name = null;
$act = '';
$input_errors = array();
$savemsg = null;

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
						awg_client_apply_changes();
					}
				}

				/*
				 * Guardar un peer con cliente vuelve a su propia pagina y no a
				 * la lista: ahi abajo esta el QR y el archivo recien generado,
				 * que es lo que se vino a buscar. Un peer sin cliente no tiene
				 * nada mas que mostrar, asi que ese sale a la lista.
				 *
				 * El redirect se pide, pero la pagina NO depende de el: si algo
				 * ya mando salida, header() no hace nada y se sigue dibujando
				 * aca mismo. Sin la linea de abajo, ese caso dibuja el
				 * formulario sin el panel del cliente -- $peer_idx sale de
				 * $_REQUEST['peer'], que en un alta no viene -- y el QR recien
				 * generado no aparece hasta volver a entrar por el lapiz.
				 */
				if (!is_null($res['peer_idx']) && ($pconfig['client_enable'] == 'yes')) {
					$peer_idx = $res['peer_idx'];

					header("Location: /awg/vpn_awg_peers_edit.php?peer={$peer_idx}");
				} else {
					header('Location: /awg/vpn_awg_peers.php');
				}
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

		case 'email':
			/*
			 * Se rearma igual que la descarga, y por lo mismo: lo que se manda
			 * es lo que esta guardado, no lo que muestra el formulario de
			 * arriba, que puede tener cambios sin guardar.
			 */
			$conf = awg_client_conf_from_peer($peer_idx, $error);

			if ($conf === false) {
				$input_errors[] = $error;
				break;
			}

			[$ml_idx, $ml_peer, $ml_is_new] = awg_peer_get_config($peer_idx, false);

			$sent = awg_mail_send_client_conf($_POST['email'] ?? '',
				$conf,
				awg_client_conf_filename($ml_peer['descr']),
				$ml_peer['descr']);

			if ($sent['success']) {
				$savemsg = $sent['message'];
			} else {
				$input_errors[] = $sent['message'];
			}

			break;

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
 *
 * Mandar el archivo por mail entra por aca aunque sea un post: no toca el peer
 * ni el formulario, asi que la pagina tiene que quedar igual que si se hubiera
 * entrado por GET. Sin esto el formulario se dibuja vacio despues de enviar.
 */
if (!$_POST || ($act == 'email')) {
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

		// Del peer y nunca del store: es ruteo de este firewall, no del cliente
		$pconfig['gateway']		= $peer['gateway'] ?? '';
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
		$pconfig['gateway']		= '';
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

if (!empty($savemsg)) {
	print_info_box($savemsg, 'success');
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
))->setHelp("AmneziaWG tunnel for this peer. Its listen port, MTU and the obfuscation parameters " .
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

/*
 * El grupo se dibuja en los DOS modos, y el campo cambia de significado entre
 * ellos: generando es la direccion de este firewall, sin generar es la del otro
 * extremo. La ayuda la reescribe updateClientMode() para que diga cual de las
 * dos es, porque una sola redaccion que cubra ambas no se entiende.
 *
 * Sin asterisco a proposito: es obligatorio solo generando. Sin generar, vacio
 * es un peer que disca hacia aca, que es un caso legitimo y el mas comun.
 */
$group = new Form_Group('Endpoint');

$group->add(new Form_Input(
	'endpoint',
	'Endpoint',
	'text',
	$pconfig['endpoint'],
	['placeholder' => 'vpn.example.com']
))->addClass('trim')
  ->setHelp('Hostname, IPv4, or IPv6 address of this firewall as reachable by the client. ' .
	    'This is where the client dials in, and it is written to the client file. ' .
	    'It is not the peer endpoint: the firewall never dials a client, it learns ' .
	    'where the client is from the handshake it receives.')
  ->setWidth(4);

/*
 * Un select al lado del campo y no un datalist encima: un datalist filtra sus
 * sugerencias contra lo que ya este escrito, y este campo llega con algo puesto,
 * asi que el resto de la lista quedaria escondida.
 *
 * Este SI queda solo para el modo cliente, al reves que el campo de al lado: lo
 * que sugiere son las direcciones de ESTE firewall, que es lo que hay que poner
 * cuando el endpoint es adonde disca el cliente. Sin generar, el endpoint es el
 * del otro extremo y ninguna direccion de aca sirve.
 *
 * La clase va en ->column y no en el select: hideClass() esconde el elemento que
 * la lleva, y en el select dejaria colgados su label y su ayuda. ->column es el
 * <div> que los envuelve a los tres.
 */
$detected = new Form_Select(
	'endpoint_detected',
	'Detected',
	'',
	array_merge(array('' => gettext('Detected on this firewall...')),
	            awg_client_endpoint_candidates()));

$detected->column->addClass('clientonly');

$group->add($detected)
  ->setHelp('Dynamic DNS hostnames and interface addresses already configured here. ' .
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

/*
 * Las dos redacciones de la ayuda, una por modo. Van por json_encode y no
 * escritas en el javascript para que gettext las siga viendo.
 */
$endpoint_help = array(
	'client' => gettext('Hostname, IPv4, or IPv6 address of this firewall as reachable by the client. ' .
			    'This is where the client dials in, and it is written to the client file. ' .
			    'It is not the peer endpoint: the firewall never dials a client, it learns ' .
			    'where the client is from the handshake it receives.'),
	'manual' => gettext('Hostname, IPv4, or IPv6 address of the remote peer, where this firewall dials out. ' .
			    'This is the peer endpoint in the WireGuard sense, and it is what a configuration ' .
			    'imported from another firewall or a provider fills in. ' .
			    'Leave it empty for a peer that dials in here instead: its address is learned ' .
			    'from the handshake it sends.'));

$port_help = array(
	'client' => gettext('Listen port of the tunnel. Required: a client rejects an endpoint without one.'),
	'manual' => gettext('Port the remote peer listens on. Required whenever an endpoint is set: ' .
			    'the backend rejects an endpoint without one.'));

$section->add($group);

/*
 * Por que gateway sale el trafico HACIA este peer.
 *
 * Solo tiene sentido discando: sin endpoint no hay a donde ir, y generando un
 * cliente el peer es roadwarrior por definicion. De ahi el 'manualonly', que es
 * el inverso de 'clientonly'.
 *
 * Lo que hace por debajo es una ruta de host al endpoint, no un binding del
 * socket: FreeBSD no tiene SO_BINDTODEVICE, y forzar solo la direccion de
 * origen deja el paquete saliendo igual por donde diga la ruta -- con el origen
 * de una WAN y la salida por otra, que es lo que un ISP descarta.
 */
$section->addInput(new Form_Select(
	'gateway',
	'Gateway',
	$pconfig['gateway'],
	awg_gateway_list()
))->addClass('manualonly')
  ->setHelp('Which WAN this firewall uses to reach the peer, when the routing table would otherwise pick another one. ' .
	    'Only applies while an endpoint is set above.<br />' .
	    '<span class="text-danger">Note: </span>this installs a host route to the endpoint address, so <b>all</b> traffic to that ' .
	    'address takes the chosen gateway, not only the tunnel. The route is removed when the peer, its gateway or the ' .
	    'AmneziaWG service goes away, and an address that already has a host route from elsewhere is left alone. ' .
	    '(<a href="/system_gateways.php">' . gettext('Gateways') . '</a>)');

$section->addInput(new Form_Input(
	'persistentkeepalive',
	'Keep Alive',
	'text',
	$pconfig['persistentkeepalive'],
	['placeholder' => 'Keep Alive']
))->addClass('trim')
  ->setHelp('Interval (in seconds) for Keep Alive packets.<br />
	     Generating a client, this goes in its file and the client sends them. Otherwise it is
	     this firewall that sends them to the peer.<br />
	     Recommended for whichever side sits behind NAT. Leave blank to disable.');

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

/*
 * Los alias se ofrecen al lado del campo pero no se mandan nunca: AmneziaWG no
 * sabe lo que es un alias, asi que elegir uno vuelca su contenido en el campo y
 * lo que se guarda son las direcciones.
 */
$alias_presets = awg_client_alias_presets();

$alias_options = $alias_networks = array();

foreach ($alias_presets as $alias_name => $alias_info) {
	$alias_options[$alias_name] = $alias_info['label'];
	$alias_networks[$alias_name] = $alias_info['networks'];
}

// Solo en un grupo hace falta que el grupo lleve la etiqueta
$networks_input = new Form_Input(
	'client_allowedips',
	empty($alias_options) ? '*Tunneled Networks' : 'Tunneled Networks',
	'text',
	$pconfig['client_allowedips'],
	['placeholder' => '0.0.0.0/0, ::/0', 'autocomplete' => 'off']
);

$networks_input->addClass('trim')
	->setHelp('Networks the client routes into the tunnel. Written as <code>AllowedIPs</code> in the client file.');

if (empty($alias_options)) {
	$section->addInput($networks_input);
} else {
	$group = new Form_Group('*Tunneled Networks');

	$group->add($networks_input)->setWidth(6);

	$group->add(new Form_Select(
		'allowedips_alias',
		'Aliases',
		'',
		array_merge(array('' => gettext('Aliases...')), $alias_options)
	))->setHelp('Firewall aliases holding networks. Picking one adds its addresses to the field on the left. ' .
		    '(<a href="/firewall_aliases.php">' . gettext('Aliases') . '</a>)')
	  ->setWidth(4);

	$section->add($group);
}

$group = new Form_Group('DNS Servers');

$group->add(new Form_Input(
	'dns',
	'DNS Servers',
	'text',
	$pconfig['dns'],
	['placeholder' => 'DNS Servers']
))->addClass('trim')
  ->setHelp('Optional. Separate multiple entries with commas.<br />
	     Leave blank to keep the DNS servers already configured on the client.')
  ->setWidth(4);

$group->add(new Form_Select(
	'dns_preset',
	'DNS Preset',
	'',
	array_merge(array('' => gettext('Presets...')), awg_client_dns_presets())
))->setHelp('Picking one fills the field on the left. Full tunnel sets the tunnel address of this firewall ' .
	    'automatically; for internal name resolution on a split tunnel, type that address here by hand.')
  ->setWidth(6);

$section->add($group);

$section->addInput(new Form_Input(
	'mtu',
	'MTU',
	'text',
	$pconfig['mtu'],
	['placeholder' => 'MTU']
))->addClass('trim')
  ->setHelp('Optional. Filled in from the tunnel when it does not run on the default of ' .
	    "{$awgg['default_mtu']}, which is what a client assumes on its own. Leave blank to let the client decide.");

$form->add($section);

/*
 * Aplicar va en su propia seccion y no al final de la anterior, que es
 * 'clientonly': ahi desaparecia junto con ella, y un peer site-to-site --o uno
 * que dejo armado una importacion-- se guardaba sin poder aplicarse desde esta
 * pagina. El cambio no se perdia, quedaba pendiente para el boton de la lista,
 * pero no habia forma de saberlo desde aca.
 */
$section = new Form_Section('Apply');

$section->addInput(new Form_Checkbox(
	'applynow',
	'Apply',
	gettext('Apply the changes immediately'),
	$pconfig['applynow'] == 'yes'
))->setHelp('<span class="text-danger">Note: </span>This action may momentarily suspend active AmneziaWG peer connections on the changed tunnels.');

$form->add($section);

$form->addGlobal(new Form_Input(
	'act',
	'',
	'hidden',
	'save'
));

/*
 * El boton de guardar va ADENTRO del formulario, no en un <nav> de abajo.
 *
 * El arbol venia del paquete nativo, donde el boton es un <button> suelto
 * despues de print($form) -- o sea fuera de todo formulario -- y lo hace andar
 * un $(form).submit() por javascript. Eso funciona mientras haya un solo
 * formulario en la pagina. Abajo hay dos mas, el de descarga y el de mail, y el
 * de mail tiene un campo required: apretar Save terminaba pidiendo la direccion
 * de correo para guardar un peer.
 *
 * Un Form_Button lo pone adentro del formulario que corresponde, que es lo que
 * hace wgeasy, y de paso la pagina sigue guardando con javascript deshabilitado.
 */
$form->addGlobal(new Form_Button(
	'saveform',
	'Save Peer',
	null,
	'fa-solid fa-save'
))->addClass('btn-primary');

print($form);

/*
 * El archivo del cliente, para llevarselo.
 *
 * Va en la pagina de edicion y no en un panel que aparece una sola vez despues
 * de guardar: asi se puede volver por el QR cuando haga falta -- que es lo
 * normal, el telefono no siempre esta a mano en el momento de crear el peer --
 * sin tener que re-clavear al cliente.
 *
 * Ojo: el .conf va adentro del HTML, con la clave privada. Es el mismo limite
 * de confianza que la descarga, pero conviene tenerlo presente.
 */
$client_conf = $client_exportable ? awg_client_conf_from_peer($peer_idx) : false;

if ($client_conf !== false):
	$qrcode_js = awg_client_qrcode_js_url();
?>

<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Client File')?></h2></div>
	<div class="panel-body">
		<div class="row">
			<div class="col-sm-7">
				<textarea id="awg_conf" class="form-control" rows="14" readonly="readonly" spellcheck="false"><?=htmlspecialchars($client_conf)?></textarea>
				<br />
				<!--
					Formulario propio: este panel se dibuja despues de print($form),
					o sea fuera del formulario principal, y un submit suelto no
					mandaria nada.
				-->
				<form action="/awg/vpn_awg_peers_edit.php" method="post" style="display: inline;">
					<input type="hidden" name="index" value="<?=htmlspecialchars((string) $peer_idx)?>" />
					<input type="hidden" name="downloadconf" value="download" />
					<button type="submit" id="downloadconf" class="btn btn-primary btn-sm">
						<i class="fa-solid fa-download icon-embed-btn"></i>
						<?=gettext('Download')?>
					</button>
				</form>
				<button type="button" id="awg_copy" class="btn btn-default btn-sm" data-success-text="<?=gettext('Copied')?>" data-timeout="3000">
					<i class="fa-solid fa-clipboard icon-embed-btn"></i>
					<span id="awg_copy_label"><?=gettext('Copy')?></span>
				</button>
				<a id="awg_qrdownload" href="#" class="btn btn-default btn-sm" style="display: none;">
					<i class="fa-solid fa-qrcode icon-embed-btn"></i>
					<?=gettext('Download QR')?>
				</a>
				<div id="awg_qr_hidden" style="display: none;"></div>
				<span class="help-block"><?=gettext('This is what is saved right now, not what the form above shows. Save first to include any change.')?></span>
				<!--
					El envio por mail va en su propio formulario por lo mismo
					que la descarga: este panel se dibuja fuera del principal.
				-->
				<form action="/awg/vpn_awg_peers_edit.php" method="post" class="form-inline">
					<input type="hidden" name="peer" value="<?=htmlspecialchars((string) $peer_idx)?>" />
					<input type="hidden" name="act" value="email" />
					<div class="form-group">
						<label for="email"><?=gettext('Send by email')?>&nbsp;</label>
						<input type="email" name="email" id="email" class="form-control" placeholder="user@example.com" size="32" required="required" />
					</div>
					<button type="submit" class="btn btn-primary btn-sm" title="<?=gettext('Send the client configuration by email')?>">
						<i class="fa-solid fa-paper-plane icon-embed-btn"></i>
						<?=gettext('Send')?>
					</button>
					<span class="help-block">
						<?=gettext('Uses the SMTP server configured under System > Advanced > Notifications. Email is not encrypted in transit end to end; prefer the QR code or the download for sensitive deployments.')?>
					</span>
				</form>
			</div>
			<div class="col-sm-5 text-center">
				<div id="awg_qr"></div>
<?php if (is_null($qrcode_js)): ?>
				<div class="alert alert-warning" role="alert">
					<?=gettext('The QR code library was not found. Copy a qrcode.js build to /usr/local/www/awg/js/awg_qrcode.js.')?>
				</div>
<?php else: ?>
				<span class="help-block"><?=gettext('Scan with the AmneziaWG app on Android or iOS.')?></span>
<?php endif; ?>
			</div>
		</div>
	</div>
</div>

<?php if (!is_null($qrcode_js)): ?>
<script src="<?=htmlspecialchars($qrcode_js)?>"></script>
<script src="<?=htmlspecialchars(awg_client_asset_url('/awg/js/awg_qr.js'))?>"></script>
<?php endif; ?>

<?php endif; ?>

<?php
$genkeywarning = gettext("Overwrite pre-shared key? Click 'ok' to overwrite key.");
$genkeyswarning = gettext("Overwrite the client key pair? The client configuration already handed out would stop working. Click 'ok' to overwrite.");

// Lo que cada tunel puede contestar por si mismo, para no volver al servidor
// cada vez que se cambia la seleccion.
$tunnel_hints = awg_client_tunnel_hints();

/*
 * Escapado para todo lo que se emite adentro de un <script>. Sin esto, una
 * descripcion de tunel o de alias con un '</script>' adentro cerraria el bloque
 * y lo que siguiera se ejecutaria como HTML.
 */
$jsflags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	var awgHints = <?=json_encode($tunnel_hints, $jsflags)?>;

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
		if ($('#presharedkey').val().length == 0 || confirm(<?=json_encode($genkeywarning, $jsflags)?>)) {
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
		if ($('#privatekey').val().length == 0 || confirm(<?=json_encode($genkeyswarning, $jsflags)?>)) {
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

	// Idem el preset de DNS, salvo el centinela de "ninguno", que si viaja
	$('#dns_preset').change(function() {
		if ($(this).val().length > 0) {
			$('#dns').val($(this).val() == '<?=AWG_DNS_NONE?>' ? '' : $(this).val());
		}
	});

	/*
	 * Un alias AGREGA sus redes a lo que ya haya, sin repetir: la lista se arma
	 * juntando varios, y pisarla obligaria a elegir uno solo.
	 */
	var awgAliases = <?=json_encode($alias_networks, $jsflags)?>;

	function splitList(value) {
		return String(value).split(',').map(function(s) {
			return s.trim();
		}).filter(function(s) {
			return s.length > 0;
		});
	}

	$('#allowedips_alias').change(function() {
		var networks = awgAliases[$(this).val()];

		if (!networks) {
			return;
		}

		var current = splitList($('#client_allowedips').val());

		splitList(networks).forEach(function(network) {
			if (current.indexOf(network) === -1) {
				current.push(network);
			}
		});

		$('#client_allowedips').val(current.join(', '));

		// Ya no es ninguno de los dos presets
		$('#routing').val('custom');

		// Volver el desplegable a su leyenda, para poder elegir otro
		$(this).val('');
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
	 *
	 * El Endpoint es el unico campo que sobrevive a los dos modos cambiando de
	 * significado --este firewall generando, el otro extremo sin generar-- asi
	 * que se le reescribe la ayuda en vez de esconderlo.
	 */
	var awgEndpointHelp	= <?=json_encode($endpoint_help, $jsflags)?>;
	var awgPortHelp		= <?=json_encode($port_help, $jsflags)?>;

	function updateClientMode() {
		var generating = $('#client_enable').prop('checked');
		var mode = generating ? 'client' : 'manual';

		hideClass('clientonly', !generating);

		// El inverso: lo que solo sirve cuando este firewall es el que disca
		hideClass('manualonly', generating);

		$('#publickey').prop('readonly', generating);

		$('#endpoint').siblings('.help-block').text(awgEndpointHelp[mode]);
		$('#port').siblings('.help-block').text(awgPortHelp[mode]);
	}

	$('#client_enable').click(updateClientMode);

	updateClientMode();

<?php if (($client_conf !== false) && !is_null($qrcode_js)): ?>
	// Dibujar y descargar el codigo vive en awg_qr.js, porque la lista de peers
	// hace lo mismo desde su icono de QR.
	awgQr.size		= <?=(int) $awgg['qr_size']?>;
	awgQr.displaySize	= <?=(int) $awgg['qr_display_size']?>;
	awgQr.quietZone		= <?=(int) $awgg['qr_quiet_zone']?>;
	awgQr.level		= <?=json_encode($awgg['qr_level'], $jsflags)?>;

	var awgConfName = <?=json_encode(awg_client_conf_filename($pconfig['descr']), $jsflags)?>;

	awgQr.render('awg_qr', $('#awg_conf').val());

	$('#awg_qrdownload').show().click(function(event) {
		event.preventDefault();

		awgQr.download($('#awg_conf').val(), awgConfName.replace(/\.conf$/, ''));
	});

	$('#awg_copy').click(function() {
		var $label = $('#awg_copy_label');
		var original = $label.text();

		navigator.clipboard.writeText($('#awg_conf').val());

		$label.text($(this).attr('data-success-text'));

		setTimeout(function() {
			$label.text(original);
		}, $(this).attr('data-timeout'));
	});
<?php endif; ?>
});
//]]>
</script>

<?php
include('amneziawg/includes/awg_foot.inc');
include('foot.inc');
?>
