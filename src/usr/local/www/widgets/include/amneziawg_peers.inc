<?php
/*
 * amneziawg_peers.inc
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
 * El widget de peers, que es distinto del de tuneles.
 *
 * El widget que vino con el fork -- amneziawg.widget.php -- lista un renglon
 * por TUNEL, con su puerto y cuantos peers tiene activos. Sirve para mirar el
 * servidor. Este lista un renglon por PEER: quien esta conectado, desde donde y
 * cuando se lo vio por ultima vez, que es lo que uno quiere saber cuando
 * reparte clientes.
 */

$amneziawg_peers_title = gettext('AmneziaWG Peers');
$amneziawg_peers_title_link = '/awg/vpn_awg_peers.php';

function awg_peers_widget_settings_post($post, $user_settings) {
	global $awgg;

	$pconfig = array();

	if (isset($post['widgetkey'])) {
		$widgetkey = $post['widgetkey'];

		$refresh_interval = $post["{$widgetkey}_refresh_interval"];
		$activity_threshold = $post["{$widgetkey}_activity_threshold"];

		$pconfig['refresh_interval'] = $awgg['default_widget_refresh_interval'];
		$pconfig['activity_threshold'] = $awgg['default_widget_activity_threshold'];

		// Mostrar los desconectados es por presencia, como el resto de pfSense
		$pconfig['show_offline'] = isset($post["{$widgetkey}_show_offline"]) ? 'yes' : 'no';

		if (isset($refresh_interval)
		    && is_numericint($refresh_interval) && in_array($refresh_interval, range(0, 10))) {
			$pconfig['refresh_interval'] = $refresh_interval;
		}

		if (isset($activity_threshold)
		    && is_numericint($activity_threshold) && ($activity_threshold >= 0)) {
			$pconfig['activity_threshold'] = $activity_threshold;
		}

		$user_settings['widgets'][$widgetkey] = $pconfig;

		$message = sprintf(gettext('Updated %s peers widget settings via dashboard.'), $awgg['pkg_name']);

		save_widget_settings($_SESSION['Username'], $user_settings['widgets'], $message);
	}
}

function awg_peers_compose_widget_body($widgetkey, $activity_threshold, $show_offline = false) {
	// El mismo truco que el widget nativo para llamar funciones adentro de un
	// heredoc, que ademas deja que los scripts de traduccion vean los gettext()
	$s = fn($x) => $x;

	$colspan = 6;

	if (empty(config_get_path('installedpackages/amneziawg/tunnels/item', []))) {
		return <<<"ROW"
			<tr>
				<td colspan="{$colspan}">{$s(gettext('No AmneziaWG tunnels have been configured.'))}</td>
			</tr>

			ROW;
	}

	$a_devices = awg_get_status();

	if (empty($a_devices)) {
		return <<<"ROW"
			<tr>
				<td colspan="{$colspan}">{$s(gettext('The AmneziaWG service is not running.'))}</td>
			</tr>

			ROW;
	}

	$current_time = (new DateTimeImmutable)->getTimestamp();

	$rows = array();

	foreach ($a_devices as $device_name => $device) {
		foreach ((array) $device['peers'] as $peer) {
			$handshake = is_numericint($peer['latest_handshake']) ? (int) $peer['latest_handshake'] : 0;

			$is_active = (($handshake > 0)
					&& (($activity_threshold == 0)
					    || (($current_time - $handshake) <= $activity_threshold)));

			if (!$is_active && !$show_offline) {
				continue;
			}

			/*
			 * Un peer que esta corriendo pero no esta en la configuracion no
			 * tiene descripcion, asi que se cae a su clave publica recortada.
			 */
			$descr = trim((string) $peer['config']['descr']);

			$name = (strlen($descr) > 0)
					? awg_truncate_pretty($descr, 20)
					: awg_truncate_pretty($peer['public_key'], 12);

			$peer_idx = awg_peer_get_array_idx($peer['public_key'], $device_name);

			$link = ($peer_idx >= 0)
					? '/awg/vpn_awg_peers_edit.php?peer=' . rawurlencode($peer_idx)
					: '/awg/vpn_awg_peers.php';

			$endpoint = (strlen(trim((string) $peer['endpoint'])) > 0)
					? $peer['endpoint']
					: gettext('(none)');

			$last_seen = ($handshake > 0)
					? awg_human_time_diff("@{$handshake}")
					: gettext('never');

			// La clave va en el indice para poder ordenar por handshake sin
			// que dos peers vistos en el mismo segundo se pisen
			$rows[$handshake . '-' . $peer['public_key']] = <<<"ROW"
				<tr>
					<td>
						{$s(awg_handshake_status_icon("@{$handshake}"))}
						<a href="{$s(htmlspecialchars($link))}" title="{$s(htmlspecialchars($descr))}">{$s(htmlspecialchars($name))}</a>
					</td>
					<td>{$s(htmlspecialchars($device_name))}</td>
					<td>{$s(htmlspecialchars($endpoint))}</td>
					<td>{$s(htmlspecialchars($last_seen))}</td>
					<td>{$s(htmlspecialchars(format_bytes($peer['transfer_rx'])))}</td>
					<td>{$s(htmlspecialchars(format_bytes($peer['transfer_tx'])))}</td>
				</tr>

				ROW;
		}
	}

	if (empty($rows)) {
		$empty = $show_offline
				? gettext('No AmneziaWG peers have been configured.')
				: gettext('No peers are connected right now.');

		return <<<"ROW"
			<tr>
				<td colspan="{$colspan}">{$s($empty)}</td>
			</tr>

			ROW;
	}

	// El visto mas recientemente primero
	krsort($rows);

	return implode('', $rows);
}

?>
