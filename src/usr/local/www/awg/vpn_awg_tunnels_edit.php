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

			/*
			 * El sorteo de los parametros de ofuscacion, por ajax, para que el
			 * boton no pierda lo demas que el usuario ya escribio en la pagina.
			 *
			 * El nivel lo manda el selector, pero no se le cree: se topea con
			 * awg_version_ceiling() igual que todo lo demas, o un POST armado a
			 * mano se llevaria campos que este firewall no puede escribir.
			 */
			case 'genobf':
				$level = min((int) ($_POST['awgversion'] ?? 1),
					     awg_version_ceiling());

				print(json_encode(awg_gen_obfuscation($level)));
				exit;
				break;

			/*
			 * La clave de proteccion de headers va aparte del sorteo general
			 * porque es un secreto compartido: el boton de sortear no la puede
			 * tocar sin dejar afuera a los clientes ya entregados.
			 */
			case 'genhpk':
				print(awg_gen_header_protection_key());
				exit;
				break;

			case 'genjunk':
				$payloads = array();

				foreach (array('i1', 'i2', 'i3', 'i4', 'i5') as $payload) {
					$payloads[$payload] = awg_gen_junk_payload();
				}

				print(json_encode($payloads));
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
	 * Todo el juego de ofuscacion se SORTEA, no solo los headers: un tunel nuevo
	 * con valores de fabrica hace que todas las instalaciones del paquete emitan
	 * los mismos bytes, y eso es una firma -- justo lo que este paquete existe
	 * para no dejar. Los I1-I5 quedan vacios: son opcionales y tienen su propio
	 * boton.
	 */
	if (!$_POST) {
		$level = awg_version_ceiling();

		$pconfig = array_merge($pconfig, awg_gen_obfuscation($level));

		/*
		 * La clave de proteccion de headers no sale del sorteo general --es un
		 * secreto compartido, no un parametro-- pero un tunel nuevo si tiene
		 * que nacer con una: es lo unico de 3.0 que da una ganancia clara, y
		 * nadie tiene todavia un .conf que se pueda invalidar.
		 */
		if ($level >= 3) {
			$pconfig['headerprotectionkey'] = awg_gen_header_protection_key();
		}
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
 * Los parametros que separan AmneziaWG de WireGuard: 16 hasta 2.0, 25 con 3.1. La criptografia es la
 * misma; esto cambia la forma de los paquetes para que un DPI no reconozca el
 * handshake por su firma.
 */
$section = new Form_Section('Obfuscation');

/*
 * El selector de version.
 *
 * La pregunta no es "que version tengo" sino QUE ENTIENDE EL EXTREMO MAS DEBIL
 * del tunel, y por eso se puede elegir menos de lo que soporta el backend: la
 * app estable de Android es 2.0.x, y una configuracion con una clave que el
 * cliente no conoce no se degrada, se rechaza entera.
 *
 * Arriba del techo no se ofrece nada, y se dice por que. Las dos razones son
 * independientes -- el backend instalado y lo que este paquete sabe escribir --
 * asi que se informan por separado.
 */
$backend_version = awg_backend_version();
$ceiling         = awg_version_ceiling();
$awg_version     = awg_tunnel_version($pconfig, $ceiling);

// Los campos que existen en la pagina, que es cosa del techo y no de lo elegido.
$awg2 = ($ceiling >= 2);
$awg3 = ($ceiling >= 3);
$awg4 = ($ceiling >= 4);

$version_options = array();

foreach ($awgg['awg_versions'] as $level => $meta) {
	if ($level <= $ceiling) {
		$version_options[$level] = $meta['label'];
	}
}

$version_help = gettext('What the <b>weakest end</b> of this tunnel understands — not what this firewall supports. Anything above the level you pick is left out of both configuration files, even if it has a value stored.');

foreach ($awgg['awg_versions'] as $level => $meta) {
	if ($level <= $ceiling) {
		continue;
	}

	$why = ($level > $backend_version)
	    ? sprintf(gettext('the %s bundled with this package does not understand it'), 'awg(8)')
	    : gettext('this package does not write those parameters yet');

	$version_help .= '<br />' . sprintf(
		gettext('<b>%1$s</b> is not offered: %2$s.'),
		$meta['label'], $why);
}

$section->addInput(new Form_Select(
	'awgversion',
	'Compatibility',
	$awg_version,
	$version_options
))->setHelp($version_help);

$section->addInput(new Form_StaticText(
	'',
	"<span class='text-muted'>{$s(gettext('Both ends of a tunnel must obfuscate identically. If a parameter differs, the handshake simply never completes and nothing logs an error.'))}</span>"
));

/*
 * Volver a sortear todo. Un tunel nuevo ya viene sorteado; el boton esta para
 * el que quiera cambiarlos, y para los tuneles viejos que se crearon con los
 * valores de fabrica que este paquete traia antes.
 */
/*
 * La clase de color va explicita: Form_Button agrega btn-primary solo en la
 * rama SIN icono. Con icono el boton sale con 'btn' pelado, que en este tema
 * pinta el texto del color del fondo -- se lee solo al pasarle el mouse por
 * encima.
 */
$section->addInput(new Form_Button(
	'genobf',
	'Randomise',
	null,
	'fa-solid fa-dice'
))->addClass('btn-primary btn-sm')
  ->setHelp('Draws a fresh set for the level selected above. New tunnels arrive already randomised: the values that matter here are the ones nobody else is using, so a value shipped with the package would be a signature of its own. It does not touch the junk payloads below.');

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
 *
 * Se dibujan si el FIREWALL llega a 2.0, no si el tunel esta en 2.0: el
 * selector los muestra y los esconde sin volver al servidor, y lo que decide si
 * se escriben es awg_obfuscation_pairs(). La clase es de donde los agarra ese
 * javascript.
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

	$group->addClass('awg-v2-only')
	      /*
	       * Las etiquetas van escapadas: el texto de ayuda se imprime como HTML,
	       * asi que <b hex> y <t> los toma el navegador como marcado y los
	       * desaparece -- dejando 'a template: literal bytes, a timestamp, / /
	       * that many random bytes', que no explica nada. Justamente los
	       * ejemplos que hacian falta.
	       */
	      ->setHelp(gettext('Optional junk packets with content you choose, sent before the handshake. Each is a template: <code>&lt;b hex&gt;</code> literal bytes, <code>&lt;t&gt;</code> a timestamp, <code>&lt;r N&gt;</code> / <code>&lt;rc N&gt;</code> / <code>&lt;rd N&gt;</code> that many random bytes, letters or digits.'));

	$section->add($group);

	$section->addInput(new Form_Button(
		'genjunk',
		'Randomise payloads',
		null,
		'fa-solid fa-dice'
	))->addClass('btn-primary btn-sm')
	  ->setHelp('Fills the five with freshly drawn templates. There is no factory template and there will not be one: the same bytes leaving every installation of this package would be a better signature than the one being hidden. For the same reason the examples in Amnezia\'s documentation are not used — they are published.');
}

