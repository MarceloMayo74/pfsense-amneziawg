<?php
/*
 * awg_install.inc
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
require_once('services.inc');
require_once('service-utils.inc');
require_once('unbound.inc');
require_once('util.inc');

// AmneziaWG includes
require_once('amneziawg/includes/awg_api.inc');
require_once('amneziawg/includes/awg_globals.inc');
require_once('amneziawg/includes/awg_service.inc');

/*
 * This is the main install hook
 */
function awg_install() {
	global $g;

	$g['amneziawg_installing'] = true;

	update_status("done.\n  Installing AmneziaWG early shell commands...");

	// Installs the AmneziaWG earlyshellcmds
	awg_earlyshellcmd_install();

	update_status("done.\n  Creating AmneziaWG interface group...");

	// Create the 'AmneziaWG' interface group
	awg_ifgroup_install();

	update_status("done.\n  Creating AmneziaWG Unbound access list...");

	// Create the AmneziaWG Unbound ACL
	awg_unbound_acl_install();

	update_status("done.\n  Installing AmneziaWG service...");

	// Create the AmneziaWG service
	awg_service_install();

	update_status("done.\n  Applying AmneziaWG default settings as necessary...");

	awg_defaults_install();

	update_status("done.\n");
}

/*
 * This is the main deinstall hook
 */
function awg_deinstall() {
	awg_globals();

	update_status("  Removing AmneziaWG early shell commands...");

	// Removes the AmneziaWG earlyshellcmds
	awg_earlyshellcmd_deinstall();

	update_status("done.\n  Removing AmneziaWG interface group...");

	// Removes the 'AmneziaWG' interface group
	awg_ifgroup_deinstall();

	update_status("done.\n  Removing AmneziaWG temporary files...");

	// Removes AmneziaWG temporary files
	awg_delete_temp_files();

	// Only delete AmneziaWG configuration if keep_conf is explicitly 'no'
	if (config_get_path('installedpackages/amneziawg/config/0/keep_conf') == 'no') {
		update_status("done.\n  Removing AmneziaWG configuration settings...");

		// Removes AmneziaWG configuration
		awg_remove_config_settings();
	} else {
		update_status("done.\n  Keeping AmneziaWG configuration settings...");
	}

	update_status("done.\n  Removing AmneziaWG Unbound access list...");

	// Remove Unbound ACL
	awg_unbound_acl_deinstall();

	update_status("done.\n  Destroying AmneziaWG tunnels...");

	// Teardown any AmneziaWG tunnel interfaces
	awg_destroy_tunnels();

	update_status("done.\n  Stopping and removing the AmneziaWG service...");

	// Stop and remove the AmneziaWG service
	awg_service_deinstall(true);

	update_status("done.\n");
}

function awg_delete_temp_files() {
	global $awgg;

	// Removes AmneziaWG temporary configuration files
	awg_delete_config_files();

	// This nukes any lingering .apply files
	foreach ($awgg['applylist'] as $path) {
		unlink_if_exists($path);
	}

	// Package is uninstalling, so clear lingering dirty status too...
	foreach ($awgg['subsystems'] as $subsystem) {
		clear_subsystem_dirty($subsystem);
	}
}

/*
 * This just ensures that there are defaults in the XML.
 * The package should function correctly without these being set at all.
 * This is just to make sure the XML is reflecting the running behavior.
 */
function awg_defaults_install() {
	global $awgg;

	// Read latest settings
	awg_globals();

	$config_changed = false;
	$enable_config = awg_is_service_enabled() ? 'on' : 'off';
	if (config_get_path('installedpackages/amneziawg/config/0/enable') !== $enable_config) {
		config_set_path('installedpackages/amneziawg/config/0/enable', $enable_config);
		$config_changed = true;
	}

	if (config_get_path('installedpackages/amneziawg/config/0/keep_conf') === null) {
		config_set_path('installedpackages/amneziawg/config/0/keep_conf', 'yes');
		$config_changed = true;
	}

	if (config_get_path('installedpackages/amneziawg/config/0/resolve_interval') === null) {
		config_set_path('installedpackages/amneziawg/config/0/resolve_interval', $awgg['default_resolve_interval']);
		$config_changed = true;
	}

	if (config_get_path('installedpackages/amneziawg/config/0/resolve_interval_track') === null) {
		config_set_path('installedpackages/amneziawg/config/0/resolve_interval_track', 'no');
		$config_changed = true;
	}

	if (config_get_path('installedpackages/amneziawg/config/0/interface_group') === null) {
		config_set_path('installedpackages/amneziawg/config/0/interface_group', 'all');
		$config_changed = true;
	}

	if (config_get_path('installedpackages/amneziawg/config/0/hide_secrets') === null) {
		config_set_path('installedpackages/amneziawg/config/0/hide_secrets', 'yes');
		$config_changed = true;
	}

	if (config_get_path('installedpackages/amneziawg/config/0/hide_peers') === null) {
		config_set_path('installedpackages/amneziawg/config/0/hide_peers', 'yes');
		$config_changed = true;
	}

	if ($config_changed) {
		awg_write_config('Applied package default settings as necessary.', false);
	}
}

