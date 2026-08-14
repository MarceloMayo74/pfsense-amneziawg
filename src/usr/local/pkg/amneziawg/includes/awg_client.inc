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
 * Las redes del tunel, como limites de subred, para armar un split tunnel.
 */
function awg_client_tunnel_networks($tunnel_name) {
	$a_ret = array();

	foreach (awg_client_tunnel_address_rows($tunnel_name) as $address) {
		$masked_address = "{$address['address']}/{$address['mask']}";

		if (is_subnet($masked_address)) {
			$a_ret[] = gen_subnet($address['address'], $address['mask']) . "/{$address['mask']}";
		}
	}

	return $a_ret;
}

/*
 * Las redes locales que un cliente itinerante normalmente necesita alcanzar.
 *
 * Se toman las interfaces sin gateway -- con gateway es una WAN -- y, entre
 * esas, las que reparten DHCP, que es la senal mas confiable de "aca vive
 * gente". Si ninguna reparte DHCP se caen todas adentro, porque una lista
 * vacia seria peor que una con alguna de mas.
 */
function awg_client_local_networks() {
	$a_ret = array();
	$dhcp_ifs = array();

	foreach (get_configured_interface_with_descr() as $if => $descr) {
		// En pfSense la mayoria de los toggles son por presencia, no por valor.
		if ((config_get_path("dhcpd/{$if}/enable") !== null)
		    || (config_get_path("dhcpdv6/{$if}/enable") !== null)) {
			$dhcp_ifs[] = $if;
		}
	}

	$fallback = empty($dhcp_ifs);

	foreach (get_configured_interface_with_descr() as $if => $descr) {
		if (!empty(config_get_path("interfaces/{$if}/gateway"))) {
			continue;
		}

		if (!$fallback && !in_array($if, $dhcp_ifs)) {
			continue;
		}

		$address = get_interface_ip($if);
		$bits = get_interface_subnet($if);

		if (is_ipaddrv4($address) && is_numericint($bits) && ($bits > 0) && ($bits < 32)) {
			$a_ret[] = gen_subnet($address, $bits) . "/{$bits}";
		}

		$address6 = get_interface_ipv6($if);
		$bits6 = get_interface_subnetv6($if);

		if (is_ipaddrv6($address6) && is_numericint($bits6) && ($bits6 > 0) && ($bits6 < 128)) {
			$a_ret[] = gen_subnet($address6, $bits6) . "/{$bits6}";
		}
	}

	return array_values(array_unique($a_ret));
}

/*
 * Todo lo que un cliente itinerante suele necesitar: lo que hay detras del
 * firewall mas la red del propio tunel.
 */
function awg_client_tunneled_networks($tunnel_name) {
	return array_values(array_unique(array_merge(
			awg_client_tunnel_networks($tunnel_name),
			awg_client_local_networks())));
}

/*
 * El DNS que le corresponde por defecto a un cliente de este tunel: la
 * direccion de este firewall del lado del tunel.
 */
function awg_client_tunnel_dns($tunnel_name) {
	$rows = awg_client_tunnel_address_rows($tunnel_name);

	foreach ($rows as $address) {
		if (is_ipaddrv4($address['address'])) {
			return $address['address'];
		}
	}

	foreach ($rows as $address) {
		if (is_ipaddrv6($address['address'])) {
			return $address['address'];
		}
	}

	return null;
}

/*
 * El puerto en el que escucha el tunel, que es al que tiene que discar el
 * cliente.
 */
function awg_client_tunnel_port($tunnel_name) {
	global $awgg;

	if ([$tun_idx, $tunnel, $is_new] = awg_tunnel_get_config_by_name($tunnel_name)) {
		if (is_port($tunnel['listenport'])) {
			return $tunnel['listenport'];
		}
	}

	return $awgg['default_port'];
}

/*
 * La MTU del tunel, pero solo cuando vale la pena escribirla en el archivo del
 * cliente.
 *
 * La default se omite a proposito: es la que todo cliente ya asume, asi que
 * ponerla solo agrega una linea que despues hay que mantener. El caso que
 * importa es un tunel al que se le bajo la MTU -- una WAN PPPoE, un enlace con
 * encabezado propio -- y ahi si hay que decirselo al cliente.
 */