/*
 * Lo que estrena 3.0. Mismo criterio que arriba: se dibujan si el FIREWALL
 * llega, y el selector los muestra o los esconde sin volver al servidor.
 */
if ($awg3) {
	/*
	 * La clave de proteccion de headers es lo unico de 3.0 que da una ganancia
	 * clara: sin ella el tipo de mensaje viaja en claro y un DPI lo lee. Pero
	 * es un secreto COMPARTIDO, asi que tiene boton propio y no entra en el
	 * sorteo general -- rehacerla deja afuera a todos los clientes que ya
	 * tienen su .conf.
	 */
	$group = new Form_Group('Header Protection');

	$group->add(new Form_Input(
		'headerprotectionkey',
		'HeaderProtectionKey',
		'text',
		$pconfig['headerprotectionkey'] ?? ''
	))->addClass('trim')
	  ->setHelp('HeaderProtectionKey — a 32-byte key in base64')
	  ->setWidth(7);

	$group->addClass('awg-v3-only');

	$section->add($group);

	$section->addInput(new Form_Button(
		'genhpk',
		'New key',
		null,
		'fa-solid fa-key'
	))->addClass('btn-primary btn-sm awg-v3-only')
	  ->setHelp(sprintf('Encrypts the packet header with ChaCha20, so the message type stops being readable. <b>Both ends need the same key</b>, and it travels in the client configuration file — so drawing a new one here locks out every client whose file was already handed over. New tunnels arrive with one already drawn.<br />Its nonce is taken from the first %1$d bytes of each packet\'s padding, so while a key is set <b>S1 through S4 are raised to %1$d</b> above — including S4, the one paid for on every data packet. Drop below 3.0 or clear the key and S4 goes back to 0, which is what makes it free again.', $awgg['header_protection_min_padding']));

	$group = new Form_Group('Content Padding');

	$group->add(new Form_Input(
		'contentpaddingaddition',
		'ContentPaddingAddition',
		'text',
		$pconfig['contentpaddingaddition'] ?? ''
	))->addClass('trim')
	  ->setHelp('ContentPaddingAddition — bytes, or a range such as 12-40')
	  ->setWidth(3);

	$group->addClass('awg-v3-only')
	      ->setHelp(gettext('Extra bytes added to <b>every data packet</b>, not just the handshake, so this one is paid for in throughput and MTU — the same trade S4 makes. Empty means the backend adds nothing.'));

	$section->add($group);

	/*
	 * Los cinco tiempos del protocolo. Hasta 2.0 eran constantes compiladas;
	 * 3.0 los deja elegir para que el ritmo del tunel deje de ser reconocible.
	 * Vacios significa los de fabrica, que es lo que hace todo el mundo.
	 */
	$group = new Form_Group('Protocol Timings');

	foreach (array(
		'rekeyaftertime'	=> array('RekeyAfterTime', 'rekey after'),
		'rekeytimeout'		=> array('RekeyTimeout', 'handshake retry'),
		'rejectaftertime'	=> array('RejectAfterTime', 'session expiry'),
		'keepalivetimeout'	=> array('KeepaliveTimeout', 'keepalive'),
		'maxhandshakeattempts'	=> array('MaxHandshakeAttempts', 'attempts')) as $field => $meta) {
		$group->add(new Form_Input(
			$field,
			$meta[0],
			'text',
			$pconfig[$field] ?? ''
		))->addClass('trim')
		  ->setHelp(sprintf('%1$s — %2$s', $meta[0], $meta[1]))
		  ->setWidth(2);
	}

	$group->addClass('awg-v3-only')
	      ->setHelp(sprintf(gettext('Seconds, or a range such as 100-140; the last one is a count. <b>These are local</b> — each end reads its own and none of them travel in the handshake, so the two sides do not have to match. ' .
			'Leaving them empty keeps WireGuard\'s own values (%1$d, %2$d, %3$d, %4$d and %5$d), which is what everyone else uses, so changing them is only worth it when the timing itself is what gives the tunnel away. ' .
			'A plain <b>0 means empty</b>, not zero: the backend reads it as "unset" and falls back to the same defaults.<br />' .
			'Two of them constrain each other on this side: RejectAfterTime has to stay above KeepaliveTimeout + RekeyTimeout, and RekeyAfterTime below RejectAfterTime. The backend checks neither, and getting either wrong costs throughput without ever looking like a configuration error.'),
			$awgg['timing_defaults']['rekeyaftertime'],
			$awgg['timing_defaults']['rekeytimeout'],
			$awgg['timing_defaults']['rejectaftertime'],
			$awgg['timing_defaults']['keepalivetimeout'],
			$awgg['timing_defaults']['maxhandshakeattempts']));

	$section->add($group);
}

