<?php
/*
 * awg_validate.inc
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

// pfSense includes 
require_once('config.inc');
require_once('pfsense-utils.inc');
require_once('util.inc');

// AmneziaWG includes
require_once('amneziawg/includes/awg_api.inc');

function awg_validate_peer_toggle($peer) {
	$input_errors = array();

	// Boilerplate...

	return $input_errors;
}

function awg_validate_peer_delete($peer) {
	$input_errors = array();

	// Boilerplate...

	return $input_errors;
}

function awg_validate_tunnel_delete($tunnel) {
	$input_errors = array();

	// We can't delete assigned tunnels
	if (is_awg_tunnel_assigned($tunnel['name'])) {
		$awg_pfsense_if = awg_get_pfsense_interface_info($tunnel['name']);

		$input_errors[] = "Cannot delete {$tunnel['name']} while it is assigned to {$awg_pfsense_if['descr']} ({$awg_pfsense_if['name']})";
	}

	return $input_errors;
}

function awg_validate_tunnel_toggle($tunnel) {
	$input_errors = array();

	$is_enabled = (isset($tunnel['enabled']) && ($tunnel['enabled']) == 'yes');

	if (!$is_enabled && is_awg_tunnel_assigned($tunnel['name'])) {
		$awg_pfsense_if = awg_get_pfsense_interface_info($tunnel['name']);

		$input_errors[] = "Cannot disable {$tunnel['name']} while it is assigned to {$awg_pfsense_if['descr']} ({$awg_pfsense_if['name']}).";
	}

	// Consumers expect an array
	return $input_errors;
}

/*
 * Validate package settings
 */
function awg_validate_settings_post($pconfig) {
	$input_errors = array();

	$enable = $pconfig['enable'];

	if (awg_is_awg_assigned() && (isset($enable) && (empty($enable) || ($enable == 'off')))) {
		$input_errors[] = gettext('Cannot disable AmneziaWG when one or more tunnels is assigned to a pfSense interface.');
	}
	
	// Check endpoint hostname resolve interval
	$resolve_interval = $pconfig['resolve_interval'];

	if (!empty($resolve_interval) && !is_numericint($resolve_interval)) {
		$input_errors[] = "Invalid endpoint hostname resolve interval ({$resolve_interval}).";
	}

	/*
	 * El intervalo del watchdog termina en el campo de minutos de un crontab,
	 * como '*\/N'. Fuera de 1..60 la entrada no tendria sentido.
	 */
	$watchdog_interval = $pconfig['watchdog_interval'];

	if (!empty($watchdog_interval) &&
	    (!is_numericint($watchdog_interval) ||
	     ($watchdog_interval < 1) || ($watchdog_interval > 60))) {
		$input_errors[] = "Invalid watchdog interval ({$watchdog_interval}). It must be a whole number of minutes between 1 and 60.";
	}

	// Consumers expect an array
	return $input_errors;
}

/*
 * Valildate a tunnel
 * These validation checks should be in the same order as the UI for consistency
 */
function awg_validate_tunnel_post($pconfig, $idx_from_post) {
	$input_errors = array();

	// Check enabled/disabled status
	if (is_awg_tunnel_assigned($pconfig['name']) && (!isset($pconfig['enabled']) || ($pconfig['enabled'] != 'yes'))) {
		$awg_pfsense_if = awg_get_pfsense_interface_info($pconfig['name']);

		$input_errors[] = "Cannot disable {$pconfig['name']} while it is assigned to {$awg_pfsense_if['name']} ({$awg_pfsense_if['name']}).";
	}

	// Check listen port
	$lport = $pconfig['listenport'];

	if (!empty($lport) && (!ctype_digit($lport) || !is_port($lport))) {
		$input_errors[] = "Invalid interface listen port ({$lport}).";
	}

	// Check keys
	if (empty($pconfig['privatekey'])) {
		$input_errors[] = gettext('A private key must be specified.');
	} elseif (!awg_is_valid_key($pconfig['privatekey'])) {
		$input_errors[] = gettext('The private key specified is not a valid AmneziaWG private key.');
	}

	// Assigned tunnels don't need these validation checks
	if (!is_awg_tunnel_assigned($pconfig['name'])) {
		foreach ((array) $pconfig['addresses']['row'] as $address) {
			// Remove any accidental whitespace
			$address['address'] = trim($address['address']);

			$tmp_subnet = "{$address['address']}/{$address['mask']}";

			if (!empty($address['address']) && !is_subnet($tmp_subnet)) {
				$input_errors[] = "Address {$tmp_subnet} is not a valid CIDR address.";
			}

			$conflicts = where_is_ipaddr_configured($address['address'], '', true, true, $address['mask']);

			if (!empty($conflicts)) {
				foreach ($conflicts as $conflict) {
					$ifname = strtoupper($conflict['if']);

					$input_errors[] = "Address {$address['address']} is already configured on this firewall. [ {$ifname} ({$conflict['ip_or_subnet']}) ]";
				}
			}
		}
	}

	// Check the obfuscation parameters
	$input_errors = array_merge($input_errors, awg_validate_obfuscation($pconfig));

	// Consumers expect an array
	return $input_errors;
}

