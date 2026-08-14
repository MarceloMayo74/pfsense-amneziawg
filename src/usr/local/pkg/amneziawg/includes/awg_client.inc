<?php
/*
 * awg_client.inc
 *
 * Generacion del archivo de configuracion que se le entrega al cliente.
 *
 * Es la pieza que ninguna de las referencias tiene: el paquete de OPNsense no
 * genera configs de cliente, y wgeasy las genera para WireGuard, donde no hay
 * ofuscacion que copiar. La regla que gobierna todo este archivo es que los dos
 * extremos tienen que ofuscar IDENTICO -- si el cliente manda un Jc distinto o
 * un H1 distinto, no hay handshake y el sintoma es un tunel que simplemente no
 * levanta, sin ningun error que apunte a la causa.
 *
 * Por eso el bloque de ofuscacion no se arma aca: sale de awg_obfuscation_pairs(),
 * la misma funcion que escribe el .conf del servidor.
 *
 * OJO con validar esto a mano en un firewall: el .conf del cliente NO se puede
 * pasar por "awg setconf". Address, DNS y MTU son directivas de awg-quick, no
 * del backend, y awg(8) rechaza el archivo entero por ellas. Para probarlo con
 * awg(8) hay que sacar esas tres lineas primero.
 */

require_once('config.inc');
require_once('util.inc');

/*
 * Arma el .conf del cliente y lo devuelve como texto.
 *
 * $conf espera:
 *
 *   descr                 nombre del cliente, va como comentario
 *   privatekey            la del CLIENTE, no la del tunel
 *   address               una o varias, separadas por coma
 *   dns                   opcional
 *   mtu                   opcional
 *   publickey             la del TUNEL, que es el peer del cliente
 *   presharedkey          opcional
 *   allowedips            lo que el cliente rutea hacia el tunel
 *   endpoint              host:puerto por donde llegar al firewall
 *   persistentkeepalive   opcional, 0 lo omite
 *   obfuscation           array('Jc' => '4', ...) de awg_obfuscation_pairs()
 *
 * Devuelve false y carga $error si falta algo sin lo cual el archivo no sirve.
 */
function awg_client_build_conf($conf, &$error = null) {
	/*
	 * Sin cualquiera de estos cuatro el archivo se genera igual pero el cliente
	 * no conecta, asi que es mejor negarse a generarlo que entregar algo que
	 * falla despues, en la maquina del usuario y sin mensaje.
	 */
	foreach (array('privatekey', 'address', 'publickey', 'allowedips') as $required) {
		if (empty(trim((string) ($conf[$required] ?? '')))) {
			$error = sprintf(gettext('The client configuration needs a %s.'), $required);
			return false;
		}
	}

	$lines = array();

	if (!empty($conf['descr'])) {
		$lines[] = "# {$conf['descr']}";
	}

	$hostname = trim((string) config_get_path('system/hostname'));
	$now = new DateTimeImmutable();

	$lines[] = "# AmneziaWG - generado por {$hostname} el {$now->format('Y-m-d H:i:s')}";
	$lines[] = '# Contiene una clave privada. No lo compartas.';
	$lines[] = null;

	$lines[] = '[Interface]';
	$lines[] = "PrivateKey = {$conf['privatekey']}";
	$lines[] = "Address = {$conf['address']}";

	if (!empty($conf['dns'])) {
		$lines[] = "DNS = {$conf['dns']}";
	}

	if (!empty($conf['mtu'])) {
		$lines[] = "MTU = {$conf['mtu']}";
	}

	/*
	 * La ofuscacion va en [Interface] y tiene que ser la misma que la del
	 * servidor. Que este vacio es un error de quien llama -- un tunel siempre
	 * tiene al menos H1-H4, que se sortean al crearlo -- pero no se aborta:
	 * un .conf sin ofuscacion es un WireGuard valido, y romper la descarga
	 * seria peor que entregar algo que al menos conecta contra un tunel que
	 * tampoco ofusque.
	 */
	foreach ((array) ($conf['obfuscation'] ?? array()) as $key => $value) {
		$lines[] = "{$key} = {$value}";
	}

	$lines[] = null;

	$lines[] = '[Peer]';
	$lines[] = "PublicKey = {$conf['publickey']}";

	if (!empty($conf['presharedkey'])) {
		$lines[] = "PresharedKey = {$conf['presharedkey']}";
	}

	$lines[] = "AllowedIPs = {$conf['allowedips']}";

	if (!empty($conf['endpoint'])) {
		$lines[] = "Endpoint = {$conf['endpoint']}";
	}

	$keepalive = $conf['persistentkeepalive'] ?? 0;

	if (is_numericint($keepalive) && ($keepalive > 0)) {
		$lines[] = "PersistentKeepalive = {$keepalive}";
	}

	$lines[] = null;

	return implode("\n", $lines);
}