/*
 * Los dos booleanos de 3.1. Van como selector de tres estados y no como
 * checkbox: vacio no es lo mismo que off. Vacio deja la clave fuera del .conf
 * --y un cliente 3.0 la rechazaria entera-- mientras que off la escribe.
 */
if ($awg4) {
	$group = new Form_Group('AmneziaWG 3.1');

	foreach (array(
		'randomtrailers' => array('RandomTrailers', 'random trailing bytes'),
		'disablecookies' => array('DisableCookies', 'no cookie replies')) as $field => $meta) {
		$group->add(new Form_Select(
			$field,
			$meta[0],
			$pconfig[$field] ?? '',
			array('' => gettext('not written'), 'on' => gettext('on'), 'off' => gettext('off'))
		))->setHelp(sprintf('%1$s — %2$s', $meta[0], $meta[1]))
		  ->setWidth(3);
	}

	$group->addClass('awg-v4-only')
	      ->setHelp(gettext('Both ends must agree. Left as <i>not written</i> the key stays out of both configuration files, which is what an AmneziaWG 3.0 client needs — it rejects the whole file over a key it does not know.'));

	$section->add($group);
}

$form->add($section);

/*
 * Los campos que el firewall no alcanza no se dibujan, pero lo que ya este
 * guardado viaja igual en el POST: si no, editar cualquier otra cosa del tunel
 * borraria valores que el usuario nunca vio, y que vuelven a servir en cuanto
 * el backend se actualice.
 *
 * Bajar el selector NO borra nada, por la misma razon: los campos se esconden y
 * dejan de escribirse, pero siguen ahi si mañana se vuelve a subir. Lo que
 * garantiza que no se escriban es awg_obfuscation_pairs(), no esta pantalla.
 *
 * El unico filtro es el nivel del campo contra el techo. Antes esto colgaba
 * ademas de un `if (!$awg2)`, que con una tabla que llegaba hasta 2.0 daba lo
 * mismo --con el techo en 2 no habia nada arriba que preservar-- y con los
 * campos de 3.x pasaba a borrarlos: un firewall en 2.0 editando un tunel que
 * traia valores de 3.0 los perdia sin decir nada.
 */
