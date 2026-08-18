<?php
/*
 * awg_import.inc
 *
 * part of pfSense (https://www.pfsense.org)
 * Copyright (c) 2021-2026 Rubicon Communications, LLC (Netgate)
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

/*
 * Leer un .conf ajeno y armar con el un tunel de este firewall.
 *
 * Es el espejo de awg_client.inc: aquel escribe el archivo que se le entrega a
 * un cliente, este lee el que nos entregaron a nosotros. Existe porque hacerlo a
 * mano no es tedioso, es peligroso: los dos extremos tienen que ofuscar
 * IDENTICO, son hasta 25 valores, y un solo digito mal no da ningun error --
 * simplemente el handshake no cierra nunca y nada lo dice.
 *
 * En AmneziaWG, como en WireGuard, no hay servidor ni cliente: el archivo que a
 * uno le pasan describe un extremo --su clave privada, su direccion, su
 * ofuscacion-- y un peer con el que hablar. Eso es exactamente un tunel de este
 * paquete mas uno o mas peers, asi que importar es traducir, no adaptar.
 */

require_once('amneziawg/includes/awg_globals.inc');
require_once('amneziawg/includes/awg_api.inc');

/*
 * Parte un .conf en secciones. Devuelve array('interface' => array(clave =>
 * valor), 'peers' => array(array(clave => valor), ...)) con las claves en
 * minuscula, o false con el motivo en $error.
 *
 * Tolera lo que tolera awg(8): comentarios con #, espacios alrededor del '=',
 * lineas en blanco y nombres de clave en cualquier capitalizacion. No tolera
 * una linea que no sea ni comentario ni seccion ni 'clave = valor': si el
 * archivo esta cortado a la mitad --que es como llega un copiar y pegar
 * apurado-- es mejor decirlo que importar medio tunel.
 */
function awg_import_parse($text, &$error = null) {
	$out = array('interface' => array(), 'peers' => array());

	$section = null;
	$peer = null;

	foreach (preg_split('/\R/', (string) $text) as $number => $raw) {
		$line = trim($raw);

		// Los comentarios de fin de linea no existen en este formato: '#' solo
		// cuenta al principio, porque un valor puede llevar '#' adentro.
		if (($line === '') || ($line[0] === '#') || ($line[0] === ';')) {
			continue;
		}

		if (preg_match('/^\[(\w+)\]$/', $line, $m)) {
			$section = strtolower($m[1]);

			if ($section === 'peer') {
				if (is_array($peer)) {
					$out['peers'][] = $peer;
				}

				$peer = array();
			} else if ($section !== 'interface') {
				$error = sprintf(gettext('Line %1$d: [%2$s] is not a section this understands.'),
						 $number + 1, $m[1]);

				return false;
			}

			continue;
		}

		if (strpos($line, '=') === false) {
			$error = sprintf(gettext('Line %1$d is neither a section nor a "key = value" pair: %2$s'),
					 $number + 1, $line);

			return false;
		}

		if ($section === null) {
			$error = sprintf(gettext('Line %d comes before any [Interface] section.'), $number + 1);

			return false;
		}

		[$key, $value] = explode('=', $line, 2);

		$key = strtolower(trim($key));
		$value = trim($value);

		if ($section === 'peer') {
			$peer[$key] = $value;
		} else {
			$out['interface'][$key] = $value;
		}
	}

	if (is_array($peer)) {
		$out['peers'][] = $peer;
	}

	if (empty($out['interface'])) {
		$error = gettext('The file has no [Interface] section.');

		return false;
	}

	return $out;
}

/*
 * El nivel de AmneziaWG que hace falta para entender un .conf: el mas alto
 * entre los campos que trae.
 *
 * Un archivo de 1.x da 1, uno con S3 da 2, uno con HeaderProtectionKey da 3.
 * Sirve para dos cosas: dejar el selector del tunel donde corresponde, y poder
 * decir "este firewall no llega" antes de importar algo que no va a levantar.
 */
function awg_import_level($interface) {
	global $awgg;

	$level = 1;

	foreach ($awgg['obfuscation_fields'] as $field => $spec) {
		$key = strtolower($spec['key'] ?? $field);

		if ((trim((string) ($interface[$key] ?? '')) !== '') &&
		    ($spec['version'] > $level)) {
			$level = $spec['version'];
		}
	}

	return $level;
}

/*
 * Traduce lo parseado a la forma que guarda este paquete: un tunel y sus peers,
 * listos para config_set_path().
 *
 * Lo que NO se toma del archivo, y por que:
 *
 *   ListenPort  un .conf de cliente casi nunca lo trae, y el que lo trae suele
 *               ser el del OTRO extremo. Se pide uno libre de este firewall.
 *   DNS         es para el resolver del cliente; un tunel de pfSense no tiene
 *               donde ponerlo.
 *   Table, PreUp, PostUp, SaveConfig...  son de wg-quick, que aca no corre.
 *
 * $names_taken permite probar la funcion sin tocar la configuracion.
 */