function awg_service_rcfile() {
	global $awgg;

	$start = $stop = array();

	$start[] 		= "{$awgg['awg_daemon']} start";

	$stop[] 		= "{$awgg['awg_daemon']} stop";

	$rcfile['file'] 	= $awgg['service_name'];

	$rcfile['start'] 	= implode("\n\t", $start);

	$rcfile['stop'] 	= implode("\n\t", $stop);

	write_rcfile($rcfile);
}

function awg_service_install() {
	global $awgg;

	awg_service_rcfile();

	awg_service_deinstall();

	link($awgg['php'], $awgg['php_awg']);
}

function awg_service_deinstall($force_stop = false) {
	global $awgg;

	if ($force_stop) {
		awg_service_fpm_stop();
	}

	unlink_if_exists($awgg['php_awg']);
}

function awg_destroy_tunnels(&$cmds = null) {
	global $awgg;

	// Assume this will be successful...
	$res = true;

	// Tear down interfaces
	foreach (awg_get_running_ifs() as $tunnel) {
		$res &= awg_ifconfig_if_destroy($tunnel, $cmds);
	}

	// Now attempt to unload the module if the above was successful
	if ($res) {
		$res &= awg_kld_loadunload(false, $cmds);
	}

	return $res;
}

function awg_listenport_alias_install() {
	awg_globals();

	// TODO ?
}

/*
 * This function creates earlyshellcmd entries in the config
 */
function awg_earlyshellcmd_install() {
	global $awgg;

	awg_globals();

	awg_earlyshellcmd_deinstall(true);

	$a_earlyshellcmd = config_get_path('system/earlyshellcmd', []);

	$a_shellcmdsettings = config_get_path('installedpackages/shellcmdsettings/config', []);

	$shellcmd_pkg_cmds = array_column($a_shellcmdsettings, 'cmd');

	$config_changed = false;
	foreach ($awgg['shellcmdentries'] as $shellcmd) {
		// Need to escape slashes from the path
		$escaped_cmd = preg_quote($shellcmd['cmd'], '/');

		// Install the earlyshellcmd
		if (!preg_grep("/{$escaped_cmd}/", $a_earlyshellcmd)) {
			// Put our entry at the top of the list
			array_unshift($a_earlyshellcmd, $shellcmd['cmd']);
			$config_changed = true;
		}

		// Update the shellcmd package too
		if (!preg_grep("/{$escaped_cmd}/", $shellcmd_pkg_cmds)) {
			// Put our entry at the top of the list
			array_unshift($a_shellcmdsettings, $shellcmd);
			$config_changed = true;
		}
	}

	if ($config_changed) {
		config_set_path('system/earlyshellcmd', $a_earlyshellcmd);
		config_set_path('installedpackages/shellcmdsettings/config', $a_shellcmdsettings);
	
		awg_write_config('Installed earlyshellcmd(s).', false);
	}
}

/*
 * This function removes earlyshellcmd entries in the config
 */
function awg_earlyshellcmd_deinstall(bool $reload = false) {
	global $awgg;

	awg_globals();

	$a_earlyshellcmd = config_get_path('system/earlyshellcmd', []);

	$a_shellcmdsettings = config_get_path('installedpackages/shellcmdsettings/config', []);
	if (empty($a_shellcmdsettings)) {
		return;
	}

	$shellcmd_pkg_cmds = array_column($a_shellcmdsettings, 'cmd');

	$config_changed = false;
	foreach ($awgg['shellcmdentries'] as $shellcmd) {
		// Need to escape slashes from the path
		$escaped_cmd = preg_quote($shellcmd['cmd'], '/');

		// Deinstall the earlyshellcmd
		if ($cmds = preg_grep("/{$escaped_cmd}/", $a_earlyshellcmd)) {
			foreach ($cmds as $cmd_idx => $cmd) {
				// When these are to be readded, only remove them if they are not at the top of the list
				if ($reload && ($a_earlyshellcmd[$cmd_idx] == $a_earlyshellcmd[array_key_first($a_earlyshellcmd)])) {
					continue;
				}
				unset($a_earlyshellcmd[$cmd_idx]);
				$config_changed = true;
			}
		}

		// Update the shellcmd package too
		if ($cmds = preg_grep("/{$escaped_cmd}/", $shellcmd_pkg_cmds)) {
			foreach ($cmds as $cmd_idx => $cmd) {
				// When these are to be readded, only remove them if they are not at the top of the list
				if ($reload && ($a_shellcmdsettings[$cmd_idx] == $a_shellcmdsettings[array_key_first($a_shellcmdsettings)])) {
					continue;
				}
				unset($a_shellcmdsettings[$cmd_idx]);
				$config_changed = true;
			}
		}
	}

	if ($config_changed) {
		config_set_path('system/earlyshellcmd', $a_earlyshellcmd);
		config_set_path('installedpackages/shellcmdsettings/config', $a_shellcmdsettings);

		awg_write_config('De-installed earlyshellcmd(s).', false);
	}
}

