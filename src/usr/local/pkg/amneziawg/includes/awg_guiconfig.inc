<?php
/*
 * awg_guiconfig.inc
 *
 * part of pfSense (https://www.pfsense.org)
 * Copyright (c) 2021 R. Christian McDonald (https://github.com/rcmcdonald91)
 * All rights reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the 'License');
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an 'AS IS' BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

// pfSense includes
require_once('util.inc');

// AmneziaWG includes
require_once('amneziawg/includes/awg.inc');
require_once('amneziawg/includes/awg_globals.inc');

function awg_print_service_warning($only_with_tunnels = true) {
	if (!awg_is_service_running() 
	    && (!$only_with_tunnels || count(config_get_path('installedpackages/amneziawg/tunnels/item', [])) > 0)) {
		print_info_box(gettext('The AmneziaWG service is not running.'), 'danger', null);
	}
}

function awg_print_config_apply_box() {
	global $awgg;

	$s = fn($x) => $x;

	if (is_subsystem_dirty($awgg['subsystems']['awg'])) {
		print_apply_box("{$s(gettext('The AmneziaWG configuration has been changed.'))}<br />
					{$s(gettext('The changes must be applied for them to take effect.'))}<br />
					{$s(gettext('Notice: This action may momentarily suspend active AmneziaWG peer connections on any changed tunnels.'))}");
	}
}

// Formats peer endpoint address and port for the UI
function awg_format_endpoint($header = true, $peer = null, $endpoint_key = 'endpoint', $port_key = 'port') {
	$s = fn($x) => $x;

	if ($header) {
		return "{$s(gettext('Endpoint'))} : {$s(gettext('Port'))}";
	}

	if (!is_null($peer)) {
		if (!empty($peer[$endpoint_key])) {
			return "{$peer[$endpoint_key]}:{$peer[$port_key]}";
		} else {
			return gettext('Dynamic');
		}
	}
}

/*
 * Los gateways del sistema, para el desplegable del peer.
 *
 * Se listan los de verdad y no las interfaces: lo que hace falta para armar la
 * ruta es la direccion del gateway, y esa la resuelve gwlb.inc por nombre en el
 * momento de usarla. Guardar el nombre y no la IP es a proposito, porque en una
 * WAN por DHCP o PPPoE la IP cambia sola.
 *
 * Quedan afuera los grupos de gateway: un grupo es una politica de failover que
 * se resuelve por conexion, y una ruta de host apunta a UNA direccion.
 */
function awg_gateway_list() {
	$list = array('' => gettext('Default - let the routing table decide'));

	foreach (return_gateways_array(true) as $name => $gateway) {
		if (empty($gateway['gateway']) || !is_ipaddr($gateway['gateway'])) {
			continue;
		}

		$descr = !empty($gateway['descr']) ? " - {$gateway['descr']}" : '';

		$list[$name] = sprintf('%1$s (%2$s%3$s)%4$s',
			$name,
			$gateway['gateway'],
			$descr,
			isset($gateway['isdefaultgw']) ? gettext(' - default') : '');
	}

	return $list;
}

// Gets a list of available tunnels in a format suitable for input selection boxes
function awg_get_tun_list() {
	// Format tunnel description if one is configured
	$tunDescr = fn($tunnel) => !empty($tunnel['descr']) ? " ({$tunnel['descr']})" : null;

	$a_ret = array();

	// Always include the unassigned option first
	$a_ret['unassigned'] = 'Unassigned';

	foreach (config_get_path('installedpackages/amneziawg/tunnels/item', []) as $tunnel) {
		$a_ret[$tunnel['name']] = "{$tunnel['name']}{$tunDescr($tunnel)}";	
	}

	// Consumers of this function always expect an array type
	return $a_ret;
}

// Truncates a given string if necessary and formats it to make it look good
function awg_truncate_pretty($text, $max_length) {
	$s_truncated = substr($text, 0, $max_length);

	$s_pretty_dots = (strlen($text) > $max_length) ? '...' : null;

	return "{$s_truncated}{$s_pretty_dots}";
}

function awg_interface_status_icon($status = 'up', $enabled_class = "fa-solid fa-arrow-up text-success", $disabled_class = "fa-solid fa-arrow-down text-danger") {
	global $awgg;

	$s = fn($x) => $x;

	$ret_class = ($status === 'up') ? $enabled_class : $disabled_class;

	$ret_html = "<i class=\"{$s(htmlspecialchars($ret_class))}\" style=\"vertical-align: middle;\" title=\"{$s(htmlspecialchars($status))}\"></i>";

	return $ret_html;
}