function awg_client_tunnel_mtu($tunnel_name) {
	global $awgg;

	if ([$tun_idx, $tunnel, $is_new] = awg_tunnel_get_config_by_name($tunnel_name)) {
		if (is_numericint($tunnel['mtu']) && ($tunnel['mtu'] != $awgg['default_mtu'])) {
			return $tunnel['mtu'];
		}
	}

	return null;
}

/*
 * La clave publica del tunel, que es la del peer desde la mirada del cliente.
 */
function awg_client_tunnel_publickey($tunnel_name) {
	if ([$tun_idx, $tunnel, $is_new] = awg_tunnel_get_config_by_name($tunnel_name)) {
		if (awg_is_valid_key($tunnel['publickey'])) {
			return $tunnel['publickey'];
		}
	}

	return null;
}

/*
 * Los datos de cliente del ultimo peer creado sobre un tunel, o un array vacio.
 *
 * Los peers se agregan al final, asi que el ultimo que tenga datos es el
 * cliente mas reciente que se entrego en ese tunel. Sirve para que el segundo
 * cliente herede lo que se eligio para el primero.
 */
function awg_client_last_peer_store($tunnel_name) {
	$a_ret = array();

	foreach (awg_tunnel_get_peers_config($tunnel_name) as [$peer_idx, $peer, $peer_is_new]) {
		$store = awg_client_store($peer);

		if (!empty($store)) {
			$a_ret = $store;
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

	return awg_client_build_conf(array(
		'descr'			=> $peer['descr'],
		'privatekey'		=> $store['privatekey'],
		'address'		=> $store['address'] ?? '',
		'dns'			=> $store['dns'] ?? '',
		'mtu'			=> $store['mtu'] ?? '',
		'publickey'		=> awg_client_tunnel_publickey($peer['tun']),
		'presharedkey'		=> $peer['presharedkey'] ?? '',
		'allowedips'		=> $store['allowedips'] ?? '',
		'endpoint'		=> awg_client_format_endpoint($store['endpoint'] ?? '',
		                                              $store['port'] ?? ''),
		'persistentkeepalive'	=> $store['persistentkeepalive'] ?? 0,
		'obfuscation'		=> awg_obfuscation_pairs($tunnel)), $error);
}

/*
 * Junta host y puerto en el Endpoint que espera el cliente.
 *
 * Una IPv6 literal va entre corchetes: sin ellos, los dos puntos del puerto no
 * se distinguen de los de la direccion y el cliente rechaza el archivo.
 */
function awg_client_format_endpoint($host, $port) {
	$host = trim((string) $host);
	$port = trim((string) $port);

	if ($host === '') {
		return '';
	}

	if (is_ipaddrv6($host) && (strpos($host, '[') === false)) {
		$host = "[{$host}]";
	}

	return ($port === '') ? $host : "{$host}:{$port}";
}

/*
 * Parsea una lista de direcciones separadas por coma a filas
 * array('address' => ..., 'mask' => ...).
 *
 * Una entrada sin barra se toma como un host suelto: /32 en IPv4 y /128 en
 * IPv6, que es lo que quiere decir alguien que escribe una direccion sola.
 */
function awg_client_parse_addresses($addresses, &$input_errors = array(), $label = 'Address') {
	$a_ret = array();

	foreach (explode(',', (string) $addresses) as $entry) {
		$entry = trim($entry);

		if (strlen($entry) == 0) {
			continue;
		}

		if (strpos($entry, '/') !== false) {
			[$address, $mask] = explode('/', $entry, 2);
		} else {
			$address = $entry;
			$mask = is_ipaddrv6($entry) ? '128' : '32';
		}

		$address = trim($address);
		$mask = trim($mask);

		$masked_address = "{$address}/{$mask}";

		if (!is_subnet($masked_address)) {
			$input_errors[] = sprintf(
				gettext('%1$s %2$s is not a valid IPv4 or IPv6 CIDR address.'),
				$label, $masked_address);

			continue;
		}

		$a_ret[] = array('address' => $address, 'mask' => $mask);
	}

	return $a_ret;
}

/*
 * Convierte las filas de direcciones en los campos repetibles que espera
 * awg_peer_allowedips(), para poder mandarle al guardado nativo un post
 * indistinguible del que arma la pagina a mano.
 */
function awg_client_addresses_to_post($addresses, $descr = null) {
	$a_ret = array();

	foreach ((array) $addresses as $counter => $address) {
		$a_ret["address{$counter}"]		= $address['address'];
		$a_ret["address_subnet{$counter}"]	= $address['mask'];
		$a_ret["address_descr{$counter}"]	= $descr;
	}

	return $a_ret;
}

/*
 * Y la vuelta: las filas a una linea separada por comas.
 */
function awg_client_addresses_to_line($addresses) {
	$a_ret = array();

	foreach ((array) $addresses as $address) {
		$a_ret[] = "{$address['address']}/{$address['mask']}";
	}

	return implode(', ', $a_ret);
}

/*
 * Los valores con los que se abre la pagina para un cliente nuevo de un tunel.
 *
 * Lo que se eligio para el cliente anterior del mismo tunel pesa mas que
 * cualquier default: el segundo cliente casi siempre quiere lo mismo que el
 * primero, y volver a elegir routing, DNS y MTU en cada alta es la clase de
 * trabajo que la pagina existe para evitar.
 */
function awg_client_tunnel_defaults($tunnel_name) {
	global $awgg;

	$store = awg_client_last_peer_store($tunnel_name);

	// Un valor recordado solo cuenta si de verdad tenia algo.
	$remembered = function($key) use ($store) {
		return (isset($store[$key]) && (strlen((string) $store[$key]) > 0))
				? (string) $store[$key]
				: null;
	};

	$networks = awg_client_tunneled_networks($tunnel_name);

	$routing = $remembered('routing') ?? (empty($networks) ? 'full' : 'split');

	switch ($routing) {
		case 'full':
			$client_allowedips = $awgg['default_allowedips'];
			break;

		case 'split':
			$client_allowedips = implode(', ', $networks);
			break;

		default:
			$client_allowedips = $remembered('allowedips') ?? implode(', ', $networks);
			break;
	}

	[$tun_idx, $tunnel, $tun_is_new] = awg_tunnel_get_config_by_name($tunnel_name);

	return array(
		/*
		 * Con todo el trafico por el tunel este firewall tambien deberia
		 * resolver nombres; con split tunnel se respeta el DNS que ya tenga el
		 * cliente salvo que el operador elija uno. Un cliente guardado a
		 * proposito sin DNS se recuerda asi, y por eso se pregunta por el
		 * elemento y no por su largo.
		 */
		'dns'			=> array_key_exists('dns', $store)
						? (string) $store['dns']
						: (($routing == 'full') ? awg_client_tunnel_dns($tunnel_name) : null),
		'mtu'			=> $remembered('mtu') ?? awg_client_tunnel_mtu($tunnel_name),
		'routing'		=> $routing,
		'client_allowedips'	=> $client_allowedips,
		'endpoint'		=> $remembered('endpoint'),
		'port'			=> $remembered('port') ?? awg_client_tunnel_port($tunnel_name),
		'address'		=> awg_client_next_address($tunnel_name),
		'persistentkeepalive'	=> array_key_exists('persistentkeepalive', $store)
						? (string) $store['persistentkeepalive']
						: $awgg['default_keepalive'],
		/*
		 * La ofuscacion no es un campo que el operador cargue: sale del tunel y
		 * viaja aca solo para que la pagina pueda decir de cual la esta
		 * tomando. Que el archivo del cliente la lleve lo resuelve
		 * awg_client_conf_from_peer(), leyendo el tunel en el momento.
		 */
		'obfuscation'		=> $tunnel ? awg_obfuscation_pairs($tunnel) : array());
}

/*
 * Los defaults de TODOS los tuneles, para que el javascript pueda rellenar el
 * formulario al cambiar la seleccion sin volver al servidor.
 */
function awg_client_tunnel_hints() {
	$a_ret = array();

	foreach (awg_get_tun_list() as $tun_name => $tun_descr) {
		$a_ret[$tun_name] = array(
			'port'		=> awg_client_tunnel_port($tun_name),
			'dns'		=> awg_client_tunnel_dns($tun_name),
			'address'	=> awg_client_next_address($tun_name),
			'networks'	=> implode(', ', awg_client_tunneled_networks($tun_name)),
			'defaults'	=> awg_client_tunnel_defaults($tun_name));
	}

	return $a_ret;
}

/*
 * Valida el formulario de peer y lo guarda, cliente incluido.
 *
 * Sale de la pagina y no vive en ella por lo mismo que el resto: para poder
 * leerlo entero de una y para que la pagina se ocupe de dibujar.
 *
 * El orden de adentro es lo unico delicado. Se valida todo primero, se arma un
 * post de peer INDISTINGUIBLE del que manda un formulario nativo, se deja que
 * awg_do_peer_post() valide y guarde -- ese camino ya lo ejercitaron las fases
 * 4 y 5 y no se toca-- y recien despues se escriben los datos del cliente.
 *
 * Dos cosas que parecen errores y son deliberadas, heredadas de wgeasy:
 *
 *   - El endpoint que se guarda en el peer es el de ESTE firewall, el que disca
 *     el cliente. Asi la lista de peers muestra una direccion en vez de
 *     "Dynamic". WireGuard lo reemplaza solo por el origen real del cliente en
 *     cuanto llega su primer handshake valido.
 *   - El keepalive NO se guarda en el peer: es cosa del cliente, que es el que
 *     esta detras del NAT, y va unicamente en su archivo.
 */
function awg_client_do_peer_post($post) {
	global $awgg;

	$input_errors = array();
	$changes = false;
	$tuns_to_sync = array();
	$peer_idx = null;

	$edit_idx = is_numericint($post['index'] ?? null) ? $post['index'] : null;

	$pconfig = array();

	$pconfig['index']		= $edit_idx;
	$pconfig['enabled']		= empty($post['enabled']) ? 'no' : 'yes';
	$pconfig['client_enable']	= empty($post['client_enable']) ? 'no' : 'yes';
	$pconfig['applynow']		= empty($post['applynow']) ? 'no' : 'yes';
	$pconfig['tun']			= trim((string) ($post['tun'] ?? ''));
	$pconfig['descr']		= trim((string) ($post['descr'] ?? ''));
	$pconfig['privatekey']		= trim((string) ($post['privatekey'] ?? ''));
	$pconfig['publickey']		= trim((string) ($post['publickey'] ?? ''));
	$pconfig['presharedkey']	= trim((string) ($post['presharedkey'] ?? ''));
	$pconfig['address']		= trim((string) ($post['address'] ?? ''));
	$pconfig['dns']			= trim((string) ($post['dns'] ?? ''));
	$pconfig['mtu']			= trim((string) ($post['mtu'] ?? ''));
	$pconfig['routing']		= trim((string) ($post['routing'] ?? 'full'));
	$pconfig['client_allowedips']	= trim((string) ($post['client_allowedips'] ?? ''));
	$pconfig['endpoint']		= trim((string) ($post['endpoint'] ?? ''));
	$pconfig['port']		= trim((string) ($post['port'] ?? ''));
	$pconfig['persistentkeepalive']	= trim((string) ($post['persistentkeepalive'] ?? ''));

	$genera = ($pconfig['client_enable'] == 'yes');

	if (!array_key_exists($pconfig['tun'], awg_get_tun_list())) {
		$input_errors[] = gettext('Select a valid AmneziaWG tunnel. The tunnel must exist and have a valid key pair.');
	} elseif ($genera && ($pconfig['tun'] == 'unassigned')) {
		/*
		 * 'unassigned' es una opcion valida para un peer -- queda registrado sin
		 * atar a ningun tunel -- pero no para armarle un archivo al cliente: sin
		 * tunel no hay clave publica que ponerle, ni puerto, ni ofuscacion.
		 */
		$input_errors[] = gettext('A client configuration needs a tunnel. Select one, or uncheck the client option.');
	}

	if (strlen($pconfig['descr']) == 0) {
		$input_errors[] = gettext('A description must be specified.');
	}

	/*
	 * Con generacion, la clave publica se DERIVA de la privada del cliente: si
	 * el operador cargara las dos por separado y no se correspondieran, el
	 * cliente no conectaria y nada lo avisaria. Sin generacion la publica es lo
	 * unico que hay -- el caso del peer site-to-site o del cliente que genero
	 * sus propias claves, que es la practica mas segura -- y se toma tal cual.
	 */
	if ($genera) {
		if (strlen($pconfig['privatekey']) == 0) {
			$input_errors[] = gettext('A client private key must be specified.');
		} elseif (!awg_is_valid_key($pconfig['privatekey'])) {
			$input_errors[] = gettext('The client private key specified is not a valid AmneziaWG private key.');
		} else {
			$keypair = awg_gen_publickey($pconfig['privatekey']);

			$pconfig['privatekey'] = $keypair['privkey_clamped'];
			$pconfig['publickey'] = $keypair['pubkey'];

			if (!awg_is_valid_key($pconfig['publickey'])) {
				$input_errors[] = gettext('Unable to derive a public key from the client private key.');
			}
		}
	} else {
		$pconfig['privatekey'] = '';

		if (strlen($pconfig['publickey']) == 0) {
			$input_errors[] = gettext('A public key must be specified.');
		} elseif (!awg_is_valid_key($pconfig['publickey'])) {
			$input_errors[] = gettext('The public key specified is not a valid AmneziaWG public key.');
		}
	}

	$addresses = awg_client_parse_addresses($pconfig['address'], $input_errors, gettext('Allowed IP'));

	if (empty($addresses)) {
		$input_errors[] = gettext('At least one allowed IP must be specified.');
	}

	/*
	 * awg_validate_peer_post() rechaza claves publicas repetidas pero no
	 * direcciones repetidas, y dos peers con la misma direccion en un tunel
	 * significa que el trafico llega solo al ultimo de la lista.
	 */
	foreach ($addresses as $address) {
		$masked_address = gen_subnet($address['address'], $address['mask']) . "/{$address['mask']}";

		foreach (awg_tunnel_get_peers_config($pconfig['tun']) as [$other_idx, $other, $other_is_new]) {
			// Que un peer conserve su propia direccion al editarlo esta bien.
			if (!is_null($edit_idx) && ($other_idx == $edit_idx)) {
				continue;
			}

			foreach ((array) $other['allowedips']['row'] as $row) {
				if ("{$row['address']}/{$row['mask']}" == $masked_address) {
					$input_errors[] = sprintf(
						gettext('Address %1$s is already assigned to peer "%2$s" on tunnel %3$s.'),
						$masked_address, $other['descr'], $pconfig['tun']);
				}
			}
		}
	}

	if ($genera) {
		$client_allowedips = awg_client_parse_addresses($pconfig['client_allowedips'],
		                                                $input_errors, gettext('Tunneled network'));

		if (empty($client_allowedips)) {
			$input_errors[] = gettext('At least one tunneled network must be specified for the client.');
		}

		if (strlen($pconfig['endpoint']) == 0) {
			$input_errors[] = gettext('An endpoint must be specified.');
		} elseif (!is_hostname($pconfig['endpoint']) && !is_ipaddr($pconfig['endpoint'])) {
			$input_errors[] = sprintf(
				gettext('Endpoint %s must be a valid IPv4 or IPv6 address or hostname.'),
				$pconfig['endpoint']);
		}

		/*
		 * El puerto no es opcional: awg(8) parsea el Endpoint como host:puerto y
		 * un archivo con solo el host se rechaza de plano -- "invalid QR code"
		 * en el telefono--, asi que no se guarda un peer sin el.
		 */
		if (strlen($pconfig['port']) == 0) {
			$input_errors[] = gettext('An endpoint port must be specified. Clients reject an endpoint without one.');
		} elseif (!ctype_digit($pconfig['port']) || !is_port($pconfig['port'])) {
			$input_errors[] = sprintf(gettext('Invalid endpoint port (%s).'), $pconfig['port']);
		}

		foreach (explode(',', $pconfig['dns']) as $dns) {
			$dns = trim($dns);

			if ((strlen($dns) > 0) && !is_ipaddr($dns) && !is_hostname($dns)) {
				$input_errors[] = sprintf(gettext('Invalid DNS entry (%s).'), $dns);
			}
		}

		if (!empty($pconfig['mtu'])) {
			if (!is_numericint($pconfig['mtu'])) {
				$input_errors[] = sprintf(gettext('Invalid MTU (%s).'), $pconfig['mtu']);
			} elseif ((intval($pconfig['mtu']) < 1280) || (intval($pconfig['mtu']) > 9000)) {
				$input_errors[] = sprintf(gettext('MTU must be in range 1280-9000 (%s).'), $pconfig['mtu']);
			}
		}
	}

	if (!empty($pconfig['persistentkeepalive'])) {
		if (!is_numericint($pconfig['persistentkeepalive'])) {
			$input_errors[] = sprintf(gettext('Invalid keep alive interval (%s).'),
			                          $pconfig['persistentkeepalive']);
		} elseif ((intval($pconfig['persistentkeepalive']) < 0)
		          || (intval($pconfig['persistentkeepalive']) > 65535)) {
			$input_errors[] = sprintf(gettext('Keep alive interval must be in range 0-65535 (%s).'),
			                          $pconfig['persistentkeepalive']);
		}
	}

	// Nada de lo que sigue es seguro con el formulario roto.
	if (!empty($input_errors)) {
		return array('input_errors' => $input_errors, 'changes' => $changes,
		             'tuns_to_sync' => $tuns_to_sync, 'pconfig' => $pconfig,
		             'peer_idx' => $peer_idx);
	}

	$peer_post = array(
		'index'			=> $edit_idx,
		'enabled'		=> $pconfig['enabled'],
		'tun'			=> $pconfig['tun'],
		'descr'			=> $pconfig['descr'],
		'endpoint'		=> $genera ? $pconfig['endpoint'] : '',
		'port'			=> $genera ? $pconfig['port'] : '',
		'persistentkeepalive'	=> null,
		'publickey'		=> $pconfig['publickey'],
		'presharedkey'		=> empty($pconfig['presharedkey']) ? null : $pconfig['presharedkey']);

	$peer_post = array_merge($peer_post,
	                         awg_client_addresses_to_post($addresses, $pconfig['descr']));

	$res = awg_do_peer_post($peer_post);

	$input_errors = array_merge($input_errors, (array) $res['input_errors']);

	if (empty($input_errors)) {
		$changes	= $res['changes'];
		$tuns_to_sync	= $res['tuns_to_sync'];
		$peer_idx	= $res['peer_idx'];

		$pconfig['index'] = $peer_idx;

		/*
		 * Todo lo necesario para rearmar el archivo del cliente mas adelante,
		 * para poder editar el peer y volver a exportarlo sin re-clavearlo.
		 * Este es el unico lugar donde se persiste una clave privada de cliente.
		 *
		 * Sin generacion se guarda vacio, que es lo que apaga la exportacion de
		 * un peer que antes la tenia.
		 */
		awg_client_save_store($peer_idx, $genera ? array(
			'privatekey'		=> $pconfig['privatekey'],
			'address'		=> awg_client_addresses_to_line($addresses),
			'dns'			=> $pconfig['dns'],
			'mtu'			=> $pconfig['mtu'],
			'endpoint'		=> $pconfig['endpoint'],
			'port'			=> $pconfig['port'],
			'routing'		=> $pconfig['routing'],
			'allowedips'		=> awg_client_addresses_to_line($client_allowedips),
			'persistentkeepalive'	=> $pconfig['persistentkeepalive']) : array());
	}

	return array('input_errors' => $input_errors, 'changes' => $changes,
	             'tuns_to_sync' => $tuns_to_sync, 'pconfig' => $pconfig,
	             'peer_idx' => $peer_idx);
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