foreach ($awgg['obfuscation_fields'] as $field => $spec) {
	if ($spec['version'] > $ceiling) {
		$form->addGlobal(new Form_Input(
			$field,
			'',
			'hidden',
			$pconfig[$field] ?? ''
		));
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

/*
 * El boton de guardar va ADENTRO del formulario, como en la pagina de peers.
 *
 * Venia del paquete nativo como un <button type="submit"> suelto en el <nav> de
 * abajo -- fuera de todo formulario, donde un submit no envia nada-- y lo hacia
 * andar un $(form).submit() por javascript. Ese "form" no es una variable
 * declarada en ningun lado, y el formulario que imprime la clase Form no lleva
 * id ni name. El dia que esta pagina tenga un segundo formulario, el que se
 * envia pasa a ser cualquiera: en peers terminaba pidiendo el mail para guardar.
 *
 * Queda arriba de la lista de peers, que es donde termina el formulario del
 * tunel. El <nav> de abajo sigue existiendo para Add Peer, que no guarda nada.
 */
$form->addGlobal(new Form_Button(
	'saveform',
	'Save Tunnel',
	null,
	'fa-solid fa-save'
))->addClass('btn-primary');

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
</nav>

<?php $genKeyWarning = gettext("Overwrite key pair? Click 'ok' to overwrite keys."); ?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	// Supress "Delete" button if there are fewer than two rows
	checkLastRow();

	awgRegTrimHandler();

	/*
	 * El selector de version manda sobre que campos se ven. Solo esconde: lo
	 * que decide que se escribe en los .conf es awg_obfuscation_pairs(), del
	 * lado del servidor, para que bajar de nivel no deje un S3 viejo colandose
	 * en el archivo.
	 *
	 * Los campos de 2.0 pueden no existir en la pagina --contra un backend
	 * 1.x-- y entonces esto no hace nada, que es lo correcto.
	 */
	/*
	 * Con proteccion de headers, los cuatro rellenos tienen que llegar al
	 * minimo: el nonce del ChaCha20 sale de ahi. S4 es el unico que suele estar
	 * en cero --es el que se paga en cada paquete de datos-- asi que subirlo es
	 * parte de prender la clave, y la pantalla lo hace en vez de rebotar el
	 * guardado. Solo si hay clave: sin ella esos bytes no compran nada.
	 *
	 * La validacion del servidor sigue estando: esto es comodidad, no la regla.
	 */
	var awgHpMin = <?=(int) $awgg['header_protection_min_padding']?>;

	function awgEnsurePadding() {
		var aplica = (parseInt($('#awgversion').val(), 10) >= 3) &&
			     ($('#headerprotectionkey').val().trim() !== '');

		$.each(['s1', 's2', 's3', 's4'], function(i, field) {
			var $input = $('#' + field);

			if (!$input.length) {
				return;
			}

			var valor = $input.val().trim();

			if (aplica) {
				if ((valor === '') || (parseInt(valor, 10) < awgHpMin)) {
					/*
					 * Se guarda lo que habia para poder devolverlo: subirlo es
					 * cosa nuestra, asi que bajarlo tambien tiene que serlo.
					 */
					$input.data('awgPrev', valor);
					$input.val(awgHpMin);
				}

				return;
			}

			/*
			 * Ya no hace falta. Se devuelve el valor anterior, pero solo si el
			 * campo sigue teniendo EXACTAMENTE lo que pusimos: si el usuario lo
			 * cambio despues, ese numero es suyo y no se toca.
			 */
			if (parseInt(valor, 10) !== awgHpMin) {
				return;
			}

			var previo = $input.data('awgPrev');

			if (previo !== undefined) {
				$input.val(previo);
				$input.removeData('awgPrev');

				return;
			}

			/*
			 * Sin memoria de esta sesion --la pagina se abrio con el valor ya
			 * guardado-- queda S4, que es el unico de los cuatro con una
			 * respuesta clara: abajo de 3.0 el propio paquete lo sortea en cero,
			 * porque es el unico relleno que se paga en cada paquete de datos.
			 * Los otros tres no se tocan: ahi el minimo no significa nada
			 * especial y el valor bien puede ser una eleccion.
			 */
			if (field === 's4') {
				$input.val(0);
			}
		});
	}

	function awgApplyVersion() {
		var level  = parseInt($('#awgversion').val(), 10);
		var below2 = (level < 2);
		var below3 = (level < 3);
		var below4 = (level < 4);

		hideGroupInput('s3', below2);
		hideGroupInput('s4', below2);
		hideClass('awg-v2-only', below2);
		hideInput('genjunk', below2);

		hideClass('awg-v3-only', below3);
		hideInput('genhpk', below3);
		hideClass('awg-v4-only', below4);

		awgEnsurePadding();
	}

	$('#awgversion').change(awgApplyVersion);
	awgApplyVersion();

	// Botones de accion, no de submit: sin esto guardan el tunel.
	$('#genobf, #genjunk, #genhpk').prop('type', 'button');

	/*
	 * El sorteo va al servidor en vez de hacerse en javascript por dos razones:
	 * el sorteo bueno esta en PHP --random_int, no Math.random-- y asi la
	 * pagina, el alta y cualquier otro camino sortean con el MISMO codigo. Dos
	 * generadores separados se desincronizan sin que nada falle.
	 */
	$('#genobf').click(function(event) {
		$.ajax({
			url: '/awg/vpn_awg_tunnels_edit.php',
			type: 'post',
			data: {act: 'genobf', awgversion: $('#awgversion').val()},
			success: function(response) {
				var gen = JSON.parse(response);

				for (var field in gen) {
					$('#' + field).val(gen[field]);
				}
			}
		});
	});

	$('#genjunk').click(function(event) {
		$.ajax({
			url: '/awg/vpn_awg_tunnels_edit.php',
			type: 'post',
			data: {act: 'genjunk'},
			success: function(response) {
				var gen = JSON.parse(response);

				for (var field in gen) {
					$('#' + field).val(gen[field]);
				}
			}
		});
	});

	/*
	 * La clave nueva se pide y se pone, pero se avisa antes: es un secreto
	 * compartido y cambiarlo deja afuera a los clientes que ya tienen su .conf.
	 * El aviso va aca y no en el texto de ayuda porque el texto se lee despues
	 * de apretar.
	 */
	$('#genhpk').click(function(event) {
		if ($('#headerprotectionkey').val().trim() !== '' &&
		    !confirm('This tunnel already has a header protection key. Drawing a new one will lock out every client whose configuration file was already handed over. Continue?')) {
			return;
		}

		$.ajax({
			url: '/awg/vpn_awg_tunnels_edit.php',
			type: 'post',
			data: {act: 'genhpk'},
			success: function(response) {
				$('#headerprotectionkey').val(response);

				// Recien ahora hay clave, asi que el relleno pasa a hacer falta
				awgEnsurePadding();
			}
		});
	});

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

});
//]]>
</script>

<?php
include('amneziawg/includes/awg_foot.inc');
include('foot.inc');
?>