/* 
 * Returns the appropriate icon for peer handshake status based on latest handshake time
 * 
 * $latest_handshake should be format compatible with DateTime
 */
function awg_handshake_status_icon($latest_handshake = '@0', $tunnel_status = 'up', $fa_icon = "fa-solid fa-handshake") {
	global $awgg;

	$s = fn($x) => $x;

	try {
		$end_time = new DateTimeImmutable;

		$start_time = new DateTime($latest_handshake);
	} catch (Exception $e) {
		return null;
	}

	$diff_time = $end_time->diff($start_time);

	$diff_time_seconds = abs($end_time->getTimestamp() - $start_time->getTimestamp());

	$a_thresholds = $awgg['handshake_thresholds'];

	foreach ($a_thresholds as $threshold => $value) {
		if ($diff_time_seconds >= $threshold) {
			$ret_class = $value['class'];
			$ret_title = $value['title'];
			break;
		}
	}

	$ret_html = "<i class=\"fa-solid {$s(htmlspecialchars($fa_icon))} {$s(htmlspecialchars($ret_class))}\" style=\"vertical-align: middle;\" title=\"{$s(htmlspecialchars($ret_title))}\"></i>";

	return $ret_html;
}

/* 
 * Formats a given time difference in a human-friendly way
 * 
 * $start and $end should be format compatible with DateTime
 */
function awg_human_time_diff($start, $end = null, $num_units = 2, $from_epoch = false) {
	if (is_null($end)) {
		$end = new DateTime('now');
	}

	// If these fail for any reason, just bail out...
	try {
		if (!($start instanceof DateTime)) {
			$start = new DateTime($start);
		}

		if (!($end instanceof DateTime)) {
			$end = new DateTime($end);
		}
	} catch (Exception $e) {
		return gettext('never');
	}

	$interval = $end->diff($start);

	$doPlural = fn($nb, $str) => ($nb <= 1) ? $str[0] : $str[1];

	$doTense = fn($interval) => ($interval->invert) ? gettext('ago') : gettext('from now');

	$a_format = array();

	$a_interval = get_object_vars($interval);

	$tokens	= array(
			'y' => array(gettext('year'), gettext('years')),
			'm' => array(gettext('month'), gettext('months')),
			'd' => array(gettext('day'), gettext('days')),
			'h' => array(gettext('hour'), gettext('hours')),
			'i' => array(gettext('minute'), gettext('minutes')),
			's' => array(gettext('second'), gettext('seconds')));

	foreach ($tokens as $key => $value) {
		if ($a_interval[$key] != 0) {
			$a_format[] = "%{$key} {$doPlural($a_interval[$key], $value)}";
		}
	}

	if ((count($a_format) == 0) || (($start->getTimestamp() == 0) && !$from_epoch)) {
		return gettext('never');
	}

	$s_format = implode(', ', array_slice($a_format, 0, $num_units));

	return "{$interval->format($s_format)} {$doTense($interval)}";
}

// Returns the appropriate text input field type for secrets
function awg_secret_input_type() {
	awg_globals();

	$type = 'text';

	if ((config_get_path('installedpackages/amneziawg/config/0/hide_secrets') !== null)
	    && config_get_path('installedpackages/amneziawg/config/0/hide_secrets') == 'yes') {
		$type = 'password';
	}

	return $type;
}

// Returns bool indicating if peers should be hidden on status page on load
function awg_status_peers_hidden() {
	awg_globals();

	return (config_get_path('installedpackages/amneziawg/config/0/hide_peers') === 'yes');
}

function awg_tunnel_status_class($tunnel = null) {
	global $awgg;

	if (isset($tunnel) && is_array($tunnel)) {
		if (awg_is_service_running() && ($tunnel['enabled'] == 'yes')) {
			return 'enabled';
		}
	}

	return 'disabled';
}

function awg_peer_status_class($peer = null) {
	global $awgg;

	if (isset($peer) && is_array($peer)) {
		$tunnel_state = true;

		// We want to visually disable peers if the tunnel is disabled...
		if ([$tun_idx, $tunnel, $is_new] = awg_tunnel_get_config_by_name($peer['tun'])) {
			$tunnel_state = ($tunnel['enabled'] == 'yes');
		}

		if (awg_is_service_running() && ($peer['enabled'] == 'yes') && $tunnel_state) {
			return 'enabled';
		}
	}

	return 'disabled';
}

