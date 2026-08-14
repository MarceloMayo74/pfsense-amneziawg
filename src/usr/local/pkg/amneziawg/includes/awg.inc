<?php
/*
 * awg.inc
 *
 * part of pfSense (https://www.pfsense.org)
 * Copyright (c) 2021-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2021 R. Christian McDonald (https://github.com/rcmcdonald91)
 * Copyright (c) 2020 Ascrod
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
require_once('gwlb.inc');
require_once('util.inc');

// AmneziaWG includes
require_once('amneziawg/includes/awg_api.inc');
require_once('amneziawg/includes/awg_install.inc');
require_once('amneziawg/includes/awg_service.inc');
require_once('amneziawg/includes/awg_validate.inc');

global $awgg;

/*
 * This toggles a given tunnel based on index
 */
function awg_toggle_tunnel($tunnel_name) {
	// Assume no changes will be made...
	$changes = false;

	$input_errors = array();

	if ([$tun_idx, $tunnel, $is_new] = awg_tunnel_get_config_by_name($tunnel_name)) {
		$is_enabled = (isset($tunnel['enabled']) && ($tunnel['enabled']) == 'yes');

		// Toggle the tunnel state
		$tunnel['enabled']	= $is_enabled ? 'no' : 'yes';

		$input_errors		= awg_validate_tunnel_toggle($tunnel);

		if (empty($input_errors)) {
			config_set_path("installedpackages/amneziawg/tunnels/item/{$tun_idx}", $tunnel);

			$action_text = $is_enabled ? 'Disabled' : 'Enabled';
			
			// Sync with configuration backend
			awg_write_config("{$action_text} tunnel {$tunnel['name']}.", true);

			// Resync the package
			awg_resync();

			// We've got meaningful changes...
			$changes = true;

			// What tunnel would we need to sync to apply these changes?
			$tuns_to_sync[] = $tunnel['name'];
		}
	}

	return array('input_errors' => $input_errors, 'changes' => $changes, 'tuns_to_sync' => $tuns_to_sync);
}

/*
 * This toggles a given peer based on config array index
 */
function awg_toggle_peer($peer_idx) {
	// Assume no changes will be made...
	$changes = false;

	$input_errors = array();

	if ([$peer_idx, $peer, $is_new] = awg_peer_get_config($peer_idx)) {
		$is_enabled = (isset($peer['enabled']) && ($peer['enabled']) == 'yes');

		// Toggle the peer state
		$peer['enabled']	= $is_enabled ? 'no' : 'yes';

		$input_errors		= awg_validate_peer_toggle($peer);

		// Boilerplate...
		if (empty($input_errors)) {
			config_set_path("installedpackages/amneziawg/peers/item/{$peer_idx}", $peer);

			$action_text = $is_enabled ? 'Disabled' : 'Enabled';

			// Sync with configuration backend
			awg_write_config("{$action_text} peer {$peer['publickey']} on {$peer['tun']}.", true);

			// Resync the package
			awg_resync();

			// This checks if the peer tunnel is a valid tunnel
			if (awg_is_valid_tunnel($peer['tun'])) {
				// We've got meaningful changes...
				$changes = true;

				// What tunnel would we need to sync to apply these changes?
				$tuns_to_sync[] = $peer['tun'];
			}
		}
	}

	return array('input_errors' => $input_errors, 'changes' => $changes, 'tuns_to_sync' => $tuns_to_sync);
}

/*
 * This deletes a given peer based on config array index
 */
