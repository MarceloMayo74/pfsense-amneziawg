<?php
/*
 * awg_globals.inc
 *
 * part of pfSense (https://www.pfsense.org)
 * Copyright (c) 2021 R. Christian McDonald (https://github.com/rcmcdonald91)
 * Copyright (c) 2022-2026 Rubicon Communications, LLC (Netgate)
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

// Global defines

// pfSense includes
require_once('config.inc');
require_once('globals.inc');

global $g, $awgg;

define('AWG_ERROR_IF_NAME',		1);
define('AWG_ERROR_IF_CREATE',		2);
define('AWG_ERROR_IF_GROUP',		4);
define('AWG_ERROR_IF_SETADDR',		8);
define('AWG_ERROR_IF_DELADDR',		16);
define('AWG_ERROR_IF_UP',		32);
define('AWG_ERROR_IF_DOWN',		64);
define('AWG_ERROR_IF_SYNC',		128);
define('AWG_ERROR_IF_DESTROY',		256);

/*
 * El data plane es userspace, no un modulo de kernel: en lugar de cargar y
 * descargar if_wg se levanta y se baja un proceso amneziawg-go por tunel.
 * Las condiciones de error que importan son otras.
 */
define('AWG_ERROR_PROC_START',		1);
define('AWG_ERROR_PROC_STOP',		2);
define('AWG_ERROR_PROC_UAPI',		4);

define('AWG_ERROR_PEER_SET',		1);
define('AWG_ERROR_PEER_ENDPOINT',	2);
define('AWG_ERROR_PEER_REMOVE',		4);

define('AWG_ERROR_SVC_DISABLED',		1);
define('AWG_ERROR_SVC_RUNNING',		2);
define('AWG_ERROR_SVC_START',		4);
define('AWG_ERROR_SVC_STOP',		8);
define('AWG_ERROR_SVC_CREATE',		16);

