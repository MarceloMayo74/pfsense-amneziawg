<?php
/*
 * awg_api.inc
 *
 * part of pfSense (https://www.pfsense.org)
 * Copyright (c) 2021-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2021 R. Christian McDonald (https://github.com/rcmcdonald91)
 * Copyright (c) 2021 Vajonam
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
require_once('filter.inc');
require_once('gwlb.inc');
require_once('interfaces.inc');
require_once('util.inc');
require_once('services.inc');
require_once('service-utils.inc');

// AmneziaWG includes
require_once('amneziawg/includes/awg_globals.inc');
require_once('amneziawg/includes/awg_install.inc');

/*
* A wrapper for awg_get_running_config to export everything plus extras for the status page
*/
function awg_get_status($json = false) {
	return awg_get_running_config(true, $json);
}

/*
* Returns a massive associative array of current awg status
* 
* $extras returns additional info not provided by `awg show all dump`
*/
function awg_get_running_config($extras = false, $json = false) {
	global $awgg;

	$tunnel_output_keys = array('private_key', 'public_key', 'listen_port', 'fwmark');

	$peer_output_keys = array('public_key', 'preshared_key', 'endpoint', 'allowed_ips', 'latest_handshake', 'transfer_rx', 'transfer_tx', 'persistent_keepalive');

	$ret_config = $cmd_output_rows = array();

	exec("{$awgg['awg']} show all dump", $cmd_output_rows, $ret_code);

	// Guard check
	if ($ret_code !== 0) {
		return ($json ? json_encode($ret_config) : $ret_config);
	}

	foreach ($cmd_output_rows as $row) {
		$tmp_tunnel = $tmp_peer = array();

		$a_device = explode("\t", $row);

		$current_device = $a_device[0];

		if (strcmp($current_device, $last_device)) {
			foreach ($tunnel_output_keys as $key_index => $key) {
				$tmp_tunnel[$key] = $a_device[$key_index + 1];
			}

			if ($extras) {
				// Gets some extra information about tunnels not returned by `awg show all dump`
				$tunnel_if_stats		= pfSense_get_interface_stats($current_device);

				$tmp_tunnel['status']		= awg_interface_status($current_device) ? 'up' : 'down';
				$tmp_tunnel['transfer_rx']	= $tunnel_if_stats['inbytes'];
				$tmp_tunnel['transfer_tx']	= $tunnel_if_stats['outbytes'];
				$tmp_tunnel['inpkts']		= $tunnel_if_stats['inpkts'];
				$tmp_tunnel['outpkts']		= $tunnel_if_stats['outpkts'];
				$tmp_tunnel['mtu']		= $tunnel_if_stats['mtu'];

				[$tun_idx, $tmp_tunnel['config'], $is_new] = awg_tunnel_get_config_by_name($current_device);
			}

			// Add the tunnel to the array
			$ret_config[$current_device] = $tmp_tunnel;

			// Now provision an empty peer array
			$ret_config[$current_device]['peers'] = array();

			$last_device = $a_device[0];

			continue;
		}

		foreach ($peer_output_keys as $key_index => $key) {
			$tmp_peer[$key] = $a_device[$key_index + 1];
		}

		if ($extras) {
			[$peer_idx, $tmp_peer['config'], $is_new] = awg_peer_get_config_by_name($last_device, $tmp_peer['public_key']);
		}

		// Add the peer to the array
		$ret_config[$last_device]['peers'][$a_device[1]] = $tmp_peer;
	}

	return ($json ? json_encode($ret_config) : $ret_config);
}

/*
 * Returns a peer's config array index by public key and tunnel name
 */
function awg_peer_get_array_idx($public_key, $tunnel_name) {
	global $awgg;

	foreach (config_get_path('installedpackages/amneziawg/peers/item', []) as $peer_idx => $peer){
		if ($public_key == $peer['publickey'] && $tunnel_name == $peer['tun']) {
			return $peer_idx;
		}
	}

	return -1;
}

function awg_get_address_family($address) {
	if (is_v4($address)) {
		return 'inet';
	} elseif (is_v6($address)) {
		return 'inet6';
	}

	return false;
}

function awg_ifconfig_if_address_adddel($if_name, $masked_address, $add = true, &$cmds = null) {
	global $awgg;

	$s = fn($x) => $x;

	$ret_code = 0;

	$action = $add ? 'add' : 'delete';

	$error = $add ? AWG_ERROR_IF_SETADDR : AWG_ERROR_IF_DELADDR;

	if (awg_is_valid_tunnel($if_name, true)
	    && ($family = awg_get_address_family($masked_address))) {
		$cmd = "{$awgg['ifconfig']} {$s(escapeshellarg($if_name))} {$s(escapeshellarg($family))} {$s(escapeshellarg($masked_address))} {$s(escapeshellarg($action))} 2>&1";
		$cmds[] = $res = awg_exec($cmd, 'interface', $error);
		$ret_code = $res['ret_code'];
	}

	return ($ret_code == 0);
}