/*
 * Valida los parametros de ofuscacion, sean los 16 de 2.0 o los 25 de 3.1.
 *
 * Se valida el rango de todos los campos siempre, sin importar el nivel elegido
 * ni el del backend. Lo que NO se hace es rechazar un campo por estar arriba de
 * ese nivel: el valor puede venir de otro firewall, o de un backend que se
 * actualice despues, y bloquear el save obligaria a borrarlo para poder tocar
 * cualquier otra cosa del tunel. Que no llegue a ningun .conf ya lo garantiza
 * awg_obfuscation_pairs(), que es donde importa.
 */
/*
 * Valida la plantilla de un I1-I5. Devuelve '' si esta bien, o el motivo.
 *
 * Es una cadena de etiquetas <clave valor> pegadas. Las reglas salen de
 * newObfChain() y de los builders de device/obf.go, no de la documentacion:
 *
 *   <b hex>          hex con o sin 0x, no vacio, de largo par
 *   <t>              sin argumento
 *   <r N> <rc N>     N entero
 *   <rd N> <dz N>
 *   <d> <ds>         sin argumento; no sirven en un I --se generan sin datos de
 *                    origen-- pero el backend los acepta y no somos nosotros
 *                    quienes vamos a rechazar una config que alla funciona
 *
 * En dos cosas somos MAS estrictos que el backend, las dos a proposito:
 *
 *   - N negativo. strconv.Atoi lo acepta y el largo negativo termina en un
 *     slice invalido adentro del proceso go. Que el .conf entre y el daemon se
 *     caiga despues es peor que un error al guardar.
 *   - N gigante. Nada lo limita del otro lado, pero el I es un paquete UDP: un
 *     <r 100000> no se puede mandar. El tope de 1000 es el que publica Amnezia.
 *
 * Y el texto suelto fuera de las etiquetas, que el backend IGNORA en silencio,
 * aca se rechaza: es la unica forma de que lo que se ve en la pantalla sea lo
 * que viaja por el cable.
 */
function awg_validate_junk_payload($value) {
	$known = array('b', 't', 'r', 'rc', 'rd', 'd', 'ds', 'dz');
	$needs_number = array('r', 'rc', 'rd', 'dz');

	$rest = $value;
	$tags = 0;

	while ($rest !== '') {
		$start = strpos($rest, '<');

		if ($start === false) {
			break;
		}

		if (trim(substr($rest, 0, $start)) !== '') {
			return gettext('everything has to be inside <...> tags; the backend silently drops the rest.');
		}

		$end = strpos($rest, '>', $start);

		if ($end === false) {
			return gettext('there is a < without its closing >.');
		}

		$parts = preg_split('/\s+/', trim(substr($rest, $start + 1, $end - $start - 1)), -1, PREG_SPLIT_NO_EMPTY);
		$rest = substr($rest, $end + 1);
		$tags++;

		if (empty($parts)) {
			return gettext('there is an empty <> tag.');
		}

		$tag = $parts[0];
		$arg = $parts[1] ?? '';

		if (!in_array($tag, $known, true)) {
			return sprintf(gettext('<%s> is not a tag the backend knows.'), $tag);
		}

		if ($tag === 'b') {
			$hex = (stripos($arg, '0x') === 0) ? substr($arg, 2) : $arg;

			if ($hex === '') {
				return gettext('<b> needs its bytes, as hex.');
			}

			if ((strlen($hex) % 2) !== 0) {
				return gettext('<b> needs an even number of hex digits: they are bytes.');
			}

			if (!ctype_xdigit($hex)) {
				return gettext('<b> takes hex digits only.');
			}
		} else if (in_array($tag, $needs_number, true)) {
			if (!ctype_digit($arg)) {
				return sprintf(gettext('<%s> needs a length, as a whole number.'), $tag);
			}

			if ((int) $arg > 1000) {
				return sprintf(gettext('<%s> is limited to 1000: the whole thing has to fit in one UDP packet.'), $tag);
			}
		}
	}

	if (trim($rest) !== '') {
		return gettext('everything has to be inside <...> tags; the backend silently drops the rest.');
	}

	if ($tags === 0) {
		return gettext('this has no tags, so nothing would be sent. Leave it empty instead.');
	}

	return '';
}

