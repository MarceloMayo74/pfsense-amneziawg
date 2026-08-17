<?php
/*
 * test-import.php - el parser de .conf ajenos, sin firewall.
 *
 *   php tools/test-import.php
 *
 * Lo que se prueba aca es que importar sea el espejo exacto de exportar: un
 * archivo generado por este mismo paquete tiene que volver a entrar sin perder
 * ni un parametro. Si se pierde uno solo de los 25 de ofuscacion, el tunel
 * importado no levanta contra el servidor que lo emitio -- y no da error,
 * simplemente el handshake no cierra.
 */

$src = __DIR__ . '/../src/usr/local/pkg/amneziawg/includes';

function extract_function($path, $name) {
	$code = @file_get_contents($path);

	if ($code === false) {
		fwrite(STDERR, "No se pudo leer {$path}\n");
		exit(2);
	}

	if (!preg_match('/^function\s+' . preg_quote($name, '/') . '\s*\(.*?^\}$/ms', $code, $m)) {
		fwrite(STDERR, "No se encontro {$name}() en {$path}\n");
		exit(2);
	}

	return $m[0];
}

if (!function_exists('gettext')) {
	function gettext($s) { return $s; }
}

// Lo que el parser necesita del entorno de pfSense
if (!function_exists('is_ipaddrv6')) {
	function is_ipaddrv6($v) { return (strpos((string) $v, ':') !== false); }
}

$globals = file_get_contents("{$src}/awg_globals.inc");

preg_match("/'obfuscation_fields'.*?'disablecookies'.*?\)\),/s", $globals, $fields);

if (empty($fields)) {
	fwrite(STDERR, "No se pudieron leer obfuscation_fields de awg_globals.inc\n");
	exit(2);
}

preg_match("/'default_mtu'\s*=>\s*(\d+)/", $globals, $mtu);

eval('$awgg = array(' . rtrim($fields[0], ',') . ", 'default_mtu' => {$mtu[1]});");

// El techo y los nombres los pone el firewall; aca se fijan para poder probar
$test_ceiling = 4;
$test_if = 'tun9000';
$test_port = 51820;

function awg_version_ceiling($use_cache = true) { global $test_ceiling; return $test_ceiling; }
function next_awg_if() { global $test_if; return $test_if; }
function next_awg_port() { global $test_port; return $test_port; }

/*
 * Claves de verdad: el validador mira el largo decodificado, asi que no sirve
 * cualquier cadena. La publica se deriva de la privada, cosa que fuera de
 * pfSense no se puede hacer, asi que se devuelve una fija -- lo que importa es
 * que el parser la ponga donde va.
 */
function awg_is_valid_key($key) {
	$raw = base64_decode((string) $key, true);

	return ($raw !== false) && (strlen($raw) === 32);
}

function awg_gen_publickey($privkey, $json = false) {
	return array(
		'privkey'		=> $privkey,
		'privkey_clamped'	=> $privkey,
		'pubkey'		=> 'cGDVKdiXQL1p+U9CD9zSHGCLKgnHFsGVFyKw1UPFMWo=');
}

eval(extract_function("{$src}/awg_api.inc", 'awg_ports_in_use'));
eval(extract_function("{$src}/awg_import.inc", 'awg_import_parse'));
eval(extract_function("{$src}/awg_import.inc", 'awg_import_level'));
eval(extract_function("{$src}/awg_import.inc", 'awg_import_build'));
eval(extract_function("{$src}/awg_import.inc", 'awg_import_split_list'));
eval(extract_function("{$src}/awg_import.inc", 'awg_import_split_endpoint'));

$pass = $fail = 0;

function check($name, $cond, $detail = '') {
	global $pass, $fail;

	if ($cond) {
		$pass++;
		printf("  ok   %s\n", $name);
	} else {
		$fail++;
		printf("  FAIL %s  %s\n", $name, $detail);
	}
}

$clave_a = base64_encode(str_repeat("\x41", 32));
$clave_b = base64_encode(str_repeat("\x42", 32));
$clave_c = base64_encode(str_repeat("\x43", 32));
$hpk     = base64_encode(str_repeat("\x44", 32));

/*
 * Un archivo como el que genera este mismo paquete para un tunel 3.1: los 25
 * parametros, con la forma exacta que tiene la exportacion.
 */
$conf = <<<CONF
# Telefono de prueba
# AmneziaWG - generado por pfSense el 2026-08-17 14:17:52
# Contiene una clave privada. No lo compartas.

[Interface]
PrivateKey = {$clave_a}
Address = 15.15.15.4/24, fd00::4/64
DNS = 1.1.1.2, 1.0.0.2
MTU = 1420
Jc = 6
Jmin = 80
Jmax = 118
S1 = 39
S2 = 50
S3 = 35
S4 = 16
H1 = 1209559099
H2 = 2511843952
H3 = 4284098093
H4 = 1892313715
I1 = <b 0x3347adbba88c><t><r 46>
I2 = <b 0xedf539ca5848><t>
I3 = <b 0x0b4d><t>
I4 = <b 0xd359e9587263><t>
I5 = <b 0x1d67342d1725><rd 3>
HeaderProtectionKey = {$hpk}
ContentPaddingAddition = 12-40
RandomTrailers = on