$awgg = array(
	/*
	 * Binarios propios del paquete. Se empaquetan con el .pkg en lugar de
	 * declararse como dependencia: los repos de pfSense no tienen amnezia-*,
	 * y hay un .pkg por ABI justamente porque estos no son intercambiables
	 * entre FreeBSD 15 y 16.
	 */
	'awg'			=> '/usr/local/bin/awg',
	'awg_go'		=> '/usr/local/bin/amneziawg-go',
	'daemon'		=> '/usr/sbin/daemon',
	'ifconfig'		=> '/sbin/ifconfig',
	'pkg'			=> '/usr/sbin/pkg',
	'php'			=> '/usr/local/bin/php',
	'php_awg'		=> '/usr/local/bin/php_awg',
	'awg_pkg_root'		=> '/usr/local/pkg/amneziawg',
	'subsystem_prefix'	=> 'amneziawg',
	'service_name'		=> 'amneziawgd',
	'nrb'			=> '-NoReMoTeBaCkUp',
	'applylist'		=> array(
					'tunnels' => "{$g['tmp_path']}/.amneziawg_tunnel.apply"),
	'pkg_name'		=> 'pfSense-pkg-AmneziaWG',
	'conf_path'		=> '/usr/local/etc/amneziawg',
	'xml_path'		=> 'installedpackages/amneziawg',

	/*
	 * Las interfaces se llaman tun9000..tun9999, no tun_awg0. Tiene que ser
	 * un nombre tunN de los que pfSense ya reconoce, si no la interfaz no
	 * aparece en Interfaces > Assignments y no se puede asignar. El rango
	 * alto evita chocar con OpenVPN. Ver docs/arquitectura.md, seccion 5.
	 */
	'if_prefix'		=> 'tun',
	'if_range_min'		=> 9000,
	'if_range_max'		=> 9999,

	/*
	 * amneziawg-go publica un socket de control por tunel y awg(8) habla con
	 * el. Los dos coinciden en esta ruta, que ademas es distinta de la que
	 * usa el paquete oficial de WireGuard (/var/run/wireguard), asi que los
	 * dos paquetes pueden convivir.
	 */
	'sock_path'		=> '/var/run/amneziawg',
	'run_path'		=> '/var/run/amneziawg',
	'ifgroupentry'		=> array(
					'ifname' => 'AmneziaWG', 
					'descr' => gettext('AmneziaWG Interface Group (DO NOT EDIT/DELETE!)'),
					'members' => null),
	'unboundaclentry'	=> array(
					'aclname' => 'AmneziaWG',
					'aclaction' => 'allow',
					'description' => gettext('AmneziaWG Unbound ACL (DO NOT EDIT/DELETE!)')),
	'listenportalias'	=> array(
					'name' => 'AmneziaWGListenPorts',
					'type' => 'port',
					'descr' => gettext('AmneziaWG ListenPort Alias (DO NOT EDIT/DELETE!)')),
	'handshake_thresholds' 	=> array(
					86400 => array('class' => 'text-danger', 'title' => gettext('Greater than 1 day')),
					300 => array('class' => 'text-warning', 'title' => gettext('Greater than 5 minutes')),
					0 => array('class' => 'text-success', 'title' => gettext('Less than 5 minutes'))),
	'error_flags'		=> array(
					'interface' => array(
						AWG_ERROR_IF_NAME	=> gettext('Invalid AmneziaWG tunnel name'),
						AWG_ERROR_IF_CREATE	=> gettext('Unable to create AmneziaWG tunnel interface'),
						AWG_ERROR_IF_GROUP	=> gettext('Unable to add AmneziaWG tunnel interface to the AmneziaWG interface group'),
						AWG_ERROR_IF_SETADDR	=> gettext('Unable to set AmneziaWG tunnel interface address(es)'),
						AWG_ERROR_IF_DELADDR	=> gettext('Unable to delete AmneziaWG tunnel interface address(es)'),
						AWG_ERROR_IF_UP		=> gettext('Unable to bring up AmneziaWG tunnel interface'),
						AWG_ERROR_IF_DOWN	=> gettext('Unable to bring down AmneziaWG tunnel interface'),
						AWG_ERROR_IF_SYNC	=> gettext('Unable to sync AmneziaWG tunnel configuration with awg(8)'),
						AWG_ERROR_IF_DESTROY	=> gettext('Unable to destroy AmneziaWG tunnel interface')),
					'process' => array(
						AWG_ERROR_PROC_START	=> gettext('Unable to start the amneziawg-go process for this tunnel'),
						AWG_ERROR_PROC_STOP	=> gettext('Unable to stop the amneziawg-go process for this tunnel'),
						AWG_ERROR_PROC_UAPI	=> gettext('The amneziawg-go process did not publish its control socket in time')),
					'peer' => array(
						AWG_ERROR_PEER_SET	=> gettext('Unable to set peer configuration'),
						AWG_ERROR_PEER_ENDPOINT	=> gettext('Unable to resolve peer endpoint'),
						AWG_ERROR_PEER_REMOVE	=> gettext('Unable to remove peer')),
					'service' => array(
						AWG_ERROR_SVC_DISABLED	=> gettext('AmneziaWG service is disabled'),
						AWG_ERROR_SVC_RUNNING	=> gettext('AmneziaWG service is already running'),
						AWG_ERROR_SVC_START	=> gettext('Unable to start AmneziaWG service'),
						AWG_ERROR_SVC_STOP	=> gettext('Unable to stop AmneziaWG service'),
						AWG_ERROR_SVC_CREATE	=> gettext('Unable to create AmneziaWG tunnel(s)'))),
	'default_mtu'				=> 1420,
	'default_port'				=> 51820,
	'default_resolve_interval'		=> 300,

	/*
	 * Los datos del cliente viven en un sub-elemento del peer y no sueltos entre
	 * sus campos: lo que el peer describe es el protocolo -- clave publica,
	 * AllowedIPs que el servidor rutea hacia el cliente -- y lo que va aca
	 * describe al cliente del otro lado, incluida su clave PRIVADA. Separarlos
	 * evita chocar con los campos que escribe el guardado nativo del peer y hace
	 * que "este peer se puede exportar" sea una pregunta de una sola linea.
	 */
	'client_store'				=> 'client',

	// Tope de direcciones a probar buscando una libre, para que una /8 mal
	// puesta no cuelgue la pagina.
	'max_address_probe'			=> 4096,

	// Lo que se le ofrece por defecto a un cliente nuevo: todo el trafico por
	// el tunel, y keepalive puesto porque casi siempre esta detras de un NAT.
	'default_allowedips'			=> '0.0.0.0/0, ::/0',
	'default_keepalive'			=> 25,

	/*
	 * La libreria de QR. Se busca en orden y se usa la primera que exista, asi
	 * una instalacion que ya tenga la de wgeasy no arrastra una segunda copia
	 * de 35 KB del mismo codigo.
	 */
	'www_root'				=> '/usr/local/www',
	'qrcode_js'				=> array(
							'/awg/js/awg_qrcode.js',
							'/wgeasy/js/wgeasy_qrcode.js'),

	/*
	 * El QR se dibuja a 400 px y se descarga a 512. Nivel de correccion 'L'
	 * porque el .conf de AmneziaWG es largo -- lleva 16 parametros mas que uno
	 * de WireGuard-- y mas correccion significa mas modulos, o sea un codigo
	 * mas denso y mas dificil de leer con la camara. Los 4 modulos de zona
	 * tranquila los pide el estandar; sin ellos hay lectores que no encuentran
	 * el codigo.
	 */
	'qr_display_size'			=> 400,
	'qr_size'				=> 512,
	'qr_level'				=> 'L',
	'qr_quiet_zone'				=> 4,

	/*
	 * Watchdog. Cada cuantos minutos corre el cron, y cuanto espera antes de
	 * reintentar un tunel que no arranca.
	 *
	 * El backoff existe porque un tunel que no puede levantar -- puerto tomado,
	 * clave invalida -- si no reintentaria cada minuto para siempre, y cada
	 * intento cuesta hasta 5 segundos esperando el socket. Se duplica hasta el
	 * tope y se limpia solo en cuanto el tunel arranca.
	 */
	'default_watchdog_interval'		=> 5,
	'watchdog_backoff_min'			=> 60,
	'watchdog_backoff_max'			=> 3600,
	'default_widget_activity_threshold'	=> 120,
	'default_widget_refresh_interval'	=> 20,
	'max_port'				=> 65535,

	// Un tunel por nombre disponible en el rango tun9000..tun9999.
	'max_tunnels'				=> 1000,

	/*
	 * Los 16 parametros de ofuscacion. Todos van en [Interface]: ninguno es
	 * por peer. Los rangos salen del modelo de datos del plugin de OPNsense.
	 *
	 * Ojo con H1-H4: son TEXTO, no enteros. Admiten un valor suelto o un
	 * rango con guion (787134324-1593815189). Modelarlos como campo numerico
	 * los rompe en silencio contra configuraciones reales.
	 *
	 * 'awg2' marca los que solo entiende un backend AmneziaWG 2.0. Contra un
	 * backend 1.x no deben escribirse en el .conf.
	 */
	'obfuscation_fields'	=> array(
		'jc'   => array('type' => 'int',  'min' => 1, 'max' => 128,  'awg2' => false),
		'jmin' => array('type' => 'int',  'min' => 1, 'max' => 1280, 'awg2' => false),
		'jmax' => array('type' => 'int',  'min' => 1, 'max' => 1280, 'awg2' => false),
		's1'   => array('type' => 'int',  'min' => 0, 'max' => 1280, 'awg2' => false),
		's2'   => array('type' => 'int',  'min' => 0, 'max' => 1280, 'awg2' => false),
		's3'   => array('type' => 'int',  'min' => 0, 'max' => 1280, 'awg2' => true),
		's4'   => array('type' => 'int',  'min' => 0, 'max' => 1280, 'awg2' => true),
		'h1'   => array('type' => 'hdr',  'min' => 5, 'max' => 4294967295, 'awg2' => false),
		'h2'   => array('type' => 'hdr',  'min' => 5, 'max' => 4294967295, 'awg2' => false),
		'h3'   => array('type' => 'hdr',  'min' => 5, 'max' => 4294967295, 'awg2' => false),
		'h4'   => array('type' => 'hdr',  'min' => 5, 'max' => 4294967295, 'awg2' => false),
		'i1'   => array('type' => 'text', 'awg2' => true),
		'i2'   => array('type' => 'text', 'awg2' => true),
		'i3'   => array('type' => 'text', 'awg2' => true),
		'i4'   => array('type' => 'text', 'awg2' => true),
		'i5'   => array('type' => 'text', 'awg2' => true)),

	// Un valor suelto, o un rango 'desde-hasta'.
	'header_mask'		=> '/^\d{1,10}(-\d{1,10})?$/',

	/*
	 * Defaults de los paquetes basura y el relleno. H1-H4 no tienen default:
	 * se sortean al crear el tunel, porque headers fijos serian una firma
	 * nueva y justamente lo que se busca es no tener ninguna.
	 */
	'default_jc'		=> 4,
	'default_jmin'		=> 40,
	'default_jmax'		=> 70,
	'default_s1'		=> 30,
	'default_s2'		=> 40
);