function awg_validate_obfuscation($pconfig) {
	global $awgg;

	$input_errors = array();

	/*
	 * El nivel del tunel. Se rechaza uno que no existe o que esta por encima del
	 * techo, porque eso solo puede venir de un POST armado a mano o de una
	 * pantalla vieja: el desplegable no ofrece nada mas alto.
	 *
	 * Lo que NO se rechaza es un valor guardado que quedo alto despues de un
	 * downgrade. Eso lo baja awg_tunnel_version() en silencio al escribir, que
	 * es lo unico que hace falta: bloquear el save dejaria un tunel restaurado
	 * de otro firewall imposible de editar hasta adivinar cual es el campo malo.
	 */
	$version = trim((string) ($pconfig['awgversion'] ?? ''));

	if ($version !== '') {
		$ceiling = awg_version_ceiling();

		/*
		 * Se rechaza lo que este paquete no podria haber escrito nunca, y NO lo
		 * que quedo por debajo del piso. Un valor bajo es un escalon que se saco
		 * de la lista despues --1.x-- y awg_tunnel_version() lo sube solo;
		 * rechazarlo dejaria ese tunel imposible de guardar, con un error que
		 * nombra un campo que el operador jamas eligio.
		 */
		if (!ctype_digit($version) || ((int) $version > awg_max_version())) {
			$input_errors[] = gettext('The AmneziaWG compatibility level is not one this package knows.');
		} else if ((int) $version > $ceiling) {
			/*
			 * El techo puede quedar por debajo del piso de la escalera:
			 * awg_backend_version() arranca en 1 y solo sube si sus sondas
			 * contestan, asi que un backend roto o ausente da 1, que ya no es un
			 * escalon de la tabla. Sin este corte el mensaje saldria con el
			 * nombre vacio.
			 */
			$label = $awgg['awg_versions'][$ceiling]['label'] ?? '';

			$input_errors[] = ($label !== '')
				? sprintf(gettext('This firewall cannot go past %s.'), $label)
				: gettext('The AmneziaWG backend on this firewall does not understand any level this package offers. Check that the service is installed and running.');
		}
	}

	foreach ($awgg['obfuscation_fields'] as $field => $spec) {
		$value = trim((string) ($pconfig[$field] ?? ''));

		// El nombre con el que el usuario lo ve, que es el del .conf
		$label = $spec['key'] ?? ucfirst($field);

		// Todos son opcionales
		if ($value === '') {
			continue;
		}

		switch ($spec['type']) {
			case 'int':
				if (!ctype_digit($value) ||
				    ((int) $value < $spec['min']) ||
				    ((int) $value > $spec['max'])) {
					$input_errors[] = sprintf(
						gettext('%1$s must be a whole number between %2$s and %3$s.'),
						$label, $spec['min'], $spec['max']);
				}
				break;

			case 'hdr':
				if (!preg_match($awgg['header_mask'], $value)) {
					$input_errors[] = sprintf(
						gettext('%s must be a number, or a hyphenated range such as 12345-67890.'),
						$label);
					break;
				}

				[$lo, $hi] = awg_header_bounds($value);

				if ($hi < $lo) {
					$input_errors[] = sprintf(
						gettext('%s has its range reversed: the lower bound cannot be greater than the upper bound.'),
						$label);
				}

				/*
				 * El minimo no es cosmetico. Un header que no se setea
				 * conserva el tipo de mensaje estandar de WireGuard, del 1 al
				 * 4, asi que cualquier valor ahi abajo se pisa con el default
				 * de los que quedaron vacios.
				 */
				if (($lo < $spec['min']) || ($hi > $spec['max'])) {
					$input_errors[] = sprintf(
						gettext('%1$s must be between %2$s and %3$s. Values below %2$s are the standard WireGuard message types, which any header left empty still uses.'),
						$label, $spec['min'], $spec['max']);
				}
				break;

			case 'text':
				$why = awg_validate_junk_payload($value);

				if ($why !== '') {
					$input_errors[] = sprintf('%1$s: %2$s', $label, $why);
				}
				break;

			/*
			 * Los rangos de 3.0. Misma forma que H1-H4 pero en 16 bits, y aca
			 * el minimo si es cosmetico: cero significa "no lo toques", que es
			 * justo lo que hace el backend con el campo vacio.
			 */
			case 'range':
				if (!preg_match($awgg['range_mask'], $value)) {
					$input_errors[] = sprintf(
						gettext('%s must be a number, or a hyphenated range such as 12-40.'),
						$label);
					break;
				}

				[$lo, $hi] = awg_header_bounds($value);

				if ($hi < $lo) {
					$input_errors[] = sprintf(
						gettext('%s has its range reversed: the lower bound cannot be greater than the upper bound.'),
						$label);
					break;
				}

				if (($lo < $spec['min']) || ($hi > $spec['max'])) {
					$input_errors[] = sprintf(
						gettext('%1$s must be between %2$s and %3$s.'),
						$label, $spec['min'], $spec['max']);
				}
				break;

			/*
			 * La clave de proteccion de headers: 32 bytes en base64, la misma
			 * forma que una clave de WireGuard. Se valida el largo decodificado
			 * y no la longitud del texto, porque base64 admite escrituras que
			 * miden 44 caracteres y no decodifican a 32 bytes.
			 */
			case 'key':
				$raw = base64_decode($value, true);

				if (($raw === false) || (strlen($raw) !== 32)) {
					$input_errors[] = sprintf(
						gettext('%s must be a 32-byte key in base64, the same shape as a WireGuard key.'),
						$label);
				}
				break;

			/*
			 * Los booleanos de 3.1. Se guardan y se escriben tal cual: awg(8)
			 * los parsea con parse_bool(), que quiere on/off.
			 */
			case 'bool':
				if (!in_array($value, array('on', 'off'), true)) {
					$input_errors[] = sprintf(
						gettext('%s must be either on or off.'),
						$label);
				}
				break;
		}
	}

	// Los paquetes basura no pueden tener un minimo mayor que su maximo
	$jmin = trim((string) $pconfig['jmin']);
	$jmax = trim((string) $pconfig['jmax']);

	if (($jmin !== '') && ($jmax !== '') &&
	    ctype_digit($jmin) && ctype_digit($jmax) &&
	    ((int) $jmin > (int) $jmax)) {
		$input_errors[] = gettext('Jmin cannot be greater than Jmax.');
	}

	/*
	 * La proteccion de headers necesita lugar para su nonce, y ese lugar es el
	 * relleno de cada tipo de paquete. Con HeaderProtectionKey puesta,
	 * mergeWithDevice() exige que S1, S2, S3 y S4 lleguen a
	 * HeaderCipherNonceSize y si no rechaza el setconf entero, con un
	 * 'Invalid argument' que no dice cual falto.
	 *
	 * Es la unica regla del backend que ata dos campos de niveles distintos, y
	 * la que hace que S4 deje de poder ser cero: es el unico relleno que se
	 * paga en cada paquete de datos.
	 *
	 * Se mira el NIVEL y no solo si el campo tiene valor: abajo de 3.0 la clave
	 * no se escribe en ningun .conf, asi que el backend nunca ve la
	 * combinacion. Sin ese detalle, un tunel bajado a 2.0 --con la clave
	 * guardada, que es lo que hace el selector-- no se podia guardar hasta
	 * subirle S4, que ahi no sirve para nada y solo cuesta MTU.
	 */
	$hpk = trim((string) ($pconfig['headerprotectionkey'] ?? ''));

	if (($hpk !== '') && (awg_tunnel_version($pconfig) >= 3)) {
		$min = $awgg['header_protection_min_padding'];

		$faltan = array();

		foreach (array('s1', 's2', 's3', 's4') as $padding) {
			$value = trim((string) ($pconfig[$padding] ?? ''));

			if (($value === '') || !ctype_digit($value) || ((int) $value < $min)) {
				$faltan[] = strtoupper($padding);
			}
		}

		if (!empty($faltan)) {
			$input_errors[] = sprintf(
				gettext('HeaderProtectionKey needs %1$s to be %2$d or more: the nonce that encrypts the header is taken from the first %2$d bytes of each packet\'s padding, so every packet type needs room for it. The backend rejects the whole tunnel otherwise. Either raise them or clear the key.'),
				implode(', ', $faltan), $min);
		}
	}

	/*
	 * amneziawg-go compara los cuatro headers de a pares y rechaza el setconf
	 * ENTERO si dos se pisan, asi que un solapamiento no rompe un campo: deja
	 * el tunel sin levantar.
	 */
	$ranges = array();

	foreach (array('h1', 'h2', 'h3', 'h4') as $header) {
		$value = trim((string) $pconfig[$header]);

		if (($value !== '') && preg_match($awgg['header_mask'], $value)) {
			$ranges[$header] = awg_header_bounds($value);
		}
	}

	foreach ($ranges as $left => $lr) {
		foreach ($ranges as $right => $rr) {
			if ($left >= $right) {
				continue;
			}

			if (($lr[0] <= $rr[1]) && ($rr[0] <= $lr[1])) {
				$input_errors[] = sprintf(
					gettext('%1$s and %2$s overlap. The backend rejects the entire tunnel configuration when any two headers share a value.'),
					ucfirst($left), ucfirst($right));
			}
		}
	}

	$input_errors = array_merge($input_errors, awg_validate_timings($pconfig));

	return $input_errors;
}