[Peer]
PublicKey = {$clave_b}
PresharedKey = {$clave_c}
AllowedIPs = 15.15.15.0/24, 192.168.30.0/24
Endpoint = mayosystems.duckdns.org:51822
PersistentKeepalive = 25
CONF;

echo "=== el parseo ===\n";

$parsed = awg_import_parse($conf, $err);

check('parsea un .conf completo', $parsed !== false, (string) $err);
check('encuentra la seccion de interfaz', !empty($parsed['interface']));
check('encuentra un peer', count($parsed['peers']) === 1, count($parsed['peers']) . ' peers');
check('ignora los comentarios', !isset($parsed['interface']['# telefono de prueba']));
check('normaliza las claves a minuscula', isset($parsed['interface']['privatekey'], $parsed['interface']['headerprotectionkey']));
check('no se come el = de una clave base64',
      $parsed['interface']['privatekey'] === $clave_a,
      $parsed['interface']['privatekey']);
check('conserva los espacios y simbolos de un I',
      $parsed['interface']['i1'] === '<b 0x3347adbba88c><t><r 46>',
      $parsed['interface']['i1']);

echo "\n=== lo que rechaza ===\n";

check('un archivo sin [Interface]', awg_import_parse("[Peer]\nPublicKey = x\n", $e1) === false);
check('una linea suelta que no es clave = valor',
      awg_import_parse("[Interface]\nPrivateKey = x\nesto no es nada\n", $e2) === false);
check('una seccion que no existe',
      awg_import_parse("[Interface]\nPrivateKey = x\n[Servidor]\n", $e3) === false);
check('una clave antes de cualquier seccion',
      awg_import_parse("PrivateKey = x\n[Interface]\n", $e4) === false);
check('y el error dice el numero de linea', strpos((string) $e2, '3') !== false, (string) $e2);

echo "\n=== varios peers ===\n";

$dos = awg_import_parse("[Interface]\nPrivateKey = {$clave_a}\n[Peer]\nPublicKey = {$clave_b}\n[Peer]\nPublicKey = {$clave_c}\n", $err);

check('parsea dos peers', count($dos['peers']) === 2);
check('y no los mezcla',
      ($dos['peers'][0]['publickey'] === $clave_b) && ($dos['peers'][1]['publickey'] === $clave_c));

echo "\n=== el nivel que pide el archivo ===\n";

check('un archivo 1.x pide 1',
      awg_import_level(array('jc' => '4', 'h1' => '5')) === 1);
check('con S3 pide 2',
      awg_import_level(array('jc' => '4', 's3' => '20')) === 2);
check('con HeaderProtectionKey pide 3',
      awg_import_level(array('headerprotectionkey' => $hpk)) === 3);
check('con RandomTrailers pide 4',
      awg_import_level(array('randomtrailers' => 'on')) === 4);
check('un campo vacio no cuenta',
      awg_import_level(array('s3' => '', 'headerprotectionkey' => '')) === 1);

echo "\n=== el tunel que sale ===\n";

$built = awg_import_build($parsed, 'Prueba', $err);

check('construye', $built !== false, (string) $err);
check('toma la clave privada del archivo', $built['tunnel']['privatekey'] === $clave_a);
check('deriva la publica', !empty($built['tunnel']['publickey']));
check('toma el MTU', $built['tunnel']['mtu'] === '1420');
check('pide un puerto propio y no el del archivo', $built['tunnel']['listenport'] === '51820');
check('parte las dos direcciones',
      count($built['tunnel']['addresses']['row']) === 2,
      json_encode($built['tunnel']['addresses']));
check('con su mascara',
      ($built['tunnel']['addresses']['row'][0] === array('address' => '15.15.15.4', 'mask' => '24', 'descr' => '')),
      json_encode($built['tunnel']['addresses']['row'][0]));

$perdidos = array();

foreach (array('jc', 'jmin', 'jmax', 's1', 's2', 's3', 's4', 'h1', 'h2', 'h3', 'h4',
	       'i1', 'i2', 'i3', 'i4', 'i5', 'headerprotectionkey',
	       'contentpaddingaddition', 'randomtrailers') as $field) {
	if (trim((string) $built['tunnel'][$field]) === '') {
		$perdidos[] = $field;
	}
}

check('no pierde NINGUN parametro de ofuscacion', empty($perdidos), implode(',', $perdidos));
check('y los que el archivo no traia quedan vacios, no inventados',
      $built['tunnel']['rekeyaftertime'] === '');
check('el nivel sale del archivo', $built['tunnel']['awgversion'] === '4', $built['tunnel']['awgversion']);

