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
