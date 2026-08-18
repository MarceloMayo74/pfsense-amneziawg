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
	'service_title'		=> 'AmneziaWG service',
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
	 * porque el .conf de AmneziaWG es largo -- lleva hasta 25 parametros mas que uno
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
	 * Los 25 parametros de ofuscacion. Todos van en [Interface]: ninguno es
	 * por peer. Los rangos de 1.x y 2.0 salen del modelo de datos del plugin de
	 * OPNsense; los de 3.x, del config.c de amneziawg-tools.
	 *
	 * Ojo con H1-H4: son TEXTO, no enteros. Admiten un valor suelto o un
	 * rango con guion (787134324-1593815189). Modelarlos como campo numerico
	 * los rompe en silencio contra configuraciones reales. Los seis campos de
	 * 3.0 que son 'range' tienen la misma forma, pero en 16 bits.
	 *
	 * 'key' es el nombre de la clave en el .conf. Solo hace falta cuando no es
	 * el nombre del campo capitalizado: jc da Jc y s1 da S1, pero
	 * contentpaddingaddition daria Contentpaddingaddition, que awg(8) no
	 * reconoce -- y una clave que no reconoce le hace abortar el archivo entero.
	 *
	 * 'version' es el nivel de AmneziaWG que hace falta para entender el campo.
	 * Contra un backend --o un cliente-- de un nivel menor no deben escribirse
	 * en el .conf, por lo mismo.
	 */
	'obfuscation_fields'	=> array(
		'jc'   => array('type' => 'int',  'min' => 1, 'max' => 128,  'version' => 1),
		'jmin' => array('type' => 'int',  'min' => 1, 'max' => 1280, 'version' => 1),
		'jmax' => array('type' => 'int',  'min' => 1, 'max' => 1280, 'version' => 1),
		's1'   => array('type' => 'int',  'min' => 0, 'max' => 1280, 'version' => 1),
		's2'   => array('type' => 'int',  'min' => 0, 'max' => 1280, 'version' => 1),
		's3'   => array('type' => 'int',  'min' => 0, 'max' => 1280, 'version' => 2),
		's4'   => array('type' => 'int',  'min' => 0, 'max' => 1280, 'version' => 2),
		'h1'   => array('type' => 'hdr',  'min' => 5, 'max' => 4294967295, 'version' => 1),
		'h2'   => array('type' => 'hdr',  'min' => 5, 'max' => 4294967295, 'version' => 1),
		'h3'   => array('type' => 'hdr',  'min' => 5, 'max' => 4294967295, 'version' => 1),
		'h4'   => array('type' => 'hdr',  'min' => 5, 'max' => 4294967295, 'version' => 1),
		'i1'   => array('type' => 'text', 'version' => 2),
		'i2'   => array('type' => 'text', 'version' => 2),
		'i3'   => array('type' => 'text', 'version' => 2),
		'i4'   => array('type' => 'text', 'version' => 2),
		'i5'   => array('type' => 'text', 'version' => 2),

		// 3.0: la clave de proteccion de headers y el relleno de contenido
		'headerprotectionkey'	=> array('type' => 'key',   'key' => 'HeaderProtectionKey',    'version' => 3),
		'contentpaddingaddition'=> array('type' => 'range', 'key' => 'ContentPaddingAddition', 'min' => 0, 'max' => 65535, 'version' => 3),

		// 3.0: los tiempos del protocolo, que hasta 2.0 eran constantes
		'rekeyaftertime'	=> array('type' => 'range', 'key' => 'RekeyAfterTime',       'min' => 0, 'max' => 65535, 'version' => 3),
		'rekeytimeout'		=> array('type' => 'range', 'key' => 'RekeyTimeout',         'min' => 0, 'max' => 65535, 'version' => 3),
		'rejectaftertime'	=> array('type' => 'range', 'key' => 'RejectAfterTime',      'min' => 0, 'max' => 65535, 'version' => 3),
		'keepalivetimeout'	=> array('type' => 'range', 'key' => 'KeepaliveTimeout',     'min' => 0, 'max' => 65535, 'version' => 3),
		'maxhandshakeattempts'	=> array('type' => 'range', 'key' => 'MaxHandshakeAttempts', 'min' => 0, 'max' => 65535, 'version' => 3),

		// 3.1: los dos booleanos, que se escriben on/off y no 1/0
		'randomtrailers'	=> array('type' => 'bool',  'key' => 'RandomTrailers',       'version' => 4),
		'disablecookies'	=> array('type' => 'bool',  'key' => 'DisableCookies',       'version' => 4)),

	/*
	 * Los niveles de AmneziaWG, que es lo que elige el selector del tunel.
	 *
	 * La pregunta que hace el selector NO es "que version tengo" sino "que
	 * entiende el extremo mas debil de este tunel". El backend se detecta solo
	 * --awg_backend_version()-- y pone el techo; abajo de ese techo la eleccion
	 * es del usuario, porque el otro extremo puede ser mas viejo: la app de
	 * Android estable es 2.0.x y todo lo 3.x esta publicado como prerelease.
	 *
	 * 3.0 (amneziawg-tools v3.0.20260730 en adelante) agrega en [Interface]
	 * ContentPaddingAddition (rango u16), HeaderProtectionKey (una clave) y los
	 * timings RekeyAfterTime, RekeyTimeout, RejectAfterTime, KeepaliveTimeout y
	 * MaxHandshakeAttempts; 3.1 suma RandomTrailers y DisableCookies, los dos
	 * booleanos. Estan leidos del config.c de las tools, no de la doc.
	 *
	 * 3.0 y 3.1 son escalones separados a proposito, aunque el mismo binario
	 * entienda los dos: un cliente 3.0 rechaza el .conf entero al ver
	 * RandomTrailers, exactamente como uno de 1.x lo rechaza al ver S3. El
	 * escalon existe para poder decir "hasta aca entiende el otro extremo", no
	 * para describir el backend.
	 */
	'awg_versions'	=> array(
		1 => array(
			'label' => 'AmneziaWG 1.x',
			'descr' => 'Jc, Jmin, Jmax, S1, S2 y H1-H4. Lo entiende cualquier cliente de AmneziaWG.'),
		2 => array(
			'label' => 'AmneziaWG 2.0',
			'descr' => 'Agrega S3, S4 e I1-I5. Es lo que entiende la app estable de Android (2.0.x).'),
		3 => array(
			'label' => 'AmneziaWG 3.0',
			'descr' => 'Agrega relleno de contenido, proteccion de headers y los timings.'),
		4 => array(
			'label' => 'AmneziaWG 3.1',
			'descr' => 'Agrega los dos booleanos: colas al azar y cookies apagadas.')),

	/*
	 * Hasta que nivel sabe escribir ESTE paquete, que es una cosa distinta de
	 * hasta donde llega el backend instalado. El techo real es el minimo de los
	 * dos, y lo calcula awg_version_ceiling().
	 */
	'awg_version_implemented'	=> 4,

	// Un valor suelto, o un rango 'desde-hasta'.
	'header_mask'		=> '/^\d{1,10}(-\d{1,10})?$/',

	// Lo mismo pero en 16 bits, que es la forma de los campos 'range' de 3.0.
	'range_mask'		=> '/^\d{1,5}(-\d{1,5})?$/',

	/*
	 * Cuanto relleno necesita CADA tipo de paquete para poder usar la proteccion
	 * de headers, o sea el minimo de S1, S2, S3 y S4 cuando hay
	 * HeaderProtectionKey.
	 *
	 * No es una politica nuestra: es HeaderCipherNonceSize de amneziawg-go. El
	 * nonce del ChaCha20 que cifra el header sale de los primeros 12 bytes del
	 * relleno --HeaderProtectionCipher(crypt[:HeaderCipherNonceSize])-- asi que
	 * un tipo de paquete con menos relleno no tiene donde ponerlo, y
	 * mergeWithDevice() rechaza el setconf ENTERO. El error que llega a la GUI
	 * es 'Invalid argument', que no dice cual de los cuatro campos fue.
	 *
	 * La consecuencia practica esta en S4: venia en cero a proposito porque es
	 * el unico que se paga en cada paquete de datos. Con proteccion de headers
	 * deja de poder ser cero.
	 */
	'header_protection_min_padding'	=> 12,

	/*
	 * Lo que un paquete de datos lleva ademas del payload, para poder decir hasta
	 * donde llega S4 sin fragmentar.
	 *
	 * S4 es el unico relleno que se paga en CADA paquete de datos, asi que su
	 * limite real no es un numero del protocolo sino el MTU:
	 *
	 *   en el cable = MTU + S4 + 32 + (28 en IPv4 | 48 en IPv6)
	 *                        \____/   \_____________________/
	 *                     WG: header    IP + UDP de afuera
	 *                     16 + tag 16
	 *
	 * Con el MTU de fabrica de 1420 y un camino de 1500, eso deja S4 <= 20 sobre
	 * IPv4 y <= 0 sobre IPv6 -- 1420 esta elegido para que un paquete lleno entre
	 * justo en 1500 sobre IPv6, sin margen para nada mas.
	 *
	 * Ojo: 'max' => 1280 en obfuscation_fields NO es un tope del protocolo. Sale
	 * del modelo de datos del plugin de OPNsense, que es de donde salieron los
	 * rangos de 1.x y 2.0. En amneziawg-tools transport_packet_junk_size es un
	 * uint16_t leido con parse_uint16 (containers.h) y en amneziawg-go es un
	 * uint32 sin cota, asi que los dos aceptan hasta 65535. Hay generadores por
	 * ahi que afirman un tope de 32 citando config.c; ese numero no esta en
	 * config.c ni en ningun otro lado de las fuentes.
	 */
	'transport_overhead'	=> 32,
	'outer_overhead_v4'	=> 28,
	'outer_overhead_v6'	=> 48,
	'path_mtu_assumed'	=> 1500,

	/*
	 * Los cinco tiempos que 3.0 vuelve configurables, con el valor que el backend
	 * usa cuando el campo esta vacio. Salen de device/constants.go de
	 * amneziawg-go y son los mismos del WireGuard de siempre.
	 *
	 * Hacen falta para validar, no para escribir: las reglas que atan un timing
	 * con otro se rompen igual cuando uno esta puesto y el otro no, y ahi el
	 * backend compara contra estas constantes. Sin ellas, un RejectAfterTime bajo
	 * con los otros dos vacios pasaba la validacion y rompia el tunel.
	 *
	 * Ojo con el cero: UintRange.IsZero() es "el uint64 empaquetado vale 0", asi
	 * que un 0 pelado --o un 0-0-- NO significa cero, significa "sin definir", y
	 * el backend cae en la constante de aca abajo. Un rango 0-N si puede sortear
	 * el cero, porque ahi el empaquetado no da 0.
	 */
	'timing_defaults'	=> array(
		'rekeyaftertime'	=> 120,
		'rekeytimeout'		=> 5,
		'rejectaftertime'	=> 180,
		'keepalivetimeout'	=> 10,
		'maxhandshakeattempts'	=> 18),

	/*
	 * Valores de referencia, y nada mas que eso: se muestran como placeholder
	 * del campo vacio para dar una idea del orden de magnitud.
	 *
	 * **No son defaults.** Un tunel nuevo llega con todo sorteado por
	 * awg_gen_obfuscation(), porque un paquete que se instala en muchos lados
	 * con las mismas constantes hace que todas esas instalaciones emitan los
	 * mismos bytes, y eso es una firma -- lo mismo que ya valia para H1-H4.
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
 * Nivel de AmneziaWG que soporta el backend instalado (1, 2 o 3). No se puede
 * deducir de 'awg --version': ese numero no tiene relacion con la version del
 * paquete --lo escribe el port de FreeBSD con un parche a version.h--. Se
 * detecta probando un setconf con las claves de cada nivel y viendo cual acepta,
 * y se cachea aca. Ver docs/arquitectura.md, seccion 11.
 *
 * Archivo nuevo a proposito: el anterior guardaba 0/1 para "soporta 2.0", y un
 * '1' viejo significa lo contrario que un '1' de ahora. Compartir el nombre
 * habria hecho que una actualizacion del paquete leyera 1.x donde hay 2.0, sin
 * ningun sintoma hasta que un tunel dejara de escribir S3.
 */
$awgg['awg_version_cache']	= "{$awgg['run_path']}/backend_awg_version";

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
$awgg['watchdog_pattern']	= "{$awgg['awg_includes']}/awg_service.inc watchdog";
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