function awg_delete_peer($peer_idx) {
	$changes = false;

	$input_errors = array();

	if ([$peer_idx, $peer, $is_new] = awg_peer_get_config($peer_idx)) {
		$input_errors		= awg_validate_peer_delete($peer);

		// Boilerplate...
		if (empty($input_errors)) {
			// Delete the peer
			config_del_path("installedpackages/amneziawg/peers/item/{$peer_idx}");

			// Sync with configuration backend
			awg_write_config("Deleted peer {$peer_idx} ({$peer['publickey']}).", true);

			// Resync the package
			awg_resync();

			// This checks if the peer's tunnel was a valid tunnel
			if (awg_is_valid_tunnel($peer['tun'])) {
				// We've got meaningful changes...
				$changes = true;

				// What tunnel would we need to sync to apply these changes?
				$tuns_to_sync[] = $peer['tun'];
			}
		}
	}

	return array('input_errors' => $input_errors, 'changes' => $changes, 'tuns_to_sync' => $tuns_to_sync);
}

/*
 * This deletes a given tunnel based on name
 */
function awg_delete_tunnel($tunnel_name) {
	$changes = false;

	$input_errors = array();

	if ([$tun_idx, $tunnel, $is_new] = awg_tunnel_get_config_by_name($tunnel_name)) {
		$input_errors		= awg_validate_tunnel_delete($tunnel);

		if (empty($input_errors)) {
			// Delete the tunnel
			config_del_path("installedpackages/amneziawg/tunnels/item/{$tun_idx}");

			// Sync with configuration backend
			awg_write_config("Deleted tunnel {$tunnel['name']}.", true);
			
			// Mark any peers as unassigned
			awg_tunnel_unassign_peers($tunnel['name']);

			// Resync the package
			awg_resync();

			// We've got meaningful changes...
			$changes = true;

			// What tunnel would we need to sync to apply these changes?
			$tuns_to_sync[] = $tunnel['name'];
		}
	}

	return array('input_errors' => $input_errors, 'changes' => $changes, 'tuns_to_sync' => $tuns_to_sync);
}

function awg_toggle_amneziawg() {
	if (awg_is_service_enabled() && !awg_is_service_running()) {
		return awg_service_fpm_start();
	} elseif (!awg_is_service_enabled() && awg_is_service_running()) {
		return awg_service_fpm_stop();
	}

	return false;
}

function awg_tunnel_unassign_peers($tunnel_name) {
	// Assume there is no peers to unassign...
	$changes = false;
	
	foreach (awg_tunnel_get_peers_config($tunnel_name) as [$peer_idx, $peer, $is_new]) {
		config_set_path("installedpackages/amneziawg/peers/item/{$peer_idx}/tun", 'unassigned');

		// We've got at least one, so we need to resync with the backend...
		$changes = true;
	}

	if ($changes) {
		// Sync with configuration backend
		awg_write_config("Unassigned tunnel {$tunnel_name} peers.", true);
	}
}

/*
 * This transforms a raw peer post consisting of repeatables 
 */
function awg_peer_allowedips($post) {
	// Convert the post into proper arrays
	$allowedips = awg_parse_post_repeatables($post, array('address', 'address_subnet', 'address_descr'));

	$ret_array = array();

	// Loop through each address entry
	foreach ($allowedips as $row) {
		// Remove any accidental whitespace
		$row['address'] = trim($row['address']);

		if (!empty($row['address'])) {
			// Transform entries into proper subnet start boundaries
			$row['address'] = gen_subnet($row['address'], $row['address_subnet']);

			$ret_array[] 	= array(
						'address'	=> $row['address'], 
						'mask'		=> $row['address_subnet'], 
						'descr'		=> $row['address_descr']);
		}
	}

	// Consumers expect an array
	return $ret_array;
}

/*
 * This transforms a raw tunnel post consisting of repeatables 
 */
function awg_tunnel_addresses($post) {
	// Convert the post into proper arrays
	$addresses = awg_parse_post_repeatables($post, array('address', 'address_subnet', 'address_descr'));

	$ret_array = array();

	// Loop through each address entry
	foreach ($addresses as $row) {
		// Remove any accidental whitespace
		$row['address'] = trim($row['address']);

		if (!empty($row['address'])) {
			$ret_array[] 	= array(
						'address' 	=> $row['address'], 
						'mask' 		=> $row['address_subnet'], 
						'descr' 	=> $row['address_descr']);
		}
	}

	// Consumers expect an array
	return $ret_array;
}