/*
 * Las direcciones desde las que repartirle direcciones a los clientes.
 *
 * Con el tunel asignado como interfaz de pfSense, las filas de direcciones que
 * quedaron guardadas en el tunel NO son la verdad: la direccion la administra
 * la interfaz. Leer las filas viejas da sugerencias de una subred que ya no
 * existe, que es un bug que el proyecto hermano ya pago. Si la interfaz esta
 * caida y no tiene direccion, la respuesta honesta es ninguna -- las filas
 * viejas solo parecerian correctas.
 */
function awg_client_tunnel_address_rows($tunnel_name) {
	$a_ret = array();

	if (!([$tun_idx, $tunnel, $is_new] = awg_tunnel_get_config_by_name($tunnel_name))) {
		return $a_ret;
	}

	if (is_awg_tunnel_assigned($tunnel['name'])) {
		if (!($pfsense_if = awg_get_pfsense_interface_info($tunnel['name']))) {
			return $a_ret;
		}

		$if = $pfsense_if['name'];

		$address = get_interface_ip($if);
		$bits = get_interface_subnet($if);

		if (is_ipaddrv4($address) && is_numericint($bits)) {
			$a_ret[] = array('address' => $address, 'mask' => (string) $bits);
		}

		return $a_ret;
	}

	foreach ((array) $tunnel['addresses']['row'] as $address) {
		if (is_ipaddr($address['address'] ?? '')) {
			$a_ret[] = array('address' => $address['address'],
			                 'mask'    => (string) $address['mask']);
		}
	}

	return $a_ret;
}

/*
 * Elige la primera direccion libre de una lista de subredes.
 *
 * Esta separada de quien junta los datos a proposito: es donde viven los
 * errores de borde -- la direccion de red, la de broadcast, una /31 donde no
 * entra ningun host, una subred entera ocupada -- y asi se puede probar sin un
 * pfSense al lado.
 *
 * $rows son filas array('address' => ..., 'mask' => ...), $used las direcciones
 * ya tomadas. Devuelve algo como '10.10.10.2/32', o null si no queda ninguna.
 */
function awg_client_pick_address($rows, $used) {
	global $awgg;

	foreach ((array) $rows as $address) {
		$masked_address = "{$address['address']}/{$address['mask']}";

		/*
		 * Una /31 o una /32 no tiene lugar para un cliente: entre la direccion
		 * de red y la de broadcast no queda ningun host.
		 */
		if (!is_ipaddrv4($address['address'])
		    || !is_subnet($masked_address)
		    || ($address['mask'] >= 31)) {
			continue;
		}

		$network = gen_subnet($address['address'], $address['mask']);
		$last = gen_subnet_max($address['address'], $address['mask']);

		// La primera utilizable es la que sigue a la de red; $last es la de
		// broadcast, y por eso la comparacion es estricta.
		$candidate = ip_after($network);
		$probes = 0;

		while (is_ipaddrv4($candidate)
		    && ip_less_than($candidate, $last)
		    && ($probes++ < $awgg['max_address_probe'])) {
			if (!in_array($candidate, (array) $used, true)) {
				return "{$candidate}/32";
			}

			$candidate = ip_after($candidate);
		}
	}

	return null;
}

/*
 * La proxima direccion libre de un tunel: su propia direccion y las que ya
 * tienen los peers cuentan como ocupadas.
 */
