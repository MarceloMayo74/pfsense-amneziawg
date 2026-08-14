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
 * Valida los 16 parametros de ofuscacion.
 *
 * Se valida el rango de todos los campos siempre, incluso los de AWG 2.0
 * contra un backend 1.x. Lo que NO se hace es rechazar un campo 2.0 por correr
 * contra un backend viejo: el valor puede venir de otro firewall o de un
 * backend que se actualice despues, y bloquear el save obligaria a borrarlo
 * para poder tocar cualquier otra cosa del tunel. Que no llegue al .conf ya lo
 * garantiza awg_make_tunnel_conf_file(), que es donde importa.
 */
function awg_validate_obfuscation($pconfig) {
	global $awgg;

	$input_errors = array();

	foreach ($awgg['obfuscation_fields'] as $field => $spec) {
		$value = trim((string) $pconfig[$field]);

		$label = ucfirst($field);

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