function awg_interface_in_group($if_name, $group = null) {
	global $awgg;

	$s = fn($x) => $x;

	$ifs = array();

	// Assume this will fail...
	$res = false;

	// Default to the package-installed interface group...
	$group = (is_null($group)) ? $awgg['ifgroupentry']['ifname'] : $group;

	if (awg_is_valid_tunnel($if_name, true)) {
		$cmd = "{$awgg['ifconfig']} -g {$s(escapeshellarg($group))} 2>&1";
		exec($cmd, $ifs, $ret_code);
		$res = ($ret_code <> 0) ? $res : in_array($if_name, $ifs);
	}

	return $res;
}

/*
 * El data plane es userspace: cada tunel es un proceso amneziawg-go. Lo que
 * en el paquete de WireGuard era cargar y descargar if_wg, aca es levantar y
 * bajar procesos, uno por tunel.
 */

/*
 * Ruta del socket de control (UAPI) que publica amneziawg-go para un tunel.
 * awg(8) habla con el proceso por ahi en lugar de con el kernel.
 */
function awg_uapi_sock($if_name) {
	global $awgg;

	return "{$awgg['sock_path']}/{$if_name}.sock";
}

function awg_proc_pidfile($if_name) {
	global $awgg;

	return "{$awgg['run_path']}/{$if_name}.pid";
}

function awg_proc_logfile($if_name) {
	global $awgg;

	return "{$awgg['run_path']}/{$if_name}.log";
}

/*
 * Un tunel esta corriendo si su socket de control existe. Es mejor senal que
 * mirar la tabla de procesos: el socket es justo lo que awg(8) necesita, asi
 * que si esta, el tunel es utilizable.
 */
function awg_proc_is_running($if_name) {
	return file_exists(awg_uapi_sock($if_name));
}

/*
 * Espera a que aparezca el socket de control. amneziawg-go tarda un momento
 * en publicarlo despues de arrancar, y sin esta espera el setconf que viene
 * despues falla. Devuelve los microsegundos esperados, o false si no aparecio.
 */
function awg_uapi_wait($if_name, $timeout_us = 5000000) {
	$interval = 100000;
	$waited = 0;

	while ($waited < $timeout_us) {
		if (awg_proc_is_running($if_name)) {
			return $waited;
		}

		usleep($interval);
		$waited += $interval;
	}

	return false;
}

/*
 * Levanta el proceso de un tunel.
 *
 * daemon(8) va SIN -f a proposito: ese flag manda stdio del hijo a /dev/null,
 * no significa "forkear" (daemon se separa de la terminal igual, sin flags).
 * Con -f se pierden todos los logs y crashes de amneziawg-go, que son lo unico
 * que hay para diagnosticar cuando un tunel no levanta.
 */
function awg_proc_start($if_name, &$cmds = null) {
	global $awgg;

	$s = fn($x) => $x;

	if (awg_proc_is_running($if_name)) {
		return true;
	}

	safe_mkdir($awgg['run_path'], 0700);

	$cmd = "{$awgg['daemon']} -p {$s(escapeshellarg(awg_proc_pidfile($if_name)))} " .
	       "{$s(escapeshellarg($awgg['awg_go']))} {$s(escapeshellarg($if_name))} " .
	       ">> {$s(escapeshellarg(awg_proc_logfile($if_name)))} 2>&1";

	$cmds[] = $res = awg_exec($cmd, 'process', AWG_ERROR_PROC_START);

	if ($res['ret_code'] <> 0) {
		return false;
	}

	if (awg_uapi_wait($if_name) === false) {
		$cmds[] = array(
			'cmd'		=> $cmd,
			'output'	=> array(sprintf(gettext('See %s'), awg_proc_logfile($if_name))),
			'ret_code'	=> AWG_ERROR_PROC_UAPI,
			'errors'	=> awg_get_errors('process', AWG_ERROR_PROC_UAPI));

		return false;
	}

	return true;
}

/*
 * Baja el proceso de un tunel.
 *
 * No hace falta destruir la interfaz aparte: cuando muere amneziawg-go, el tun
 * se va con el. Se intenta primero por pidfile y se cae a buscar por linea de
 * comandos, que lleva el nombre de la interfaz y por eso no puede matar otro
 * tunel por accidente.
 */