function awg_client_next_address($tunnel_name) {
	$used = array();

	foreach (awg_client_tunnel_address_rows($tunnel_name) as $address) {
		$used[] = $address['address'];
	}

	foreach (awg_tunnel_get_peers_config($tunnel_name) as [$peer_idx, $peer, $peer_is_new]) {
		foreach ((array) $peer['allowedips']['row'] as $row) {
			if (is_ipaddrv4($row['address'] ?? '')) {
				$used[] = $row['address'];
			}
		}
	}

	return awg_client_pick_address(awg_client_tunnel_address_rows($tunnel_name), $used);
}

/*
 * Los datos de cliente guardados en un peer. Un peer creado a mano no tiene
 * ninguno, asi que todo consumidor tiene que aguantar un array vacio.
 */
function awg_client_store($peer) {
	global $awgg;

	if (!is_array($peer) || !isset($peer[$awgg['client_store']])) {
		return array();
	}

	return is_array($peer[$awgg['client_store']]) ? $peer[$awgg['client_store']] : array();
}

/*
 * True cuando el peer tiene con que rearmar su archivo de cliente.
 *
 * La clave privada es la condicion: sin ella el firewall no puede reconstruir
 * el .conf, y no hay forma de recuperarla despues.
 */
function awg_client_is_exportable($peer) {
	$store = awg_client_store($peer);

	return (!empty($store['privatekey']) && awg_is_valid_key($store['privatekey']));
}

/*
 * Escribe los datos de cliente sobre un peer ya guardado.
 *
 * Corre DESPUES de awg_do_peer_post(), que es lo que deja intacto el camino de
 * validacion y sincronizacion del peer: aca solo se agrega un sub-elemento.
 */
function awg_client_save_store($peer_idx, $store) {
	global $awgg;

	if (!is_numericint($peer_idx)
	    || !is_array(config_get_path("installedpackages/amneziawg/peers/item/{$peer_idx}"))) {
		return false;
	}

	config_set_path("installedpackages/amneziawg/peers/item/{$peer_idx}/" . $awgg['client_store'],
	                $store);

	awg_write_config("Saved client settings for peer {$peer_idx}.", true);

	return true;
}

/*
 * Rearma el .conf de un cliente a partir de lo guardado en su peer.
 *
 * Devuelve false si el peer no es exportable o si su tunel desaparecio.
 */
function awg_client_conf_from_peer($peer_idx, &$error = null) {
	if (!([$peer_idx, $peer, $is_new] = awg_peer_get_config($peer_idx, false))) {
		$error = gettext('That peer no longer exists.');
		return false;
	}

	$store = awg_client_store($peer);

	if (!awg_client_is_exportable($peer)) {
		$error = gettext('That peer has no client configuration to export.');
		return false;
	}

	if (!([$tun_idx, $tunnel, $tun_is_new] = awg_tunnel_get_config_by_name($peer['tun']))) {
		$error = gettext('The tunnel this peer belongs to no longer exists.');
		return false;
	}

	$publickey = awg_gen_publickey($tunnel['privatekey']);

	return awg_client_build_conf(array(
		'descr'			=> $peer['descr'],
		'privatekey'		=> $store['privatekey'],
		'address'		=> $store['address'] ?? '',
		'dns'			=> $store['dns'] ?? '',
		'mtu'			=> $store['mtu'] ?? '',
		'publickey'		=> $publickey['pubkey'],
		'presharedkey'		=> $peer['presharedkey'] ?? '',
		'allowedips'		=> $store['allowedips'] ?? '',
		'endpoint'		=> $store['endpoint'] ?? '',
		'persistentkeepalive'	=> $store['persistentkeepalive'] ?? 0,
		'obfuscation'		=> awg_obfuscation_pairs($tunnel)), $error);
}