/*
 * Las relaciones entre los cinco tiempos de 3.0.
 *
 * Cada uno por separado ya paso por el 'range' de arriba. Lo que se mira aca es
 * como quedan ENTRE ELLOS, que es donde el backend no valida nada y no da error:
 * el setconf entra, el tunel levanta, y despues se comporta mal de una forma que
 * no se parece a un problema de configuracion.
 *
 * Los cinco son locales -- cada punta lee los suyos y no viaja ninguno en el
 * cable -- asi que estas reglas se resuelven de este lado y nada mas.
 *
 * Un campo vacio no se saltea: el backend cae en su constante y la relacion se
 * rompe igual. Por eso entra timing_defaults.
 */
function awg_validate_timings($pconfig) {
	global $awgg;

	$input_errors = array();

	// Abajo de 3.0 no se escribe ninguno, asi que el backend nunca los ve.
	if (awg_tunnel_version($pconfig) < 3) {
		return $input_errors;
	}

	$lo = $hi = array();

	$defaults = (array) ($awgg['timing_defaults'] ?? array());

	/*
	 * Las dos reglas comparan contra el valor al que cae el backend, asi que sin
	 * los defaults no hay nada contra que comparar. Se sale sin decir nada en vez
	 * de tomar el cero que devuelve un indice ausente: ese cero rompe las dos
	 * desigualdades a la vez y produce dos errores inventados sobre campos que el
	 * usuario dejo vacios, que es peor que no validar.
	 */
	foreach (array('rekeyaftertime', 'rekeytimeout', 'rejectaftertime',
		       'keepalivetimeout', 'maxhandshakeattempts') as $field) {
		if (!isset($defaults[$field]) || !is_int($defaults[$field])) {
			return $input_errors;
		}
	}

	foreach ($defaults as $field => $default) {
		$value = trim((string) ($pconfig[$field] ?? ''));

		/*
		 * Vacio, mal formado o "sin definir" es la constante del backend. Lo mal
		 * formado ya lo reporto el bucle de arriba y no hay que decirlo dos
		 * veces; aca solo importa contra que numero va a comparar el backend.
		 */
		if (($value === '') || !preg_match($awgg['range_mask'], $value)) {
			$lo[$field] = $hi[$field] = $default;

			continue;
		}

		[$l, $h] = awg_header_bounds($value);

		if ($h < $l) {
			return $input_errors;
		}

		// El 0 empaquetado es IsZero(), o sea "sin definir"
		if (($l === 0) && ($h === 0)) {
			$l = $h = $default;
		}

		$lo[$field] = $l;
		$hi[$field] = $h;
	}

	/*
	 * keyRefreshTimeoutReceiving() devuelve
	 * max(0, RejectAfterTime.PickOne() - KeepaliveTimeout.Lo() - RekeyTimeout.Lo()).
	 *
	 * Cuando eso da cero, receive.go compara "time.Since(created) > 0" en CADA
	 * paquete recibido y dispara un handshake nuevo por paquete. El tunel pasa
	 * datos igual, asi que no parece roto: se ve como un uso de CPU que no cierra
	 * y un peer que renegocia sin parar.
	 *
	 * Se compara contra el piso de RejectAfterTime porque PickOne() puede sortear
	 * cualquier valor del rango, incluido el mas bajo.
	 */
	$floor = $lo['keepalivetimeout'] + $lo['rekeytimeout'];

	if ($lo['rejectaftertime'] <= $floor) {
		$input_errors[] = sprintf(
			gettext('RejectAfterTime must be greater than KeepaliveTimeout + RekeyTimeout (%1$d + %2$d = %3$d), and its lowest value is %4$d. ' .
				'Below that the backend re-handshakes on every packet it receives, which does not look like a configuration problem from the outside. ' .
				'An empty field counts as the protocol default here, because that is the value the backend falls back to.'),
			$lo['keepalivetimeout'], $lo['rekeytimeout'], $floor, $lo['rejectaftertime']);
	}

	/*
	 * El emisor renueva la clave a los RekeyAfterTime.PickOne() y la deja de
	 * poder usar a los RejectAfterTime.Hi() -- keychainExpireTime(), que usa el
	 * techo y no sortea. Si el rekey puede caer despues del vencimiento, la clave
	 * muere antes de renovarse y el trafico se corta hasta que cierre un
	 * handshake nuevo, una vez por sesion y para siempre.
	 */
	if ($hi['rekeyaftertime'] >= $hi['rejectaftertime']) {
		$input_errors[] = sprintf(
			gettext('RekeyAfterTime must stay below RejectAfterTime: it can reach %1$d while the session expires at %2$d. ' .
				'A key that expires before it is renewed stalls the tunnel once per session, until a fresh handshake completes.'),
			$hi['rekeyaftertime'], $hi['rejectaftertime']);
	}

	return $input_errors;
}