function awg_proc_stop($if_name, &$cmds = null) {
	global $awgg;

	$s = fn($x) => $x;

	if (!awg_proc_is_running($if_name)) {
		return true;
	}

	$pidfile = awg_proc_pidfile($if_name);

	if (file_exists($pidfile)) {
		killbypid($pidfile);
	} else {
		$cmd = "/bin/pkill -f {$s(escapeshellarg("{$awgg['awg_go']} {$if_name}"))} 2>&1";
		$cmds[] = awg_exec($cmd, 'process', AWG_ERROR_PROC_STOP);
	}

	// Darle un momento a que se retire y limpie su socket.
	for ($i = 0; $i < 20 && awg_proc_is_running($if_name); $i++) {
		usleep(100000);
	}

	// Si quedo el socket colgado, el proceso ya no esta para borrarlo.
	if (!awg_proc_is_running($if_name)) {
		unlink_if_exists($pidfile);

		return true;
	}

	unlink_if_exists(awg_uapi_sock($if_name));
	unlink_if_exists($pidfile);

	return false;
}

function awg_ifconfig_if_updown($if_name, $up = true, &$cmds = null) {
	global $awgg;

	$s = fn($x) => $x;
	
	$state = fn($up) => $up ? 'up' : 'down';

	$ret_code = 0;

	// Is this even a real interface?
	if (awg_is_valid_tunnel($if_name, true)) { 
		// Are we trying to down-an-up or up-a-down? Anything else is wrong in this context...
		if ((!awg_interface_status($if_name) && $up) || (awg_interface_status($if_name) && !$up)) {
			$cmd = "{$awgg['ifconfig']} {$s(escapeshellarg($if_name))} {$s(escapeshellarg($state($up)))} 2>&1";
			$error = $up ? AWG_ERROR_IF_UP : AWG_ERROR_IF_DOWN;
			$cmds[] = $res = awg_exec($cmd, 'interface', $error);
			$ret_code = $res['ret_code'];
		}
	}

	return ($ret_code == 0);
}

/*
 * This updates the addresses of the specified interface without tearing it down
 */
function awg_interface_update_addresses($if_name, &$cmds = null) {
	global $awgg;

	// Assume this will be successful...
	$res = true;

	if (awg_is_valid_tunnel($if_name, true)
	    && ([$tun_idx, $tunnel, $is_new] = awg_tunnel_get_config_by_name($if_name))) {
		// Assigned tunnel interfaces are handled by pfSense and should be ignored here
		if (!is_awg_tunnel_assigned($tunnel['name'])) {
			// Get an array of the current addresses assigned to the tunnel interface
			$current = [];
			$interface_addresses = pfSense_get_ifaddrs($tunnel['name']);
			foreach (array_get_path($interface_addresses, 'addrs', []) as $addrs) {
				$current[] = "{$addrs['addr']}/{$addrs['subnetbits']}";
			}
			foreach (array_get_path($interface_addresses, 'addrs6', []) as $addrs) {
				$current[] = Net_IPv6::uncompress($addrs['addr']) . "/{$addrs['subnetbits']}";
			}

			// Get an array of the addresses to be assigned to the interface
			$desired = array_map(function($x) {
				if (is_ipaddrv6($x['address'])) {
					$x['address'] = Net_IPv6::uncompress($x['address']);
				}
				return "{$x['address']}/{$x['mask']}"; 
			}, $tunnel['addresses']['row']);

			// Determine the addresses to remove
			$addresses_to_remove = array_diff($current, array_intersect($current, $desired));

			// Now remove them
			foreach ($addresses_to_remove as $address) {
				$res &= awg_ifconfig_if_address_adddel($tunnel['name'], $address, false, $cmds);
			}

			// Determine the addresses to add
			$addresses_to_add = array_diff($desired, array_intersect($current, $desired));

			// Now add them
			foreach ($addresses_to_add as $address) {
				$res &= awg_ifconfig_if_address_adddel($tunnel['name'], $address, true, $cmds);
			}

		// Need to let pfSense handle the assigned interfaces
		} elseif (is_awg_tunnel_assigned($tunnel['name'])) {
			if ($pfsense_if_name = awg_get_pfsense_interface_info($tunnel['name'])) {
				// This doesn't return anything useful...
				interface_reconfigure($pfsense_if_name['name']);
			}
		}
	}

	// This will return false if anything went wrong...
	return $res;
}

/* 
 * Translates AmneziaWG interface names to pfSense interface names and descriptions
 */
function awg_get_pfsense_interface_info($tunnel_name) {
	$ret_array = array();

	$iflist = get_configured_interface_list_by_realif(true);

	$ifdescr = get_configured_interface_with_descr(true);

	if (isset($iflist[$tunnel_name])) {
		$tmp_name = $iflist[$tunnel_name];

		$ret_array['name'] 	= $tmp_name;
		$ret_array['descr']	= $ifdescr[$tmp_name];

		return $ret_array;
	}

	// Consumers of this function always expect an array type
	return $ret_array;
}