/*
 * Resuelve el par de claves del cliente y deja su clave publica en el POST,
 * como publickey del peer.
 *
 * Tiene que correr ANTES de awg_do_peer_post(). La clave publica del peer y la
 * privada del cliente son las dos mitades de la misma cosa: si el usuario tilda
 * "generar" y deja Public Key vacio, la validacion nativa rechaza el formulario
 * por campo obligatorio antes de que nadie llegue a generar nada. Y si el
 * usuario cargo las dos por separado y no se corresponden, el cliente no
 * conecta -- por eso la publica se DERIVA siempre de la privada en vez de
 * confiar en lo que haya escrito.
 *
 * Se guarda la forma clampeada de la privada, que es la que usa el backend:
 * asi la publica que ve el servidor y la que deriva el cliente son la misma.
 */
function awg_client_prepare_post($post, &$input_errors = array()) {
	if (empty($post['client_enable'])) {
		return $post;
	}

	$privatekey = trim((string) ($post['client_privatekey'] ?? ''));

	if ($privatekey === '') {
		$keypair = awg_gen_keypair();
	} elseif (awg_is_valid_key($privatekey)) {
		$keypair = awg_gen_publickey($privatekey);
	} else {
		$input_errors[] = gettext('The client private key is not a valid key.');
		return $post;
	}

	$post['client_privatekey'] = $keypair['privkey_clamped'];
	$post['publickey'] = $keypair['pubkey'];

	return $post;
}

/*
 * Arma los datos de cliente a partir del POST de la pagina de peers.
 *
 * Completa en vez de rechazar: sin clave privada sortea uno, sin direccion
 * toma la proxima libre. Es deliberado -- el modo normal de uso es tildar la
 * casilla y guardar, y hacer que eso falle con "falta la clave privada" seria
 * pedirle al usuario que genere a mano lo que el firewall puede generar.
 *
 * Solo hay error cuando el usuario pego algo que no es una clave: ahi adivinar
 * seria peor que avisar.
 */
function awg_client_store_from_post($post, $tunnel_name, &$input_errors = array()) {
	// Sin la casilla tildada no hay cliente, y guardar vacio es lo que apaga
	// la exportacion de un peer que antes la tenia.
	if (empty($post['client_enable'])) {
		return array();
	}

	$privatekey = trim((string) ($post['client_privatekey'] ?? ''));

	if ($privatekey === '') {
		$keypair = awg_gen_keypair();
		$privatekey = $keypair['privkey_clamped'];
	} elseif (!awg_is_valid_key($privatekey)) {
		$input_errors[] = gettext('The client private key is not a valid key.');
	}

	$address = trim((string) ($post['client_address'] ?? ''));

	if ($address === '') {
		$address = (string) awg_client_next_address($tunnel_name);
	}

	if ($address === '') {
		$input_errors[] = gettext('There is no free address left on this tunnel for a client.');
	}

	$keepalive = trim((string) ($post['client_keepalive'] ?? ''));

	return array(
		'privatekey'		=> $privatekey,
		'address'		=> $address,
		'dns'			=> trim((string) ($post['client_dns'] ?? '')),
		'mtu'			=> trim((string) ($post['client_mtu'] ?? '')),
		'allowedips'		=> trim((string) ($post['client_allowedips'] ?? '')),
		'endpoint'		=> trim((string) ($post['client_endpoint'] ?? '')),
		'persistentkeepalive'	=> is_numericint($keepalive) ? $keepalive : '');
}

/*
 * Nombre de archivo para el .conf de un cliente.
 *
 * El nombre de la interfaz que arma el cliente sale del nombre del archivo, y
 * tanto awg-quick como el cliente de Windows lo limitan: hasta 15 caracteres de
 * [a-zA-Z0-9_=+.-]. Un nombre con espacios o acentos -- que es lo normal en una
 * descripcion escrita por una persona -- da un archivo que el cliente rechaza
 * al importar.
 */
function awg_client_conf_filename($descr, $fallback = 'awgclient') {
	$name = preg_replace('/[^a-zA-Z0-9_=+.-]/', '-', (string) $descr);
	$name = preg_replace('/-+/', '-', $name);
	$name = trim($name, '-');

	if ($name === '') {
		$name = $fallback;
	}

	return substr($name, 0, 15) . '.conf';
}

?>