/*
 * This converts a raw form post containing any repeatables like addresses, subnets, and descriptions and returns an actual array
 */
function awg_parse_post_repeatables($post, $fields = null) {
	$ret_array = array();

	if (is_array($fields)) {
		foreach ($fields as $field) {
			$x = 0;

			while (!is_null($post["{$field}{$x}"])) {
				$ret_array[$x][$field] = $post["{$field}{$x}"];

				$x++;
			}
		}
	}

	return $ret_array;
}

function awg_destroy_rogue_peers($tunnel_name, &$cmds = null) {
	global $awgg;

	// Assume this will be successful...
	$res = true;

	$running_peers = awg_tunnel_get_peers_running_keys($tunnel_name);

	foreach ((array) $running_peers as $key) {
		// This will return false for both invalid and disabled peers...
		if (!awg_peer_is_enabled($tunnel_name, $key)) {
			$res &= awg_peer_set_remove($tunnel_name, $key, $cmds);
		}
	}

	return $res;
}

/*
 * This resolves peer endpoint hostnames per tunnel and updates the kernel accordingly
 * This returns a DateTime object corresponding to the last update time
 */
function awg_resolve_endpoints() {
	// Get the latest info
	awg_globals();
	$dns_available = get_dnsavailable();

	// Do we have any peers?
	foreach (config_get_path('installedpackages/amneziawg/peers/item', []) as $peer) {
		if (!$dns_available && !is_ipaddr($peer['endpoint'])) {
			continue;
		}
		// Strip port if empty
		$peer['port'] = !empty($peer['port']) ? ":{$peer['port']}" : null;

		$endpoint = "{$peer['endpoint']}{$peer['port']}";

		awg_peer_set_endpoint($peer['tun'], $peer['publickey'], $endpoint);
	}

	$last_update_time = new DateTime();

	return $last_update_time;
}

/*
 * Determines if endpoint hostnames should be re-resolved
 * based on the last time the hostnames were re-resolved
 * 
 * An interval of 0 will effectively disable re-resolving
 */
function awg_should_reresolve_endpoints($last_update_time) {
	global $awgg;

	try {
		// Get the current timestamp
		$current_time = (new DateTimeImmutable)->getTimestamp();

		// Calculate the time for the next update event
		$time_to_update = $last_update_time->getTimestamp() + awg_get_endpoint_resolve_interval();

		// Should we update? A 0 TTL means disabled.
		return ((awg_get_endpoint_resolve_interval() > 0) && ($time_to_update <= $current_time));
	} catch (Exception $e) {
		// Something probably went wrong with DateTime, just bail out...
		return false;
	}
}

/*
 * Gets the hostname resolve interval (in seconds)
 * Returns package default if something is wrong with the configuration
 */
function awg_get_endpoint_resolve_interval() {
	global $awgg;

	// Are we tracking the system value?
	if (config_get_path('installedpackages/amneziawg/config/0/resolve_interval_track') == 'yes') {
		if (is_numeric(config_get_path('system/aliasesresolveinterval'))) {
			return config_get_path('system/aliasesresolveinterval');
		}
			
		// Package default is the same as pfSense default (300 seconds)
		return $awgg['default_resolve_interval'];
	} elseif (is_numericint(config_get_path('installedpackages/amneziawg/config/0/resolve_interval'))) {
		return config_get_path('installedpackages/amneziawg/config/0/resolve_interval');
	} else {
		// Something is wrong, so just return the package default...
		return $awgg['default_resolve_interval'];
	}

	// Should never be here...
	return 0;
}