/*
* A wrapper for just setting peer endpoints
*/
function awg_peer_set_endpoint($tunnel_name, $public_key, $endpoint, &$cmds = null) {
	// We only want to run this in running peers...
	if (in_array($public_key, awg_tunnel_get_peers_running_keys($tunnel_name))) {
		return awg_peer_set_config($tunnel_name, $public_key, 'endpoint', $endpoint, AWG_ERROR_PEER_ENDPOINT, $cmds);
	}
}

/*
* A wrapper for just removing peers
*/
function awg_peer_set_remove($tunnel_name, $public_key, &$cmds = null) {
	// We only want to run this in running peers...
	if (in_array($public_key, awg_tunnel_get_peers_running_keys($tunnel_name))) {
		return awg_peer_set_config($tunnel_name, $public_key, 'remove', null, AWG_ERROR_PEER_REMOVE, $cmds);
	}
}

/*
* A wrapper for `awg set <awg_ifname> peer <public_key> <key> <value>`
* 
* If $key and $value are null, this will just create a peer entry
*/
function awg_peer_set_config($tunnel_name, $public_key, $key = null, $value = null, $error_flag = 0, &$cmds = null) {
	global $awgg;

	$s = fn($x) => $x;

	$key = (!is_null($key)) ? " {$s(escapeshellarg($key))}" : null;

	$value = (!is_null($value)) ? " {$s(escapeshellarg($value))}" : null;

	if (awg_is_valid_tunnel($tunnel_name, true)) {
		$cmd = "{$awgg['awg']} set {$s(escapeshellarg($tunnel_name))} peer {$s(escapeshellarg($public_key))}{$key}{$value} 2>&1";
		$cmds[] = $res = awg_exec($cmd, 'peer', $error_flag);
		$ret_code = $res['ret_code'];
	}

	return ($ret_code == 0);
}

function awg_awg_if_sync($if_name, &$cmds = null) {
	global $awgg;

	$s = fn($x) => $x;

	$ret_code = 0;

	// We need to make sure latest conf files are on disk
	awg_resync();

	$conf_path = "{$awgg['conf_path']}/{$if_name}.conf";

	if (file_exists($conf_path)
	    && awg_is_valid_tunnel($if_name, true)) {
		$cmd = "{$awgg['awg']} syncconf {$s(escapeshellarg($if_name))} {$s(escapeshellarg($conf_path))}";
		$cmds[] = $res = awg_exec($cmd, 'interface', AWG_ERROR_IF_SYNC);
		$ret_code = $res['ret_code'];
	}

	return ($ret_code == 0);
}

/*
 * Crea la interfaz de un tunel.
 *
 * En el paquete de WireGuard esto era 'ifconfig wg create', porque el cloner
 * lo aportaba el modulo de kernel. Aca no hay modulo: la interfaz la crea el
 * propio amneziawg-go al arrancar, asi que crear el tunel es levantar su
 * proceso. Ver docs/arquitectura.md, seccion 2.
 */
function awg_if_create($if_name, &$cmds = null) {
	if (awg_is_valid_tunnel($if_name, true)) {
		return true;
	}

	return awg_proc_start($if_name, $cmds);
}

function awg_exec($cmd, $error_type = null, $error_flag = 1) {
	$ret_code = 0;

	$output = $errors = array();
	
	exec($cmd, $output, $ret_code);

	$ret_code = ($ret_code <> 0) ? $error_flag : $ret_code;

	$errors = awg_get_errors($error_type, $ret_code);
	
	return array('cmd' => $cmd, 'output' => $output, 'ret_code' => $ret_code, 'errors' => $errors);
}

function awg_get_errors($type, $ret_code) {
	global $awgg;

	$errors = array();

	foreach ((array) $awgg['error_flags'][$type] as $error_mask => $error_text) {
		if (($ret_code & $error_mask) > 0) {
			$errors[$error_mask] = $error_text;
		}
	}

	return $errors;
}

/*
 * Destruye la interfaz de un tunel.
 *
 * Basta con bajar el proceso: al morir amneziawg-go el tun desaparece solo,
 * sin necesidad de 'ifconfig destroy'.
 */
function awg_if_destroy($if_name, &$cmds = null) {
	global $awgg;

	$s = fn($x) => $x;

	if (!awg_proc_stop($if_name, $cmds)) {
		return false;
	}

	// Red de seguridad: si por lo que sea la interfaz sobrevivio al proceso,
	// se la saca a mano para no dejar una huerfana ocupando el nombre.
	if (does_interface_exist($if_name)) {
		$cmd = "{$awgg['ifconfig']} {$s(escapeshellarg($if_name))} destroy 2>&1";
		$cmds[] = $res = awg_exec($cmd, 'interface', AWG_ERROR_IF_DESTROY);

		return ($res['ret_code'] == 0);
	}

	return true;
}

