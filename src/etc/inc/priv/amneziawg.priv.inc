<?php
/*
 * amneziawg.priv.inc
 *
 * part of pfSense (https://www.pfsense.org)
 * Copyright (c) 2021-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2021 R. Christian McDonald
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

global $priv_list;

$priv_list['page-vpn-amneziawg'] = array();
$priv_list['page-vpn-amneziawg']['name'] = "WebCfg - VPN: AmneziaWG";
$priv_list['page-vpn-amneziawg']['descr'] = "Allow access to the 'VPN: AmneziaWG' page.";
$priv_list['page-vpn-amneziawg']['match'] = array();

$priv_list['page-vpn-amneziawg']['match'][] = "wg/vpn_aawg_tunnels.php*";
$priv_list['page-vpn-amneziawg']['match'][] = "wg/vpn_aawg_tunnels_edit.php*";
$priv_list['page-vpn-amneziawg']['match'][] = "wg/vpn_aawg_peers.php*";
$priv_list['page-vpn-amneziawg']['match'][] = "wg/vpn_aawg_peers_edit.php*";
$priv_list['page-vpn-amneziawg']['match'][] = "wg/vpn_aawg_settings.php";

$priv_list['page-status-amneziawg'] = array();
$priv_list['page-status-amneziawg']['name'] = "WebCfg - Status: AmneziaWG Status";
$priv_list['page-status-amneziawg']['descr'] = "Allow access to the 'Status: AmneziaWG Status' page.";
$priv_list['page-status-amneziawg']['match'] = array();

$priv_list['page-status-amneziawg']['match'][] = "wg/status_amneziawg.php";

?>