function awg_do_settings_post($post) {
	global $awgg;

	$pconfig = $input_errors = array();

	// Assume no changes will be made...
	$changes = false;

	// We need to save the "old config" to compare against later...
	$pconfig = $old_config = config_get_path('installedpackages/amneziawg/config/0');

	$pconfig['enable']			= (!empty($post['enable']) && $post['enable'] == 'yes') ? 'on' : 'off';
	$pconfig['keep_conf'] 			= empty($post['keep_conf']) ? 'no' : $post['keep_conf'];
	$pconfig['resolve_interval']		= empty($post['resolve_interval']) && ($post['resolve_interval'] !== '0') ? $awgg['default_resolve_interval'] : $post['resolve_interval'];
	$pconfig['resolve_interval_track']	= empty($post['resolve_interval_track']) ? 'no' : $post['resolve_interval_track'];
	$pconfig['interface_group']		= empty($post['interface_group']) ? 'all' : $post['interface_group'];
	$pconfig['hide_secrets']		= empty($post['hide_secrets']) ? 'no' : $post['hide_secrets'];
	$pconfig['hide_peers']			= empty($post['hide_peers']) ? 'no' : $post['hide_peers'];
	$pconfig['watchdog']			= empty($post['watchdog']) ? 'no' : 'yes';
	$pconfig['watchdog_interval']		= empty($post['watchdog_interval']) ? $awgg['default_watchdog_interval'] : $post['watchdog_interval'];

	$input_errors = awg_validate_settings_post($pconfig);

	if (empty($input_errors)) {
		config_set_path('installedpackages/amneziawg/config/0', $pconfig);

		// Sync with configuration backend
		awg_write_config('Saved package settings.', true);

		// La entrada de cron tiene que seguir a la configuracion
		awg_watchdog_cron_sync();

		// Resync the package
		awg_resync();

		// Do we have meaningful changes?
		$changes = ($pconfig != $old_config);
	}

	return array('input_errors' => $input_errors, 'changes' => $changes, 'pconfig' => $pconfig);
}

/*
 * Takes a raw post for a peer, validates it, and saves it to the configuration system
 */
function awg_do_peer_post($post) {
	global $awgg;

	$pconfig = $input_errors = array();

	// Assume no changes will be made...
	$changes = false;

	[$peer_idx, $pconfig, $is_new] = awg_peer_get_config($post['index'], true);

	// We need to save the "old config" to compare against later...
	$old_pconfig = $pconfig;

	$pconfig['enabled'] 		= empty($post['enabled']) ? 'no' : $post['enabled'];
	$pconfig['tun'] 		= $post['tun'];
	$pconfig['descr'] 		= $post['descr'];
	$pconfig['endpoint'] 		= $post['endpoint'];
	$pconfig['port'] 		= empty($post['port']) ? $awgg['default_port'] : $post['port'];
	$pconfig['persistentkeepalive']	= $post['persistentkeepalive'];
	$pconfig['publickey'] 		= $post['publickey'];
	$pconfig['presharedkey']	= $post['presharedkey'];
	$pconfig['allowedips']['row'] 	= awg_peer_allowedips($post);

	// Looks like we have a dynamic endpoint, so clear endpoint or port variables before saving
	if (isset($post['dynamic']) && $post['dynamic'] == 'yes') {
		unset($pconfig['endpoint'], $pconfig['port']);
	}

	$input_errors = awg_validate_peer_post($pconfig, $peer_idx);

	if (empty($input_errors)) {
		config_set_path("installedpackages/amneziawg/peers/item/{$peer_idx}", $pconfig);

		// Sync with configuration backend
		awg_write_config("Updated peer {$peer_idx} ({$pconfig['publickey']}).", true);

		// Resync the package
		awg_resync();

		/*
		 * Figure out what tunnel to sync to make these changes (if any).
		 * 
		 * If the peer is re-unassigned, then $old_pconfig['tun'] needs to be resynced.
		 */
		foreach (array($pconfig['tun'], $old_pconfig['tun']) as $tun) {
			if (awg_is_valid_tunnel($tun)) {
				$changes = ($pconfig != $old_pconfig) || $is_new;
				$tuns_to_sync[] = $tun;
			}
		}
	}

	return array('input_errors' => $input_errors, 'changes' => $changes, 'tuns_to_sync' => $tuns_to_sync, 'pconfig' => $pconfig);
}