function awg_gateways_set_enable($enable = true, $filter_configure = true) {
    $gws = return_gateways_array(true);

    // Assume nothing will be done
    $changes = false;

    foreach ($gws as $gwname => $gw) {
        if (in_array($gw['interface'], awg_get_configured_ifs())) {
            gateway_set_enabled($gwname, $enable);
            $changes = true;
        }
    }

    // Guard check
    if (!$changes) {
        return;
    }

    $action = $enable ? 'Enabled' : 'Disabled';

    awg_write_config("{$action} all AmneziaWG gateways.", false);

    if ($filter_configure) {
        filter_configure();
    }
}

/*
 * Returns an array of running AmneziaWG tunnel interfaces per awg(8)
 */
function awg_get_running_ifs() {
	return array_keys(awg_get_running_config());
}

/*
 * Returns an array of configured AmneziaWG tunnel interfaces
 */
function awg_get_configured_ifs() {
	global $awgg;

	return array_map(fn($x) => $x['name'], config_get_path('installedpackages/amneziawg/tunnels/item', []));
}

function awg_ifconfig_if_group_addremove($if_name, $group, $remove = false, &$cmds = null) {
	global $awgg;

	$s = fn($x) => $x;

	$ret_code = 0;

	$action = $remove ? '-group' : 'group';

	// Is this a real interface and not already in the group?
	if (in_array($if_name, pfSense_interface_listget())
	    && ((!awg_interface_in_group($if_name, $group) && !$remove) || (awg_interface_in_group($if_name, $group) && $remove))) {
		$cmd = "{$awgg['ifconfig']} {$s(escapeshellarg($if_name))} {$s(escapeshellarg($action))} {$s(escapeshellarg($group))} 2>&1";
		$cmds[] = $res = awg_exec($cmd, 'interface', AWG_ERROR_IF_GROUP);
		$ret_code = ($res['ret_code']);	
	}

	return ($ret_code == 0);
}

/*
 * This adds a AmneziaWG interface to the AmneziaWG interface groups
 */
function awg_interface_update_groups($if_name, &$cmds = null) {
	global $awgg;

	// Assume this will be successful...
	$res = true;

	// Is this a real interface?
	if (in_array($if_name, pfSense_interface_listget())) {
		$res &= awg_ifconfig_if_group_addremove($if_name, $awgg['ifgroupentry']['ifname'], true, $cmds);

		$res &= awg_ifconfig_if_group_addremove($if_name, 'awg', false, $cmds);

		if ((config_get_path('installedpackages/amneziawg/config/0/interface_group') == 'none') ||
		    ((config_get_path('installedpackages/amneziawg/config/0/interface_group') == 'unassigned') &&
		    is_awg_tunnel_assigned($if_name))) {
			// We don't want to add the tunnel to the AmneziaWG group...
			return $res;
		}

		// Default behavior is to just add everything...
		$res &= awg_ifconfig_if_group_addremove($if_name, $awgg['ifgroupentry']['ifname'], false, $cmds);
	}

	return $res;
}

function awg_interface_status($if_name) {
	$if_flags = awg_ifconfig_get_flags($if_name);

	return in_array('UP', $if_flags);
}

function awg_ifconfig_get_flags($if_name) {
	global $awgg;

	$s = fn($x) => $x;

	$flags = $output = array();

	if (awg_is_valid_tunnel($if_name, true)) {
		exec("{$awgg['ifconfig']} {$s(escapeshellarg($if_name))}", $output, $ret_code);

		if (($ret_code == 0) && preg_match("/flags=.*<(?P<flags>.*)>/", $output[0], $matches)) {
			$flags = explode(',', $matches['flags']);
		}
	}

	// Consumers of this function always expect an array type
	return $flags;
}

// Get various package infos and return an associative array
function awg_pkg_info() {
	global $awgg;

	$s = fn($x) => $x;

	$ret_array = array();

	$fields = array('name'=> '%n', 'version' => '%v', 'comment' => '%c');

	$return_keys = array_values(array_flip($fields));

	$field_string = implode("\t", $fields);

	// Each package needs to be escaped individually before imploding
	$packages = array_map(fn($x) => escapeshellarg($x), $awgg['depends_names']);

	$packages_string = implode(' ', $packages);

	exec("{$awgg['pkg']} query {$s(escapeshellarg($field_string))} {$packages_string}", $output, $ret_code);

	if ($ret_code == 0) {
		foreach ($output as $pkg_index => $package) {
			$fields = explode("\t", $package);

			foreach ($fields as $field_index => $field) {
				$ret_array[$pkg_index][$return_keys[$field_index]] = $field;
			}
		}
	}

	// Consumers of this function always expect an array type
	return $ret_array;
}