// These all depend on one more more of the above values

$awgg['awg_classes']		= "{$awgg['awg_pkg_root']}/classes";
$awgg['awg_includes']		= "{$awgg['awg_pkg_root']}/includes";

$awgg['conf_paths_to_clean']	= array($awgg['conf_path'], '/etc/amneziawg');
$awgg['xml_paths_to_clean']	= [$awgg['xml_path'], 'amneziawg'];

/*
 * Sin dependencias de paquete: awg y amneziawg-go van dentro del .pkg. Los
 * repos de pfSense no publican amnezia-*, y los binarios de los ports de
 * FreeBSD estan atados al ABI, que es por lo que hay un .pkg por version.
 */
$awgg['depends_names']		= array($awgg['pkg_name']);

/*
 * Version del protocolo que soporta el backend instalado (1 o 2). No se puede
 * deducir de 'awg --version': ese numero no tiene relacion con la version del
 * paquete. Se detecta probando un setconf con S3/S4 y viendo si lo acepta, una
 * vez al instalar, y se cachea aca. Ver docs/arquitectura.md, seccion 11.
 */
$awgg['awg2_cache']		= "{$awgg['run_path']}/backend_version";

$awgg['pid_path']		= "{$g['varrun_path']}/{$awgg['service_name']}.pid";
$awgg['subsystems']		= array(
					'awg' => $awgg['subsystem_prefix'], 
					'postboot' => "{$awgg['subsystem_prefix']}_postboot");