/*
 * Valida el rango y el intervalo de la rotacion de puertos.
 *
 * Lo que se rechaza no es solo lo mal escrito: un rango de un solo puerto pasa
 * cualquier chequeo de sintaxis y no rota nada, y un peer sin endpoint no tiene
 * a donde saltar. Los dos se aceptarian en silencio y no harian nada, que es
 * peor que un error.
 */
function awg_validate_porthop($pconfig) {
	global $awgg;

	$input_errors = array();

	$range = trim($pconfig['porthop'] ?? '');

	$interval = trim($pconfig['porthop_interval'] ?? '');

	if (empty($range)) {
		// Sin rango no hay rotacion, y entonces el intervalo no significa nada
		return $input_errors;
	}

	if (empty(trim($pconfig['endpoint'] ?? ''))) {
		$input_errors[] = gettext('Port hopping needs an endpoint: it rotates the port this firewall dials out to. ' .
					  'A peer that dials in here has its address learned from the handshake instead.');

		return $input_errors;
	}

	if (!preg_match('/^(\d{1,5})\s*-\s*(\d{1,5})$/', $range, $m)) {
		$input_errors[] = "Invalid port hopping range ({$range}). It must be written as low-high, for example 20000-20100.";

		return $input_errors;
	}

	[$lo, $hi] = array((int) $m[1], (int) $m[2]);

	if (!is_port((string) $lo) || !is_port((string) $hi)) {
		$input_errors[] = "Port hopping range ({$range}) is outside 1-65535.";

		return $input_errors;
	}

	if ($lo >= $hi) {
		$input_errors[] = "Port hopping range ({$range}) must go from the lower port to the higher one.";

		return $input_errors;
	}

	if ((($hi - $lo) + 1) < $awgg['porthop_range_min_ports']) {
		$input_errors[] = "Port hopping range ({$range}) must contain at least {$awgg['porthop_range_min_ports']} ports; " .
				  "a single port never changes the flow.";
	}

	if (!empty($interval)) {
		if (!is_numericint($interval)) {
			$input_errors[] = "Invalid port hopping interval ({$interval}).";
		} elseif (((int) $interval < $awgg['porthop_interval_min']) ||
			  ((int) $interval > $awgg['porthop_interval_max'])) {
			$input_errors[] = "Port hopping interval must be between {$awgg['porthop_interval_min']} and " .
					  "{$awgg['porthop_interval_max']} seconds ({$interval}).";
		}
	}

	return $input_errors;
}