/*
 * Takes a raw post for a tunnel, validates it, and saves it to the configuration system
 */
function awg_do_tunnel_post($post) {
	global $awgg;

	$pconfig = $input_errors = array();

	// Assume no changes will be made...
	$changes = false;

	[$tun_idx, $pconfig, $is_new] = awg_tunnel_get_config($post['index'], true);

	// We need to save the "old config" to compare against later...
	$old_pconfig = $pconfig;

	$key = awg_gen_publickey($post['privatekey']);

	$pconfig['name']		= empty($pconfig['name']) ? next_awg_if() : $pconfig['name'];
	$pconfig['enabled']		= empty($post['enabled']) ? 'no' : $post['enabled'];
	$pconfig['descr']		= $post['descr'];
	$pconfig['listenport']		= empty($post['listenport']) ? next_awg_port() : $post['listenport'];
	$pconfig['privatekey']		= $key['privkey'];
	$pconfig['publickey']		= $key['pubkey'];
	$pconfig['mtu']			= empty($post['mtu']) ? $awgg['default_mtu'] : $post['mtu'];
	$pconfig['addresses']['row'] 	= awg_tunnel_addresses($post);

	// Los 16 parametros de ofuscacion. Todos son de interfaz, ninguno por peer.
	foreach (array_keys($awgg['obfuscation_fields']) as $field) {
		$pconfig[$field]	= isset($post[$field]) ? trim($post[$field]) : '';
	}

	$input_errors			= awg_validate_tunnel_post($pconfig, $tun_idx);

	if (empty($input_errors)) {
		config_set_path("installedpackages/amneziawg/tunnels/item/{$tun_idx}", $pconfig);

		// Sync with configuration backend
		awg_write_config("Updated tunnel {$pconfig['name']}.", true);

		// Resync the package
		awg_resync();

		// Do we have meaningful changes?
		$changes = ($pconfig != $old_pconfig);

		// What tunnel would we need to sync to apply these changes?
		$tuns_to_sync[] = $pconfig['name'];
	}

	return array('input_errors' => $input_errors, 'changes' => $changes, 'tuns_to_sync' => $tuns_to_sync, 'pconfig' => $pconfig);
}

function awg_apply_list_get($list, $delete_after_get = true) {
	global $awgg;

	$toapplylist = array();

	if (isset($awgg['applylist'][$list])) {
		$listpath = $awgg['applylist'][$list];

		if (file_exists($listpath)) {
			$toapplylist = (array) unserialize(file_get_contents($listpath));
		}

		// Usually just want to delete the apply list after we read it...
		if ($delete_after_get) {
			unlink_if_exists($listpath);
		}
	}

	return $toapplylist;
}

function awg_apply_list_add($list, $entries) {
	global $awgg;

	$toapplylist = array();

	if (isset($awgg['applylist'][$list])) {
		$listpath = $awgg['applylist'][$list];

		// Get the current list without deleting it...
		$toapplylist = awg_apply_list_get($list, false);

		// Need to type cast $entires to array and remove duplicates
		$toapplylist = array_unique(array_merge($toapplylist, (array) $entries));

		file_put_contents($listpath, serialize($toapplylist));

		$toapplylist = unserialize(file_get_contents($listpath));
	}

	return $toapplylist;
}

/*
 * This compares the running vs configured tunnels and destroys any rogues
 */