$test_ceiling = 2;
$built_2 = awg_import_build($parsed, '', $err);
check('y se topea con lo que el firewall alcanza', $built_2['tunnel']['awgversion'] === '2');
check('pero los valores igual se guardan, para cuando el techo suba',
      $built_2['tunnel']['headerprotectionkey'] === $hpk);
$test_ceiling = 4;

echo "\n=== el peer que sale ===\n";

$peer = $built['peers'][0];

check('toma la clave publica', $peer['publickey'] === $clave_b);
check('toma la preshared', $peer['presharedkey'] === $clave_c);
check('parte el endpoint en host y puerto',
      ($peer['endpoint'] === 'mayosystems.duckdns.org') && ($peer['port'] === '51822'),
      "{$peer['endpoint']} / {$peer['port']}");
check('toma el keepalive', $peer['persistentkeepalive'] === '25');
check('parte los AllowedIPs', count($peer['allowedips']['row']) === 2);
check('y queda atado al tunel', $peer['tun'] === 'tun9000');

echo "\n=== lo que no se importa, y esta bien ===\n";

check('el DNS no va al tunel: es del resolver del cliente',
      !isset($built['tunnel']['dns']));

echo "\n=== endpoints raros ===\n";

check('ipv6 entre corchetes',
      awg_import_split_endpoint('[2001:db8::1]:51820') === array('2001:db8::1', '51820'),
      json_encode(awg_import_split_endpoint('[2001:db8::1]:51820')));
check('ipv6 sin puerto queda entero',
      awg_import_split_endpoint('2001:db8::1') === array('2001:db8::1', ''),
      json_encode(awg_import_split_endpoint('2001:db8::1')));
check('un host sin puerto', awg_import_split_endpoint('vpn.ejemplo.com') === array('vpn.ejemplo.com', ''));
check('un puerto que no es numero no se toma',
      awg_import_split_endpoint('vpn.ejemplo.com:puerto') === array('vpn.ejemplo.com', ''));
check('vacio da vacio', awg_import_split_endpoint('') === array('', ''));

echo "\n=== lo que se niega a construir ===\n";

check('sin PrivateKey', awg_import_build(array('interface' => array(), 'peers' => array(array('publickey' => $clave_b))), '', $e5) === false);
check('con una PrivateKey que no es clave',
      awg_import_build(array('interface' => array('privatekey' => 'hola'), 'peers' => array(array('publickey' => $clave_b))), '', $e6) === false);
check('sin ningun peer',
      awg_import_build(array('interface' => array('privatekey' => $clave_a), 'peers' => array()), '', $e7) === false);
check('con un peer sin PublicKey',
      awg_import_build(array('interface' => array('privatekey' => $clave_a), 'peers' => array(array())), '', $e8) === false);
check('y el motivo se explica', strlen((string) $e7) > 20, (string) $e7);

/*
 * El puerto que se le asigna a un tunel importado. Un .conf de cliente casi
 * nunca trae un ListenPort util, asi que lo elige el firewall -- y elegir uno
 * que ya esta tomado da un tunel que se guarda bien y no levanta nunca, con el
 * error recien al aplicar.
 *
 * Las lineas son salida real de sockstat(1) en un pfSense con el WireGuard
 * nativo corriendo, que es el caso que rompia: 51820 tomado por otro paquete.
 */
echo "\n=== los puertos que ya estan tomados ===\n";

$sockstat = array(
	'USER    COMMAND      PID FD PROTO   LOCAL ADDRESS         FOREIGN ADDRESS      ',
	'root    sshd-sessi 94784  9 stream  (not connected)       ??                   ',
	'root    kea-dhcp4  70123 11 stream  /var/run/kea/kea4-ctr ??                   ',
	'root    kea-dhcp4  70123 15 udp4    192.168.10.1:67       *:*                  ',
	'unbound unbound    67848  3 udp6    *:53                  *:*                  ',
	'root    wireguard  12345  8 udp4    *:51820               *:*                  ',
	'root    amneziawg- 45867 10 udp4    *:51822               *:*                  ',
	'root    openvpn    54321  6 udp4    *:1194                *:*                  ');

$taken = awg_ports_in_use($sockstat);

check('encuentra el puerto del WireGuard nativo, que es el caso que rompia',
      isset($taken[51820]), implode(',', array_keys($taken)));
check('y el de un tunel de este paquete', isset($taken[51822]));
check('y los del resto del sistema', isset($taken[67]) && isset($taken[53]) && isset($taken[1194]));
check('no inventa puertos con las lineas de sockets unix',
      count($taken) === 5, implode(',', array_keys($taken)));
check('una salida vacia no rompe', awg_ports_in_use(array()) === array());
check('una tabla con otra forma tampoco',
      awg_ports_in_use(array('cualquier cosa', '')) === array());

printf("\n%d pasaron, %d fallaron\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