$awgg['awg_daemon'] 		= "{$awgg['php_awg']} -f {$awgg['awg_includes']}/awg_service.inc";

/*
 * El watchdog es el mismo script del servicio con otro verbo, invocado por
 * cron. Corre y termina: no es un proceso mas que despues habria que vigilar.
 */
$awgg['watchdog_cmd']		= "{$awgg['awg_daemon']} watchdog";
$awgg['watchdog_state']		= "{$awgg['run_path']}/watchdog.state";
$awgg['watchdog_lock']		= "{$awgg['run_path']}/watchdog.lock";

$awgg['shellcmdentries']		= array(
					array(
						'cmd' => "service {$awgg['service_name']} start",
						'cmdtype' => 'earlyshellcmd',
						'description' => 'AmneziaWG earlyshellcmd (DO NOT EDIT/DELETE!)'));

/*
 * Helpers de arrays anidados heredados del paquete de WireGuard.
 *
 * Llevan prefijo del paquete y no el nombre generico con el que venian, que es
 * el que usa el paquete oficial. PHP tiene un unico espacio de nombres para las
 * funciones globales, asi que con los dos paquetes instalados -- que es un
 * objetivo declarado, ver docs/arquitectura.md seccion 8 -- la segunda copia en
 * cargarse aborta con "Cannot redeclare function". No es una colision teorica:
 * pasaba al abrir el dashboard, donde conviven los widgets de ambos.
 *
 * El renombrado mecanico del fork no las toco porque no tienen 'wg' en el
 * nombre. La regla que importa es que todo simbolo global que declare este
 * paquete lleve su prefijo; tools/check-collisions.sh la verifica.
 */
function &awg_array_get_value(array &$array, $parents) {
	$ref = &$array;

	foreach ((array) $parents as $parent) {
		if (is_array($ref) && array_key_exists($parent, $ref)) {
			$ref = &$ref[$parent];
		} else {
			return null;
		}
	}

	return $ref;
}

function awg_array_set_value(array &$array, $parents, $value) {
	$ref = &$array;

	foreach ((array) $parents as $parent) {
		if (isset($ref) && !is_array($ref)) {
			$ref = array();
		}

		$ref = &$ref[$parent];
	}

	$ref = $value;
}

function awg_array_unset_value(&$array, $parents) {
	$key = array_shift($parents);

	if (empty($parents)) {
		unset($array[$key]);
	} else {
		awg_array_unset_value($array[$key], $parents);
	}
}

/*
 * A wrapper for write_config() with the ability to easily toggle ACB on or off
 * 
 * Ephemeral changes to the config should not hammer the ACB system. Only changes
 * to <installedpackages><amneziawg>...</amneziawg></installedpackages> tree should
 * invoke a ACB backup event.
 */
function awg_write_config($desc = 'unknown', $do_acb = false, $backup = true, $write_config_only = false) {
	global $awgg;

	$descACB = fn($desc, $do_acb) => $do_acb ? $desc : "{$desc}{$awgg['nrb']}";

	$desc = "[{$awgg['pkg_name']}] {$descACB($desc, $do_acb)}";

	return write_config($desc, $backup, $write_config_only);
}

/*
 * This populates the $awgg with the latest information from config.xml
 */
function awg_globals() {
	global $awgg;

	// Reload config.xml to get any recent changes
	config_read_file(false, true);
}

// Call this often to read the latest configuration information
awg_globals();

?>