function awg_destroy_rogue_tunnels(&$cmds = null) {
	global $awgg;

	// Assume this will be successful
	$res = true;

	/*
	 * Una interfaz del rango que no responde a ninguna configuracion tambien es
	 * rogue, aunque awg(8) no la liste: es lo que queda cuando un proceso se cae
	 * o cuando se borra un tunel que estaba corriendo.
	 */
	$running_ifs = array_unique(array_merge(awg_get_running_ifs(), awg_get_system_ifs()));

	$config_ifs = awg_get_configured_ifs();

	$rogue_tunnels = array_diff($running_ifs, $config_ifs);

	foreach ($rogue_tunnels as $tunnel) {
		$res &= awg_if_destroy($tunnel, $cmds);
	}

	return $res;
}

/*
 * This builds, rebuilds, or destroys tunnel interfaces
 * If $tunnel_names is null, this will apply to all configured tunnel interfaces
 */
function awg_tunnel_sync($tunnel_names, $restart_services = false, $resolve_endpoints = true, $json = false) {
	global $awgg;

	// Fetch and build the latest info
	awg_resync();

	// Let's assume everything will be fine
	$ret_code = 0;

	$tunnels = array();

	// We need to destroy any rogue tunnels not created by pfSense
	awg_destroy_rogue_tunnels();

	// Handle the "apply to all" case
	if (is_null($tunnel_names)
	    || (count($tunnel_names) === 0))
		    $tunnel_names = awg_get_configured_ifs();

	// This is only necessary if the service is running
	if (awg_is_service_running()) {
		// Sync each tunnel
		foreach ((array) $tunnel_names as $tunnel_name) {
			// Attempt to sync the tunnel
			$tun_sync_status = awg_tunnel_sync_by_name($tunnel_name, false);

			// Build an aggregated return code
			$ret_code |= $tun_sync_status['ret_code'];

			// Collect the return from each individual tunnel
			$tunnels[] = array(
					'name' 		=> $tun_sync_status['config']['name'],
					'ret_code' 	=> $tun_sync_status['ret_code'], 
					'errors' 	=> $tun_sync_status['errors'],
					'config' 	=> $tun_sync_status['config']);

			// We need to destroy any rogue peers too...
			awg_destroy_rogue_peers($tunnel_name);
		}
		
		// Reconfigure system routing
		system_routing_configure();
		
		// Reconfigure pf packet filter
		filter_configure();

		// Handle any extra services that need restarting
		if ($restart_services) {
			awg_restart_extra_services();
		}

		// Handle resolving endpoints
		if ($resolve_endpoints) {
			awg_resolve_endpoints();
		}
	}

	$res = array('ret_code' => $ret_code, 'tunnels' => $tunnels);

	return $json ? json_encode($res) : $res;
}

/*
 * This builds or rebuilds a specific tunnel interface by name
 */
function awg_tunnel_sync_by_name($tunnel_name, $json = false) {
	global $awgg;

	$cmds = $errors = $tunnel = array();

	// We've got a tunnel that we need to build...
	if ([$tun_idx, $tunnel, $is_new] = awg_tunnel_get_config_by_name($tunnel_name)) {
		// Determine desired state of the tunnel
		$state = (isset($tunnel['enabled']) && $tunnel['enabled'] == 'yes');

		if (!$state) {
			/*
			 * Deshabilitado tiene que ser sin proceso.
			 *
			 * Con el modulo de kernel alcanzaba con marcar la interfaz down:
			 * crearla era clonar un cloner y no costaba nada. Aca cada tunel es
			 * un proceso, asi que dejarlo corriendo con la interfaz abajo es
			 * dejar prendido justo lo que el usuario apago -- con su puerto
			 * tomado y su socket publicado.
			 */
			awg_if_destroy($tunnel['name'], $cmds);
		} elseif (awg_if_create($tunnel['name'], $cmds)) {
			// Add the tunnel interface to the AmneziaWG interface group
			awg_interface_update_groups($tunnel['name'], $cmds);

			// Update the addresses on the tunnel interface
			awg_interface_update_addresses($tunnel['name'], $cmds);

			// Toggle the interface accordingly instead of tearing it down completely
			awg_ifconfig_if_updown($tunnel['name'], $state, $cmds);

			// Sync interface with AmneziaWG awg(8)
			awg_awg_if_sync($tunnel['name'], $cmds);
		}
	}

	$parsed_cmds = awg_parse_cmds_array($cmds);

	// Build the return array
	$res = array(
		'ret_code'	=> $parsed_cmds['ret_code'], 
		'errors'	=> $parsed_cmds['errors'], 
		'cmds'		=> $parsed_cmds['cmds'],
		'config'	=> $tunnel);

	return $json ? json_encode($res) : $res;
}