// Generate private key
function awg_gen_keypair($json = false) {
	global $awgg;

	$privkey = exec("{$awgg['awg']} genkey");

	return awg_gen_publickey($privkey, $json);
}

// Compose the public key from a provided private key
function awg_gen_publickey($privkey, $json = false) {
	global $awgg;

	$s = fn($x) => $x;

	$was_clamped = awg_is_key_clamped($privkey);

	$privkey_clamped = awg_clamp_key($privkey);

	$pubkey = exec("echo {$s(escapeshellarg($privkey))} | {$awgg['awg']} pubkey");

	$res = array('privkey' => $privkey, 'privkey_clamped' => $privkey_clamped, 'pubkey' => $pubkey, 'was_clamped' => $was_clamped);

	return $json ? json_encode($res) : $res;
}

/*
 * Reference0: https://lists.zx2c4.com/pipermail/amneziawg/2021-June/006787.html
 * Reference1: https://git.zx2c4.com/amneziawg-freebsd/tree/src/crypto.h#n100
 * 
 * Even though any 256-bit bitstring generated from a csprng is a fine private key,
 * the kernel clamps private keys according to the transformation implemented here.
 * Some AmneziaWG key generators aren't properly pre-clamping generated keys and this
 * can cause confusion for some users who might stumble across local .conf files 
 * and awg(8) output and see 'different' private keys, even though these keys result in
 * the same public key.
 * 
 * The way AmneziaWG is implemented, you can technically have two valid private keys,
 * one that is pre-clamped and one that is not, that can both result in the same public key.
 * 
 * These routines detect if the key is clamped or not, so we can at least include a note
 * in the .conf file so users who stumble onto them during troubleshooting won't freak out.
 * 
 * These routines might also become useful in the future for logging facilities.
 * 
 * Private keys and pre-shared Keys must undergo this transformaton.
 * 
 */
function awg_clamp_key($key) {
        if (!awg_is_valid_key($key)) {
                return null;
        }

        $decoded_key = base64_decode($key);
        $key_bytes = array_slice(unpack("C*", "\0{$decoded_key}"), 1);
        $key_bytes[0] &= 248;
        $key_bytes[31] = ($key_bytes[31] & 127) | 64;
        $decoded_clamped_key = pack("C*", ...$key_bytes);

        return base64_encode($decoded_clamped_key);
}

/*
 * Checks if a given $key is clamped
 */
function awg_is_key_clamped($key) {
	if (awg_is_valid_key($key)) {
		return ($key == awg_clamp_key($key));
	}

	return true;
}

/*
 * Reference0: https://lists.zx2c4.com/pipermail/amneziawg/2020-December/006222.html
 * 
 * Checks if a given private, public, or pre-shared key is valid
 */
function awg_is_valid_key($key) {
	return preg_match('/^[A-Za-z0-9+\/]{42}[A|E|I|M|Q|U|Y|c|g|k|o|s|w|4|8|0]=$/', $key);
}

/*
 * Generate a pre-shared key
 */
function awg_gen_psk() {
	global $awgg;

	$psk = exec("{$awgg['awg']} genpsk");

	return $psk;
}

/*
 * Return the next available AmneziaWG port
 */
function next_awg_port() {
	global $awgg;

	awg_globals();

	for ($idx = $awgg['default_port']; $idx < $awgg['max_port']; $idx++) {
		// Check to see if the port is already in use
		$found = false;

		foreach (config_get_path('installedpackages/amneziawg/tunnels/item', []) as $tunnel) {
			if ($tunnel['listenport'] == $idx) {
				$found = true;
				break;
			}
		}

		// If not, it can be used
		if (!$found) {
			return $idx;
		}
	}

	// We've run out of ports
	return false;
}

// Wrapper to return just the xml array index
function awg_tunnel_get_array_idx($tunnel_name) {
	global $awgg;

	foreach (config_get_path('installedpackages/amneziawg/tunnels/item', []) as $tun_idx => $tunnel) {
		if ($tunnel['name'] == $tunnel_name) {
			return $tun_idx;
		}
	}

	return -1;
}

/*
 * Devuelve el proximo nombre de interfaz libre, dentro de tun9000..tun9999.
 *
 * Tiene que ser un nombre tunN de los que pfSense ya reconoce: con un nombre
 * propio (tun_awg0) la interfaz no aparece en Interfaces > Assignments y no se
 * puede asignar. El rango alto deja lugar a OpenVPN, que arranca desde abajo,
 * pero igual se verifica contra el sistema y no solo contra la configuracion
 * propia, por si algo mas ya tomo el nombre.
 */