// Generates a toggle icon link based on the status of the target
function awg_generate_toggle_icon_link($enabled = true, $type = null, $href = '#', $post = true, $icon_enabled = 'fa-ban', $icon_disabled = 'fa-regular fa-square-check') {
	$s = fn($x) => $x;
	
	$icon = $enabled ? $icon_enabled : $icon_disabled;

	$action = $enabled ? gettext('Disable') : gettext('Enable');

	$title = "{$action} {$type}";

	$usepost = $post ? ' usepost' : null;

	return "<a class=\"fa-solid {$s(htmlspecialchars($icon))}\" title=\"{$s(htmlspecialchars($title))}\" href=\"{$s(htmlspecialchars($href))}\"{$s(htmlspecialchars($usepost))}></a>";
}

function awg_compose_address_popover_link($addresses, $title, $href) {
	global $awgg;

	$s = fn($x) => $x;

	$getDescr = fn($x) => !empty($x) ? htmlspecialchars($x) : "<i>({$s(gettext('none'))})</i>";

	$ret_html = null;

	if (is_array($addresses) && count($addresses) > 0) {
		$extras = count($addresses) - 1;

		if ($extras > 0) {
			$data_html = 	<<<"START"
					<table>
						<thead>
							<th>{$s(gettext('Address'))}</th>
							<th style='padding-left: 10px;'>{$s(gettext('Description'))}</th>
						</thead>
						<tbody>

					START;

			foreach ($addresses as $address) {
				$address['address'] = "{$address['address']}/{$address['mask']}";

				$data_html .= 	<<<"ROW"
								<tr>
									<td>{$s(htmlspecialchars($address['address']))}</td>
									<td style='padding-left: 10px;'>{$getDescr($address['descr'])}</td>
								</tr>

						ROW;
			}

			$data_html .= 	<<<"END"
						</tbody>
					</table>
					END;

			$hint = "{$addresses[0]['address']}/{$addresses[0]['mask']} (+{$extras})";

			$popover_params = " data-toggle=\"popover\" data-trigger=\"hover focus\" data-content=\"{$data_html}\" data-html=\"true\"";
		} else {
			$title = $addresses[0]['descr'];

			$hint = "{$addresses[0]['address']}/{$addresses[0]['mask']}";

			$popover_params = null;
		}

		$ret_html = "<a href=\"{$s(htmlspecialchars($href))}\" title=\"{$s(htmlspecialchars($title))}\"{$popover_params}>{$s(htmlspecialchars($hint))}</a>";
	} else {
		$ret_html = "({$s(gettext('none'))})";
	}

	return $ret_html;
}

function awg_generate_tunnel_address_popover_link($tunnel_name) {
	global $awgg;

	$s = fn($x) => $x;

	if ([$tun_idx, $tunnel, $is_new] = awg_tunnel_get_config_by_name($tunnel_name, false)) {
		if (!is_awg_tunnel_assigned($tunnel['name'])) {
			return awg_compose_address_popover_link($tunnel['addresses']['row'], gettext('Interface Addresses'), "vpn_awg_tunnels_edit.php?tun={$tunnel_name}");
		} else {
			$awg_pfsense_if = awg_get_pfsense_interface_info($tunnel['name']);

			$ret_html = 	<<<"RET"
					<i class="fa-solid fa-sitemap" style="vertical-align: middle;"></i>
					<a style="padding-left: 3px" href="../../interfaces.php?if={$s(htmlspecialchars($awg_pfsense_if['name']))}">{$s(htmlspecialchars($awg_pfsense_if['descr']))} ({$s(htmlspecialchars($awg_pfsense_if['name']))})</a>
					
					RET;

			return $ret_html;
		}
	}

	return null;
}

function awg_generate_peer_allowedips_popup_link($peer_idx) {
	global $awgg;

	$s = fn($x) => $x;

	if ([$peer_idx, $peer, $is_new] = awg_peer_get_config($peer_idx, false)) {
		return awg_compose_address_popover_link($peer['allowedips']['row'], gettext('Allowed IPs'), "vpn_awg_peers_edit.php?peer={$peer_idx}");
	}

	return null;
}

?>