/*
 * Parses an array of $cmds
 */
function awg_parse_cmds_array($cmds) {
	$ret_code = 0;
	
	$errors = array();

	if (isset($cmds) && is_array($cmds)) {
		foreach($cmds as $cmd) {
			$ret_code |= $cmd['ret_code'];

			// Unwrap and collect any errors
			array_push($errors, ...$cmd['errors']);
		}
	}

	return array('ret_code' => $ret_code, 'errors' => $errors, 'cmds' => $cmds);
}

/*
 * This performs some routine checks just to make sure everything is still in order
 */
function awg_resync() {
	global $g, $awgg;

	// Update globals
	awg_globals();

	// Create AmneziaWG configuration files
	awg_create_config_files();

	// Not really important if we are installing...
	if (!$g['amneziawg_installing']) {
		// Reinstall earlyshellcmd in case it was accidently deleted
		awg_earlyshellcmd_install();

		// Recreate interface group in case it was accidently deleted
		awg_ifgroup_install();

		// Update Unbound ACL by recreating it
		awg_unbound_acl_install();

		// Update ListenPort Alias by recreating it
		awg_listenport_alias_install();

		// Just as a clarity check...
		awg_defaults_install();
	}

	if (awg_is_service_running()) {
		// Go ahead and resolve DNS when we call package resync
		awg_resolve_endpoints();
	} elseif (!empty(awg_get_running_ifs())) {
		/*
		 * No queremos tuneles activos con el servicio parado. La guarda era
		 * antes 'esta cargado el modulo?', que con el data plane en userspace
		 * no significa nada: ahora se pregunta si quedo algun tunel corriendo.
		 */
		awg_destroy_tunnels();
	}

	// We definitely aren't installing at this point...
	unset($g['amneziawg_installing']);
}

/*
 * Returns an array of enabled tunnels
 */
function awg_get_enabled_tunnels() {
	$ret_tunnels = array();

	foreach (config_get_path('installedpackages/amneziawg/tunnels/item', []) as $tunnel) {
		if (isset($tunnel['enabled']) && $tunnel['enabled'] == 'yes') {
			$ret_tunnels[] = $tunnel;
		}
	}

	return $ret_tunnels;
}

/*
 * Creates (or recreates) all AmneziaWG .conf files based on the current XML configuration
 */
function awg_create_config_files($clean = true) {
	global $awgg;

	// Create configuration path if it is missing
	if (!file_exists($awgg['conf_path'])) {
		mkdir($awgg['conf_path'], 0700, true);
	} else {
		chmod($awgg['conf_path'], 0700);
	}

	// We almost always want to just overwrite the old configurations
	if ($clean) {
		awg_delete_config_files();
	}

	// Check if there are any configurations to write to disk
	foreach (config_get_path('installedpackages/amneziawg/tunnels/item', []) as $tunnel) {
		awg_make_tunnel_conf_file($tunnel, false);
	}
}

/* 
 * Remove all awg .conf files from any potential configuration directory
 */
function awg_delete_config_files() {
	global $awgg;

	// Loop through each potential conf path and delete all .conf files
	foreach ($awgg['conf_paths_to_clean'] as $path) {
		unlink_if_exists("{$path}/*.conf");
	}
}

/* 
 * Removes any configuration xml paths as defined by $awgg['xml_conf_tags']
 */