function next_awg_if() {
	global $awgg;

	awg_globals();

	$configured = awg_get_configured_ifs();

	for ($ifnum = $awgg['if_range_min']; $ifnum <= $awgg['if_range_max']; $ifnum++) {
		$want_if = "{$awgg['if_prefix']}{$ifnum}";

		if (!in_array($want_if, $configured)
		    && !does_interface_exist($want_if)) {
			return $want_if;
		}
	}

	// We've run out of tuns
	return false;
}

// Check if awg tunnel is assigned to an interface
function is_awg_tunnel_assigned($tunnel_name, $disabled = true) {
	$if_list = get_configured_interface_list_by_realif($disabled);

	return array_key_exists($tunnel_name, $if_list);
}

/*
 * Checks if a peer is enabled
 */
function awg_peer_is_enabled($tunnel, $public_key) {
	if ([$peer_idx, $peer, $is_new] = awg_peer_get_config_by_name($tunnel, $public_key)) {
		return (isset($peer['enabled']) && ($peer['enabled'] == 'yes'));
	}

	return false;
}

/*
 * Check if the given peer has any allowed ips indicating it's the default route it's tunnel
 */
function awg_peer_is_default_route($peer) {
	$default_ipv4 = false;
	$default_ipv6 = false;

	// TODO: Unassigned tunnel check?

	if (!empty($peer['allowedips']['row']) && is_array($peer['allowedips']['row'])) {
		foreach ((array) $peer['allowedips']['row'] as $row) {
			# Only interested in default routes using masks of /0
			if ($row['mask'] != '0') {
				continue;
			}

			if (is_ipaddrv4($row['address']) && $row['address'] == '0.0.0.0') {
				$default_ipv4 = true;
			# TODO: Check for compressed IPv6 addresses? Could it be bypassed with 0::/0, or 0:0000:0::/0 etc
			} else if (is_ipaddrv6($row['address']) && $row['address'] == '::') {
				$default_ipv6 = true;
			}

			# Check if we can break out early
			if ($default_ipv4 && $default_ipv6) {
				break;
			}
		}
	}

	return [$default_ipv4, $default_ipv6];
}

/*
 * Check if a given AmneziaWG peer is valid
 */
function awg_peer_is_valid($tunnel_name, $public_key, $running_state = false) {
	global $awgg;

	return in_array($public_key, ($running_state ? awg_tunnel_get_peers_running_keys($tunnel_name) : awg_tunnel_get_peers_config_keys($tunnel_name)));
}

/*
 * Check if a given AmneziaWG tunnel is valid
 */
function awg_is_valid_tunnel($tunnel_name, $running_state = false) {
	global $awgg;

	return in_array($tunnel_name, ($running_state ? awg_get_running_ifs() : awg_get_configured_ifs()));
}

// Checks if the service is or should be enabled
function awg_is_service_enabled() {
	$enable = config_get_path('installedpackages/amneziawg/config/0/enable');

	$is_enabled = (isset($enable) && (($enable === 'on') || $enable === 'yes'));

	$should_be_enabled = awg_is_awg_assigned();

	return ($is_enabled || $should_be_enabled);
}

// Check if at least one tunnel is enabled
function is_awg_enabled() {
	global $awgg;

	awg_globals();

	foreach (config_get_path('installedpackages/amneziawg/tunnels/item', []) as ['enabled' => $enabled]) {
		if (isset($enabled) && $enabled == 'yes') {
			// We found one, no need to keep checking
			return true;
		}
	}

	return false;
}

/*
 * Check if at least one tunnel is assigned to a pfSense interface
 */
function awg_is_awg_assigned($disabled = true) {
	global $awgg;

	foreach (config_get_path('installedpackages/amneziawg/tunnels/item', []) as ['name' => $name]) {
		if (is_awg_tunnel_assigned($name, $disabled)) {
			// We found one, no need to keep checking
			return true;
		}
	}

	return false;
}

function awg_peer_get_config_by_name($tunnel_name, $public_key) {
	$peer_idx = awg_peer_get_array_idx($public_key, $tunnel_name);

	return awg_peer_get_config($peer_idx, false);
}

function awg_tunnel_get_config_by_name($tunnel_name) {
	$tun_idx = awg_tunnel_get_array_idx($tunnel_name);

	return awg_tunnel_get_config($tun_idx, false);
}