function awg_import_build($parsed, $descr = '', &$error = null) {
	global $awgg;

	$iface = $parsed['interface'];

	if (trim((string) ($iface['privatekey'] ?? '')) === '') {
		$error = gettext('The [Interface] section has no PrivateKey.');

		return false;
	}

	if (!awg_is_valid_key($iface['privatekey'])) {
		$error = gettext('The PrivateKey in [Interface] is not a valid key.');

		return false;
	}

	if (empty($parsed['peers'])) {
		$error = gettext('The file has no [Peer] section, so there would be nothing to talk to.');

		return false;
	}

	$key = awg_gen_publickey($iface['privatekey']);

	$tunnel = array(
		'name'		=> next_awg_if(),
		'enabled'	=> 'yes',
		'descr'		=> ($descr !== '') ? $descr : gettext('Imported'),
		'listenport'	=> (string) next_awg_port(),
		'privatekey'	=> $key['privkey_clamped'],
		'publickey'	=> $key['pubkey'],
		'mtu'		=> trim((string) ($iface['mtu'] ?? '')) !== ''
					? $iface['mtu']
					: (string) $awgg['default_mtu'],
		'addresses'	=> array('row' => array()));

	foreach (awg_import_split_list($iface['address'] ?? '') as $address) {
		[$addr, $mask] = array_pad(explode('/', $address, 2), 2, '');

		$tunnel['addresses']['row'][] = array(
			'address'	=> $addr,
			'mask'		=> ($mask !== '') ? $mask : (is_ipaddrv6($addr) ? '128' : '32'),
			'descr'		=> '');
	}

	// La ofuscacion, que es el motivo de todo esto
	foreach ($awgg['obfuscation_fields'] as $field => $spec) {
		$tunnel[$field] = (string) ($iface[strtolower($spec['key'] ?? $field)] ?? '');
	}

	/*
	 * El nivel sale del archivo y se topea con lo que este firewall alcanza. Si
	 * el archivo pide mas, se importa igual --los valores quedan guardados-- pero
	 * el tunel va a escribir de menos y no va a levantar contra ese servidor.
	 * Avisar de eso es cosa de la pagina; aca solo se deja el numero correcto.
	 */
	/*
	 * El nivel sale del archivo y se topea con lo que este firewall alcanza,
	 * pero no baja de un escalon que ya no se ofrece.
	 *
	 * Subirlo no cambia lo que se escribe: awg_obfuscation_pairs() saltea los
	 * campos vacios, asi que un archivo sin S3/S4 ni I1-I5 produce el mismo
	 * .conf en 1.x que en 2.0. Lo unico que cambia es que si despues se le
	 * completa un S3 a mano, en 2.0 se escribe y en 1.x se habria ignorado en
	 * silencio.
	 */
	$tunnel['awgversion'] = (string) min(
		max(awg_import_level($iface), awg_lowest_offered_version()),
		awg_version_ceiling());

	$peers = array();

	foreach ($parsed['peers'] as $index => $p) {
		if (trim((string) ($p['publickey'] ?? '')) === '') {
			$error = sprintf(gettext('The [Peer] section number %d has no PublicKey.'), $index + 1);

			return false;
		}

		if (!awg_is_valid_key($p['publickey'])) {
			$error = sprintf(gettext('The PublicKey of [Peer] number %d is not a valid key.'), $index + 1);

			return false;
		}

		[$host, $port] = awg_import_split_endpoint($p['endpoint'] ?? '');

		$peer = array(
			'enabled'		=> 'yes',
			'tun'			=> $tunnel['name'],
			'descr'			=> $tunnel['descr'],
			'publickey'		=> trim($p['publickey']),
			'presharedkey'		=> trim((string) ($p['presharedkey'] ?? '')),
			'endpoint'		=> $host,
			'port'			=> $port,
			'persistentkeepalive'	=> trim((string) ($p['persistentkeepalive'] ?? '')),
			'allowedips'		=> array('row' => array()));

		foreach (awg_import_split_list($p['allowedips'] ?? '') as $allowed) {
			[$addr, $mask] = array_pad(explode('/', $allowed, 2), 2, '');

			$peer['allowedips']['row'][] = array(
				'address'	=> $addr,
				'mask'		=> ($mask !== '') ? $mask : (is_ipaddrv6($addr) ? '128' : '32'),
				'descr'		=> '');
		}

		$peers[] = $peer;
	}

	return array('tunnel' => $tunnel, 'peers' => $peers);
}

/*
 * 'a, b , c' -> array('a', 'b', 'c'). Vacio da una lista vacia y no una con un
 * elemento vacio, que es la trampa clasica de explode().
 */
function awg_import_split_list($value) {
	$out = array();

	foreach (explode(',', (string) $value) as $item) {
		$item = trim($item);

		if ($item !== '') {
			$out[] = $item;
		}
	}

	return $out;
}

/*
 * 'host:puerto' -> array('host', 'puerto').
 *
 * Los dos puntos son ambiguos y hay que resolverlos en orden, porque cada caso
 * mal resuelto falla lejos de aca -- al resolver el endpoint, no al guardar:
 *
 *   [2001:db8::1]:51820   con corchetes, el puerto es lo de despues del ']'
 *   2001:db8::1           IPv6 pelado: TODO es el host, no hay puerto. Partir
 *                         por el ultimo ':' lo dejaria en '2001:db8:' + '1'
 *   vpn.ejemplo.com:51820 un solo ':', el caso comun
 *   vpn.ejemplo.com       sin puerto
 */
function awg_import_split_endpoint($value) {
	$value = trim((string) $value);

	if ($value === '') {
		return array('', '');
	}

	// Con corchetes no hay ambiguedad: el host es lo de adentro
	if ($value[0] === '[') {
		$close = strpos($value, ']');

		if ($close === false) {
			return array($value, '');
		}

		$host = substr($value, 1, $close - 1);
		$rest = substr($value, $close + 1);
		$port = ($rest !== '' && $rest[0] === ':') ? substr($rest, 1) : '';

		return array($host, ctype_digit($port) ? $port : '');
	}

	// Mas de un ':' y sin corchetes solo puede ser una IPv6 sin puerto
	if (substr_count($value, ':') !== 1) {
		return array($value, '');
	}

	[$host, $port] = explode(':', $value, 2);

	return array($host, ctype_digit($port) ? $port : '');
}