/*
 * Valildate a peer
 * These validation checks should be in the same order as the UI for consistency
 */
function awg_validate_peer_post($pconfig, $posted_peer_idx) {
	$input_errors = array();

	// Check remote endpoint
	$ep = trim($pconfig['endpoint']);

	if (!empty($ep) && !is_hostname($ep) && !is_ipaddr($ep)) {
		$input_errors[] = "Endpoint {$ep} must be a valid IPv4 or IPv6 address or hostname.";
	}

	// Check remote port
	$rport = $pconfig['port'];

	if (!empty($rport) && (!ctype_digit($rport) || !is_port($rport))) {
		$input_errors[] = "Invalid peer remote port ({$rport}).";
	}

	// Check the port hopping range and interval
	$input_errors = array_merge($input_errors, awg_validate_porthop($pconfig));

	// Check persistent keep alive
	$keepalive = $pconfig['persistentkeepalive'];

	// If user provided something, validate it. Empty is a valid option.
	if (!empty($keepalive)) {
		// Generic is it a number check
		if (!is_numericint($keepalive)) {
			$input_errors[] = "Invalid keep alive interval ({$keepalive}).";
		// Valid range check on a number for a 16 bit integer
		} else if (intval($keepalive) < 0 || intval($keepalive) > 65535) {
			$input_errors[] = "Keep alive interval must be in range 0-65535 ({$keepalive}).";
		}
	}

	// Check public key
	if (empty($pconfig['publickey'])) {
		$input_errors[] = gettext('A public key must be specified.');
	} elseif (!awg_is_valid_key($pconfig['publickey'])) {
		$input_errors[] = "The public key ({$pconfig['publickey']}) is not a valid AmneziaWG public key.";
	} elseif (!empty($pconfig['tun'])) {
		foreach (awg_tunnel_get_peers_config($pconfig['tun']) as [$peer_idx, $peer, $is_new]) {
			// We don't want duplicate public keys per tunnel, but re-saving is okay...
			if (($peer['publickey'] == $pconfig['publickey']) && ($peer_idx != $posted_peer_idx)) {
				$input_errors[] = "The public key ({$pconfig['publickey']}) is already assigned to a peer on this tunnel ({$pconfig['tun']}).";

				break;
			}
		}
	}

	// Check pre-shared key
	if (!empty($pconfig['presharedkey']) && !awg_is_valid_key($pconfig['presharedkey'])) {
		$input_errors[] = "The pre-shared key ({$pconfig['presharedkey']}) is not a valid AmneziaWG pre-shared key.";
	}

	// We don't want duplicate default routes, but re-saving is okay...
	[$ipv4_default_route_exists, $ipv6_default_route_exists] = awg_tunnel_has_default_route_peer($pconfig['tun'], $posted_peer_idx);

	// Check allowed ips
	if (!empty($pconfig['allowedips']['row']) && is_array($pconfig['allowedips']['row'])) {
		foreach ((array) $pconfig['allowedips']['row'] as $row) {
			$row['address'] = trim($row['address']);

			$tmp_subnet = "{$row['address']}/{$row['mask']}";

			# TODO: This still needs some more thought, disabling this check for now.
			if ($tmp_subnet == '0.0.0.0/0' && $ipv4_default_route_exists && false) {
				$input_errors[] = "Tunnel can only have one default IPv4 route";
			}

			# TODO: Concern over IPv6 compression bypassing, see above.
			if ($tmp_subnet == '::/0' && $ipv6_default_route_exists && false) {
				$input_errors[] = "Tunnel can only have one default IPv6 route";
			}

			if (!empty($row['address']) && !is_subnet($tmp_subnet)) {
				$input_errors[] = "Address {$tmp_subnet} is not a valid IPv4 or IPv6 CIDR subnet address.";
			}
		}
	}

	// Consumers expect an array
	return $input_errors;
}

?>