function awg_tunnel_get_config($tun_idx, $return_empty = false) {
	awg_globals();

	$tunnel = array();

	$valid = (is_numericint($tun_idx) && is_array(config_get_path("installedpackages/amneziawg/tunnels/item/{$tun_idx}")));

	$is_new = !$valid;

	$tun_idx = !$valid ? count(config_get_path('installedpackages/amneziawg/tunnels/item', [])) : $tun_idx;

	$tunnel = $valid ? config_get_path("installedpackages/amneziawg/tunnels/item/{$tun_idx}") : [];

	array_init_path($tunnel, 'addresses/row');

	return ($valid || $return_empty) ? array($tun_idx, $tunnel, $is_new) : false;
}

/*
 * Return bools indicating if the tunnel referenced has any peers with default IPv4 or IPv6 routes
 */
function awg_tunnel_has_default_route_peer($tunnel_name, $ignore_peer_idx) {
	$default_ipv4 = false;
	$default_ipv6 = false;

	foreach (awg_tunnel_get_peers_config($tunnel_name) as [$peer_idx, $peer, $is_new]) {
		if ($peer_idx != $ignore_peer_idx) {
			[$peer_default_ipv4, $peer_default_ipv6] = awg_peer_is_default_route($peer);

			if ($peer_default_ipv4) {
				$default_ipv4 = true;
			}

			if ($peer_default_ipv6) {
				$default_ipv6 = true;
			}
		}

		# Check if we can stop early
		if ($default_ipv4 && $default_ipv6) {
			break;
		}
	}

	return [$default_ipv4, $default_ipv6];
}

function awg_peer_get_config($peer_idx, $return_empty = false) {
	global $awgg;

	awg_globals();

	$peer = array();

	$valid = (is_numericint($peer_idx) && is_array(config_get_path("installedpackages/amneziawg/peers/item/{$peer_idx}")));

	$is_new = !$valid;

	$peer_idx = !$valid ? count(config_get_path('installedpackages/amneziawg/peers/item', [])) + 1 : $peer_idx;

	$peer = $valid ? config_get_path("installedpackages/amneziawg/peers/item/{$peer_idx}") : [];

	array_init_path($peer, 'allowedips/row');

	return ($valid || $return_empty) ? array($peer_idx, $peer, $is_new) : false;
}

/*
 * This returns an array of peer configs for a given tunnel
 */
function awg_tunnel_get_peers_config($tunnel_name) {
	awg_globals();

	$ret_peers = array();

	// Look through array of peers for matching tunnel name
	foreach (config_get_path('installedpackages/amneziawg/peers/item', []) as $peer_idx => $peer) {
		if ($peer['tun'] == $tunnel_name) {
			$ret_peers[] = awg_peer_get_config($peer_idx, false);
		}
	}

	// Return the list of filtered peers
	return $ret_peers;
}

/*
 * This returns an array of peer keys for a given tunnel
 * 
 * These are actually *running* and bound to a tunnel
 */
function awg_tunnel_get_peers_running_keys($tunnel_name) {
	global $awgg;

	$s = fn($x) => $x;

	$output = $res = array();

	$cmd = "{$awgg['awg']} show {$s(escapeshellarg($tunnel_name))} peers 2>&1";

	exec($cmd, $output, $ret_code);

	$res = ($ret_code <> 0) ? $res : $output;

	return $res;
}

/*
 * This returns an array of peer keys for a given tunnel
 * 
 * These are actually *configured* and bound to a tunnel
 */
function awg_tunnel_get_peers_config_keys($tunnel_name) {
	// Pull out the public keys
	$keys = array_map(function($s) { 
		[$peer_idx, $peer, $is_new] = $s;

		return $peer['publickey'];
	}, awg_tunnel_get_peers_config($tunnel_name));

	return $keys;
}

/* 
 * Return AmneziaWG tunnel networks for a given address family
 */
function awg_get_tunnel_networks($family = 'both') {
	$awg_tunnel_networks = array();

	// Bail out early if AmneziaWG is not enabled...
	if (!is_awg_enabled()) {
		return $awg_tunnel_networks;
	}

	foreach (config_get_path('installedpackages/amneziawg/tunnels/item', []) as $tunnel) {
		if (!is_array($tunnel['addresses'])
		    || !is_array($tunnel['addresses']['row']))
				continue;

		foreach ((array) $tunnel['addresses']['row'] as $address) {
			$masked_address = "{$address['address']}/{$address['mask']}";

			if ((is_ipaddrv6($masked_address) && ($family == 'ipv4')) 
			    || (is_ipaddrv4($masked_address) && ($family == 'ipv6'))) {
				continue;
			}

			if (is_subnet($masked_address)) {
				$network = gen_subnet($address['address'], $address['mask']);

				$awg_tunnel_networks[] 	= array(
								'network' => $network, 
								'mask' => $address['mask'],
								'tun' => $tunnel['name'],
								'descr' => $tunnel['descr']);
			}
		}
	}

	return $awg_tunnel_networks;
}

?>