function awg_remove_config_settings() {
	global $awgg;

	// Loop through each potential conf path and unset it
	foreach ($awgg['xml_paths_to_clean'] as $path) {
		config_del_path($path);
	}

	// Now write out the new config to disk
	awg_write_config('Removed package configuration and settings.', false);
}

/*
 * Writes a AmneziaWG configuration file for a given tunnel to the configuration path
 */
function awg_make_tunnel_conf_file($tunnel, $include_endpoint = false) {
	global $awgg;

	// Init a new awgconfig object
	$wc = new awgconfig($tunnel['name'], true);

	$wc->set_path("{$awgg['conf_path']}/{$tunnel['name']}.conf");

	$wc->initialize_file("Description: {$tunnel['descr']}");

	// Process interface PrivateKey
	$key = awg_gen_publickey($tunnel['privatekey']);

	$wc->set_interface_attr('PrivateKey', $key['privkey_clamped']);

	// Process interface ListenPort
	$wc->set_interface_attr('ListenPort', $tunnel['listenport']);

	/*
	 * Los parametros de ofuscacion, todos a nivel [Interface].
	 *
	 * Los de AWG 2.0 se omiten contra un backend 1.x, y esto es lo unico que
	 * de verdad tiene que respetar la version: awg(8) aborta el parseo del
	 * archivo entero al encontrar una clave que no conoce, asi que un S3 de
	 * mas no degrada la ofuscacion sino que deja el tunel sin levantar.
	 *
	 * El nombre de la clave es el del campo capitalizado -- jc/jmin/s1/h1/i1
	 * dan Jc/Jmin/S1/H1/I1 -- que es exactamente como los nombra el parser de
	 * amneziawg-tools.
	 */
	$awg2 = awg_backend_supports_awg2();

	foreach ($awgg['obfuscation_fields'] as $field => $spec) {
		$value = trim((string) $tunnel[$field]);

		if (($value === '') || ($spec['awg2'] && !$awg2)) {
			continue;
		}

		$wc->set_interface_attr(ucfirst($field), $value);
	}

	// Process peer sections
	foreach (awg_tunnel_get_peers_config($tunnel['name']) as [$peer_idx, $peer, $is_new]) {
		if (isset($peer['enabled']) && $peer['enabled'] == 'yes') {
			// Process peer entry and PublicKey
			$wc->add_peer($peer['publickey'], "Peer: {$peer['descr']}");

			// Process PresharedKey
			$wc->set_peer_attr($peer['publickey'], 'PresharedKey', $peer['presharedkey']);

			// Process AllowedIPs
			foreach ((array) $peer['allowedips']['row'] as $ip) {
				$wc->add_peer_attr($peer['publickey'], 'AllowedIPs', "{$ip['address']}/{$ip['mask']}");
			}

			// Process PersistentKeepalive
			$keepalive = $peer['persistentkeepalive'];

			$keepalive = (!empty($keepalive) && is_numericint($keepalive)) ? $keepalive : '0';

			$wc->set_peer_attr($peer['publickey'], 'PersistentKeepalive', $keepalive);
		}
	}

	$wc->write_file();
}

function awg_download_tunnel($tunnel_name, $failure_redirect) {
	global $awgg;

	$tunnel_name = basename(trim($tunnel_name));

	// Fetch and build the latest info
	awg_resync();

	$now = new DateTimeImmutable();

	$stamp = $now->format('YmdHis');

	$conf_path = "{$awgg['conf_path']}/{$tunnel_name}.conf";

	$name = "tunnel-{$tunnel_name}-{$stamp}.conf";

	if (file_exists($conf_path)) {
		send_user_download('file', $conf_path, $name);
	}

	// If something goes wrong, bail out to the failure redirect location
	header("Location: {$failure_redirect}");
}

function awg_autoloader($classname) {
	global $awgg;

	$path = "{$awgg['awg_classes']}/{$classname}.class.php";

	if (file_exists($path)){
		require_once($path);
	}
}

spl_autoload_register('awg_autoloader');

?>