/*
 * This function creates the AmneziaWG interface group
 */
function awg_ifgroup_install() {
	global $awgg;

	awg_globals();

	awg_ifgroup_deinstall(true);

	if (config_get_path('installedpackages/amneziawg/config/0/interface_group') == 'none') {
		// No point installing the interface group...
		return;
	}

	$a_ifgroups = config_get_path('ifgroups/ifgroupentry', []);

	$ifgroup_names = array_column($a_ifgroups, 'ifname');

	$config_changed = false;
	if (!preg_grep("/{$awgg['ifgroupentry']['ifname']}/", $ifgroup_names)) {
		// Put our entry at the top of the list
		array_unshift($a_ifgroups, $awgg['ifgroupentry']);
		$config_changed = true;
	}

	if ($config_changed) {
		config_set_path('ifgroups/ifgroupentry', $a_ifgroups);

		awg_write_config("Installed interface group ({$awgg['ifgroupentry']['ifname']}).", false);
	}
}

/*
 * This function removes the AmneziaWG interface group
 */
function awg_ifgroup_deinstall(bool $reload = false) {
	global $awgg;

	awg_globals();

	$a_ifgroups = config_get_path('ifgroups/ifgroupentry', []);
	if (empty($a_ifgroups)) {
		return;
	}

	$ifgroup_names = array_column($a_ifgroups, 'ifname');

	$config_changed = false;
	if ($groups = preg_grep("/{$awgg['ifgroupentry']['ifname']}/", $ifgroup_names)) {
		foreach ($groups as $group_idx => $group) {
			// When these are to be readded, only remove them if they are not at the top of the list
			if ($reload && ($a_ifgroups[$group_idx] == $a_ifgroups[array_key_first($a_ifgroups)])) {
				continue;
			}
			unset($a_ifgroups[$group_idx]);
			$config_changed = true;
		}
	}

	if ($config_changed) {
		config_set_path('ifgroups/ifgroupentry', $a_ifgroups);

		awg_write_config("De-installed interface group ({$awgg['ifgroupentry']['ifname']}).", false);
	}
}

/*
 * This function removes the AmneziaWG Unbound access list
 */
function awg_unbound_acl_deinstall(bool $reload = false) {
	global $awgg;

	awg_globals();

	$a_unbound_acls = config_get_path('unbound/acls');
	if (empty($a_unbound_acls)) {
		return;
	}

	$a_unbound_acls_names = array_column($a_unbound_acls, 'aclname');

	$config_changed = false;
	if ($acls = preg_grep("/{$awgg['unboundaclentry']['aclname']}/", $a_unbound_acls_names)) {
		foreach ($acls as $acl_idx => $acl) {
			// When these are to be readded, only remove them if they are not at the top of the list
			if ($reload && ($a_unbound_acls[$acl_idx] == $a_unbound_acls[array_key_first($a_unbound_acls)])) {
				continue;
			}
			unset($a_unbound_acls[$acl_idx]);
			$config_changed = true;
		}
	}

	if ($config_changed) {
		config_set_path('unbound/acls', $a_unbound_acls);

		awg_write_config("De-installed Unbound ACL group ({$awgg['unboundaclentry']['aclname']}).", false);
	}
}

/*
 * This function creates the AmneziaWG Unbound access list
 */
function awg_unbound_acl_install() {
	global $awgg;

	// Format tunnel description if one is configured
	$tunDescr = fn($tunnel) => !empty($tunnel['descr']) ? " ({$tunnel['descr']})" : null;

	awg_globals();

	awg_unbound_acl_deinstall(true);

	$tun_networks = awg_get_tunnel_networks();

	$a_unbound_acls = config_get_path('unbound/acls', []);

	$a_unbound_acls_names = array_column($a_unbound_acls, 'aclname');

	$config_changed = false;
	// Only need to create the ACL if we actually have tunnel networks to add
	if (!empty($tun_networks) && is_array($tun_networks)) {
		if (!preg_grep("/{$awgg['unboundaclentry']['aclname']}/", $a_unbound_acls_names)) {
			$awgg['unboundaclentry']['aclid'] = unbound_get_next_id();

			$tmp_acls = array();

			foreach ($tun_networks as $network_idx => $network) {
				$tmp_acls[$network_idx]['acl_network'] 	= $network['network'];

				$tmp_acls[$network_idx]['mask']		= $network['mask'];

				$tmp_acls[$network_idx]['description']	= "{$network['tun']}{$tunDescr($network['descr'])}";

				$awgg['unboundaclentry']['row'] = $tmp_acls;
			}

			// Put our entry at the top of the list
			array_unshift($a_unbound_acls, $awgg['unboundaclentry']);
			$config_changed = true;
		}
	}

	if ($config_changed) {
		config_set_path('unbound/acls', $a_unbound_acls);

		awg_write_config("Installed Unbound ACL group ({$awgg['unboundaclentry']['aclname']}).", false);
	}
}

?>
