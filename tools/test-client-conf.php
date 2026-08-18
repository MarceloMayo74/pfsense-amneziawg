<?php
/*
 * test-client-conf.php - Tests de la generacion del .conf del cliente.
 *
 *   .tools\php\php.exe tools\test-client-conf.php      (el php de wgeasy)
 *
 * Mismo mecanismo que test-obfuscation.php: se extraen del arbol src/ las
 * funciones bajo prueba y se evaluan, para testear el codigo que se publica y
 * no una copia al lado.
 *
 * La propiedad que importa y que da nombre a la mitad de estos tests: la
 * ofuscacion del cliente tiene que salir de la MISMA funcion que la del
 * servidor. Si divergen no falla nada visible -- se genera el archivo, se
 * descarga, se importa -- y el sintoma aparece recien en la maquina del
 * usuario, como un tunel que no levanta y sin un mensaje que apunte a la causa.
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

// Lo que fuera de pfSense no existe.
if (!function_exists('gettext')) {
	function gettext($s) { return $s; }
}
if (!function_exists('is_numericint')) {
	function is_numericint($arg) {
		return (((is_int($arg) && $arg >= 0) ||
		         (is_string($arg) && strlen($arg) > 0 && ctype_digit($arg))) ? true : false);
	}
}
$test_config = array();

if (!function_exists('config_get_path')) {
	function config_get_path($path, $default = null) {
		global $test_config;

		if (array_key_exists($path, $test_config)) {
			return $test_config[$path];
		}

		return ($path === 'system/hostname') ? 'pfSense.home.arpa' : $default;
	}
}
if (!function_exists('is_hostname')) {
	function is_hostname($v) {
		$v = (string) $v;

		return (strlen($v) > 0) && (preg_match('/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/i', $v) === 1);
	}
}

// Los campos y sus specs salen del globals de verdad.
$globals = file_get_contents("{$src}/awg_globals.inc");
preg_match("/'obfuscation_fields'.*?'disablecookies'.*?\)\),/s", $globals, $fields);

if (empty($fields)) {
	fwrite(STDERR, "No se pudieron leer obfuscation_fields de awg_globals.inc\n");
	exit(2);
}

preg_match("/'max_address_probe'\s*=>\s*(\d+)/", $globals, $probe);

if (empty($probe)) {
	fwrite(STDERR, "No se pudo leer max_address_probe de awg_globals.inc\n");
	exit(2);
}

// El nombre del sub-elemento donde vive el cliente, tambien del globals de verdad
preg_match("/'client_store'\s*=>\s*'([^']+)'/", $globals, $store_key);

if (empty($store_key)) {
	fwrite(STDERR, "No se pudo leer client_store de awg_globals.inc\n");
	exit(2);
}

eval('$awgg = array(' . rtrim($fields[0], ',')
	. ", 'max_address_probe' => {$probe[1]}, 'client_store' => '{$store_key[1]}');");

/*
 * Stubs de las funciones de red de pfSense. Son aritmetica de enteros sobre
 * IPv4 y se pueden escribir sin margen de interpretacion; lo que se prueba con
 * ellas no es la aritmetica sino el recorrido: saltear la de red y la de
 * broadcast, saltear las ocupadas, y no colgarse con una subred enorme.
 */
if (!function_exists('is_ipaddrv4')) {
	function is_ipaddrv4($v) {
		return (filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false);
	}
	function is_ipaddrv6($v) {
		return (filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false);
	}
	function is_ipaddr($v) { return is_ipaddrv4($v) || is_ipaddrv6($v); }
	function is_subnet($v) {
		$p = explode('/', (string) $v);

		if ((count($p) !== 2) || !ctype_digit($p[1])) {
			return false;
		}

		return is_ipaddrv4($p[0]) ? ($p[1] <= 32) : (is_ipaddrv6($p[0]) && ($p[1] <= 128));
	}
	function gen_subnet($ip, $bits) {
		return long2ip(ip2long($ip) & (($bits == 0) ? 0 : (-1 << (32 - $bits))));
	}
	function gen_subnet_max($ip, $bits) {
		return long2ip(ip2long(gen_subnet($ip, $bits)) | (~(($bits == 0) ? 0 : (-1 << (32 - $bits))) & 0xFFFFFFFF));
	}
	function ip_after($ip) { return long2ip(ip2long($ip) + 1); }
	function ip_less_than($a, $b) { return (ip2long($a) < ip2long($b)); }
}


// El piso de la escalera. Se fija a mano: este test no arma awg_versions.
function awg_min_version() { return 2; }
eval(extract_function("{$src}/awg_api.inc", 'awg_tunnel_version'));
eval(extract_function("{$src}/awg_api.inc", 'awg_obfuscation_pairs'));
eval(extract_function("{$src}/awg_client.inc", 'awg_client_obfuscation_pairs'));
eval(extract_function("{$src}/awg_client.inc", 'awg_client_build_conf'));
eval(extract_function("{$src}/awg_client.inc", 'awg_client_conf_filename'));
eval(extract_function("{$src}/awg_client.inc", 'awg_client_pick_address'));
eval(extract_function("{$src}/awg_client.inc", 'awg_client_parse_addresses'));
eval(extract_function("{$src}/awg_client.inc", 'awg_client_addresses_to_post'));
eval(extract_function("{$src}/awg_client.inc", 'awg_client_addresses_to_line'));
eval(extract_function("{$src}/awg_client.inc", 'awg_client_format_endpoint'));
eval(extract_function("{$src}/awg_client.inc", 'awg_client_store'));
eval(extract_function("{$src}/awg_client.inc", 'awg_client_dialin_endpoint'));
eval(extract_function("{$src}/awg_client.inc", 'awg_client_normalize_dyndns_host'));
eval(extract_function("{$src}/awg_client.inc", 'awg_client_hostnames_from_updateurl'));
eval(extract_function("{$src}/awg_client.inc", 'awg_client_config_entries'));

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

/*
 * Un tunel de referencia. Headers como rango en H1 a proposito: son texto, y
 * modelarlos como enteros los rompe en silencio (la trampa de la fase 3).
 */
$tunnel = array(
	'name'		=> 'tun9000',
	'jc'		=> '4',
	'jmin'		=> '40',
	'jmax'		=> '70',
	's1'		=> '30',
	's2'		=> '40',
	's3'		=> '15',
	's4'		=> '20',
	'h1'		=> '787134324-1593815189',
	'h2'		=> '1234567892',
	'h3'		=> '1234567893',
	'h4'		=> '1234567894',
	'i1'		=> '<b 0xf0f0f0f0>',
	'i2'		=> '',
	'i3'		=> '',
	'i4'		=> '',
	'i5'		=> '');

printf("\n-- awg_obfuscation_pairs() --\n\n");

// El segundo argumento es el techo del firewall: 1 = backend 1.x, 2 = 2.0.
$pairs_1x = awg_obfuscation_pairs($tunnel, 1);
$pairs_2x = awg_obfuscation_pairs($tunnel, 2);

check('capitaliza los nombres como los espera el parser',
	isset($pairs_1x['Jc'], $pairs_1x['Jmin'], $pairs_1x['Jmax'], $pairs_1x['S1'], $pairs_1x['H1']),
	implode(',', array_keys($pairs_1x)));

check('contra un backend 1.x no escribe S3/S4',
	!isset($pairs_1x['S3']) && !isset($pairs_1x['S4']),
	implode(',', array_keys($pairs_1x)));

check('contra un backend 1.x no escribe I1-I5',
	!isset($pairs_1x['I1']) && !isset($pairs_1x['I2']),
	implode(',', array_keys($pairs_1x)));

check('contra un backend 2.0 si escribe S3/S4/I1',
	isset($pairs_2x['S3'], $pairs_2x['S4'], $pairs_2x['I1']),
	implode(',', array_keys($pairs_2x)));

check('los campos vacios no se escriben',
	!isset($pairs_2x['I2']) && !isset($pairs_2x['I5']),
	implode(',', array_keys($pairs_2x)));

check('el header sobrevive como rango, no como entero',
	$pairs_1x['H1'] === '787134324-1593815189',
	$pairs_1x['H1'] ?? '(ausente)');

check('un tunel sin ofuscacion da un array vacio',
	awg_obfuscation_pairs(array('name' => 'tun9001'), 2) === array());

/*
 * El selector del tunel, que es lo que hace que bajar de nivel no deje un S3
 * viejo colandose en los .conf mientras la pantalla dice 1.x.
 */
$tunnel_1x = array_merge($tunnel, array('awgversion' => '1'));
$tunnel_2x = array_merge($tunnel, array('awgversion' => '2'));

/*
 * 1.x salio de la escalera, asi que un tunel guardado ahi se sube al piso y
 * pasa a escribir lo de 2.0. En un tunel 1.x de verdad esos campos estan
 * vacios, asi que el .conf no cambia; aca el tunel de referencia los tiene
 * cargados y por eso se ven.
 */
check('un tunel marcado 1.x se sube al piso de la escalera',
	isset(awg_obfuscation_pairs($tunnel_1x, 2)['S3'], awg_obfuscation_pairs($tunnel_1x, 2)['I1']),
	implode(',', array_keys(awg_obfuscation_pairs($tunnel_1x, 2))));

check('y sigue escribiendo lo suyo',
	isset(awg_obfuscation_pairs($tunnel_1x, 2)['Jc'], awg_obfuscation_pairs($tunnel_1x, 2)['S1']));

/*
 * Pero el TECHO le gana al piso: contra un backend que solo entiende 1.x no se
 * escriben los campos de 2.0, o el tunel no levanta.
 */
check('contra un backend 1.x el techo gana y no se escriben los de 2.0',
	!isset(awg_obfuscation_pairs($tunnel_1x, 1)['S3']) &&
	!isset(awg_obfuscation_pairs($tunnel_1x, 1)['I1']),
	implode(',', array_keys(awg_obfuscation_pairs($tunnel_1x, 1))));

check('un tunel marcado 2.0 escribe todo si el firewall llega',
	isset(awg_obfuscation_pairs($tunnel_2x, 2)['S3'], awg_obfuscation_pairs($tunnel_2x, 2)['I1']));

check('un tunel marcado 2.0 contra un backend 1.x no escribe los de 2.0',
	!isset(awg_obfuscation_pairs($tunnel_2x, 1)['S3']),
	implode(',', array_keys(awg_obfuscation_pairs($tunnel_2x, 1))));

check('un tunel sin el campo se comporta como antes del selector: escribe hasta el techo',
	isset(awg_obfuscation_pairs($tunnel, 2)['S3']) &&
	!isset(awg_obfuscation_pairs($tunnel, 1)['S3']));

check('un nivel guardado por encima del techo se baja al techo, no rompe',
	awg_tunnel_version(array('awgversion' => '3'), 2) === 2);

check('un nivel guardado que no existe cae al techo',
	awg_tunnel_version(array('awgversion' => 'pepe'), 2) === 2);

/*
 * Los campos de 3.x. Lo que se prueba aca es lo unico que 3.0 rompio del diseño
 * viejo: sus nombres NO son el campo capitalizado, asi que si el spec pierde su
 * 'key' salen como Contentpaddingaddition y awg(8) aborta el .conf entero.
 */
$tunnel_3x = array_merge($tunnel, array(
	'awgversion'		=> '4',
	'headerprotectionkey'	=> 'QOfjW+aQKrJvIbYisIoAO2FYUEQlZ5RGxaBhbTKlaEE=',
	'contentpaddingaddition'=> '12-40',
	'rekeyaftertime'	=> '100',
	'randomtrailers'	=> 'on',
	'disablecookies'	=> 'off'));

$pairs_4x = awg_obfuscation_pairs($tunnel_3x, 4);

check('los nombres de 3.x salen como los espera el parser, no capitalizados',
	isset($pairs_4x['HeaderProtectionKey'], $pairs_4x['ContentPaddingAddition'],
	      $pairs_4x['RekeyAfterTime'], $pairs_4x['RandomTrailers']),
	implode(',', array_keys($pairs_4x)));

// Cada uno por separado: isset() con varias claves solo pide que falte UNA.
check('y ninguno salio con el nombre viejo',
	!isset($pairs_4x['Headerprotectionkey']) &&
	!isset($pairs_4x['Contentpaddingaddition']) &&
	!isset($pairs_4x['Randomtrailers']));

check('un booleano en off se escribe: off no es lo mismo que vacio',
	($pairs_4x['DisableCookies'] ?? null) === 'off');

check('un tunel en 3.0 no escribe los booleanos, que son de 3.1',
	!isset(awg_obfuscation_pairs(array_merge($tunnel_3x, array('awgversion' => '3')), 4)['RandomTrailers']),
	implode(',', array_keys(awg_obfuscation_pairs(array_merge($tunnel_3x, array('awgversion' => '3')), 4))));

check('pero si escribe lo demas de 3.0',
	isset(awg_obfuscation_pairs(array_merge($tunnel_3x, array('awgversion' => '3')), 4)['HeaderProtectionKey']));

$pairs_2x_desde_3x = awg_obfuscation_pairs($tunnel_3x, 2);

check('contra un backend 2.0 no se escribe nada de 3.x',
	!isset($pairs_2x_desde_3x['HeaderProtectionKey']) &&
	!isset($pairs_2x_desde_3x['RandomTrailers']) &&
	!isset($pairs_2x_desde_3x['ContentPaddingAddition']),
	implode(',', array_keys($pairs_2x_desde_3x)));

check('y lo de 2.0 se sigue escribiendo igual',
	isset(awg_obfuscation_pairs($tunnel_3x, 2)['S3']));

printf("\n-- awg_client_build_conf() --\n\n");

$base = array(
	'descr'			=> 'telefono de marcelo',
	'privatekey'		=> 'CLIENTPRIVATEKEYxxxxxxxxxxxxxxxxxxxxxxxxxxx=',
	'address'		=> '10.10.10.2/32',
	'dns'			=> '10.10.10.1',
	'mtu'			=> '1420',
	'publickey'		=> 'SERVERPUBLICKEYxxxxxxxxxxxxxxxxxxxxxxxxxxxx=',
	'presharedkey'		=> 'PRESHAREDKEYxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx=',
	'allowedips'		=> '0.0.0.0/0, ::/0',
	'endpoint'		=> 'vpn.example.com:51820',
	'persistentkeepalive'	=> 25,
	'obfuscation'		=> $pairs_1x);

$conf = awg_client_build_conf($base, $err);

check('genera el archivo con el tunel completo', $conf !== false, (string) $err);
check('tiene las dos secciones',
	strpos($conf, '[Interface]') !== false && strpos($conf, '[Peer]') !== false);

foreach (array('privatekey', 'address', 'publickey', 'allowedips') as $required) {
	$incompleto = $base;
	unset($incompleto[$required]);

	check("se niega a generar sin {$required}",
		awg_client_build_conf($incompleto, $e) === false);
}

/*
 * El test que justifica el archivo: cada par que escribe el servidor aparece
 * igual en el .conf del cliente, y del lado de [Interface].
 */
$iface = substr($conf, strpos($conf, '[Interface]'), strpos($conf, '[Peer]') - strpos($conf, '[Interface]'));
$peer  = substr($conf, strpos($conf, '[Peer]'));

$todos = true;
$donde = true;

foreach ($pairs_1x as $key => $value) {
	if (strpos($iface, "{$key} = {$value}") === false) {
		$todos = false;
	}
	if (strpos($peer, "{$key} = ") !== false) {
		$donde = false;
	}
}

check('la ofuscacion del cliente es la misma que la del servidor', $todos);
check('la ofuscacion va en [Interface] y no en [Peer]', $donde);

check('la clave privada del cliente va en [Interface]',
	strpos($iface, "PrivateKey = {$base['privatekey']}") !== false);
check('la clave publica del tunel va en [Peer]',
	strpos($peer, "PublicKey = {$base['publickey']}") !== false);
check('la clave privada del cliente no se filtra al [Peer]',
	strpos($peer, $base['privatekey']) === false);

// Un backend 1.x no debe ver jamas un S3 en el archivo del cliente.
check('contra un backend 1.x el cliente no lleva S3',
	strpos($conf, 'S3 = ') === false);

$minimo = awg_client_build_conf(array(
	'privatekey'	=> 'CLIENTPRIVATEKEYxxxxxxxxxxxxxxxxxxxxxxxxxxx=',
	'address'	=> '10.10.10.3/32',
	'publickey'	=> 'SERVERPUBLICKEYxxxxxxxxxxxxxxxxxxxxxxxxxxxx=',
	'allowedips'	=> '10.10.10.0/24',
	'obfuscation'	=> $pairs_1x));

check('sin DNS no escribe la linea DNS', strpos($minimo, 'DNS') === false);
check('sin MTU no escribe la linea MTU', strpos($minimo, 'MTU') === false);
check('sin PresharedKey no escribe la linea', strpos($minimo, 'PresharedKey') === false);
check('sin Endpoint no escribe la linea', strpos($minimo, 'Endpoint') === false);
check('sin keepalive no escribe la linea', strpos($minimo, 'PersistentKeepalive') === false);

$cero = $base;
$cero['persistentkeepalive'] = 0;
check('un keepalive en 0 se omite',
	strpos(awg_client_build_conf($cero), 'PersistentKeepalive') === false);
check('un keepalive en 25 se escribe',
	strpos($conf, 'PersistentKeepalive = 25') !== false);

check('avisa que el archivo tiene una clave privada',
	stripos($conf, 'clave privada') !== false);

printf("\n-- awg_client_conf_filename() --\n\n");

check('reemplaza lo que el cliente no acepta',
	awg_client_conf_filename('telefono de marcelo') === 'telefono-de-mar.conf',
	awg_client_conf_filename('telefono de marcelo'));

check('corta en 15 caracteres',
	strlen(awg_client_conf_filename('un-nombre-larguisimo-de-cliente')) === strlen('123456789012345.conf'));

check('los acentos no sobreviven crudos',
	preg_match('/^[a-zA-Z0-9_=+.-]+\.conf$/', awg_client_conf_filename('cámara jardín')) === 1,
	awg_client_conf_filename('cámara jardín'));

check('una descripcion vacia cae al fallback',
	awg_client_conf_filename('') === 'awgclient.conf',
	awg_client_conf_filename(''));

check('una descripcion toda invalida cae al fallback',
	awg_client_conf_filename('///') === 'awgclient.conf',
	awg_client_conf_filename('///'));

printf("\n-- awg_client_pick_address() --\n\n");

$v24 = array(array('address' => '10.10.10.1', 'mask' => '24'));

check('en una /24 vacia da la primera utilizable',
	awg_client_pick_address($v24, array()) === '10.10.10.1/32',
	(string) awg_client_pick_address($v24, array()));

check('saltea las ocupadas',
	awg_client_pick_address($v24, array('10.10.10.1', '10.10.10.2', '10.10.10.3')) === '10.10.10.4/32',
	(string) awg_client_pick_address($v24, array('10.10.10.1', '10.10.10.2', '10.10.10.3')));

check('devuelve siempre una /32',
	substr((string) awg_client_pick_address($v24, array('10.10.10.1')), -3) === '/32');

check('no reparte la direccion de red',
	strpos((string) awg_client_pick_address($v24, array()), '10.10.10.0/') === false);

$v30 = array(array('address' => '192.168.5.1', 'mask' => '30'));

check('en una /30 da la primera de las dos utilizables',
	awg_client_pick_address($v30, array()) === '192.168.5.1/32',
	(string) awg_client_pick_address($v30, array()));

check('no reparte la de broadcast de una /30',
	awg_client_pick_address($v30, array('192.168.5.1', '192.168.5.2')) === null,
	(string) awg_client_pick_address($v30, array('192.168.5.1', '192.168.5.2')));

check('una /31 no tiene lugar para un cliente',
	awg_client_pick_address(array(array('address' => '10.0.0.0', 'mask' => '31')), array()) === null);

check('una /32 tampoco',
	awg_client_pick_address(array(array('address' => '10.0.0.5', 'mask' => '32')), array()) === null);

check('una fila IPv6 se saltea sin romper',
	awg_client_pick_address(array(array('address' => 'fd00::1', 'mask' => '64')), array()) === null);

// Con la primera subred agotada tiene que pasar a la siguiente.
$dos = array(array('address' => '192.168.5.1', 'mask' => '30'),
             array('address' => '10.10.10.1',  'mask' => '24'));

check('con la primera subred agotada sigue con la segunda',
	awg_client_pick_address($dos, array('192.168.5.1', '192.168.5.2')) === '10.10.10.1/32',
	(string) awg_client_pick_address($dos, array('192.168.5.1', '192.168.5.2')));

check('sin ninguna fila devuelve null',
	awg_client_pick_address(array(), array()) === null);

/*
 * Una subred grande con el principio entero ocupado tiene que cortar por el
 * tope de sondeos y devolver null, no recorrer 65 000 direcciones.
 */
$muchas = array();
for ($i = 0; $i < $awgg['max_address_probe'] + 10; $i++) {
	$muchas[] = long2ip(ip2long('10.20.0.0') + 1 + $i);
}

$t0 = microtime(true);
$grande = awg_client_pick_address(array(array('address' => '10.20.0.1', 'mask' => '16')), $muchas);
$el = microtime(true) - $t0;

check('una subred grande corta por el tope de sondeos', $grande === null, (string) $grande);
check('y corta rapido', $el < 5.0, sprintf('%.1fs', $el));

printf("\n-- direcciones: parseo y vuelta --\n\n");

$e = array();
$filas = awg_client_parse_addresses('10.0.0.2/32, 192.168.1.0/24', $e);

check('parsea una lista separada por comas',
	count($filas) === 2 && empty($e), count($filas) . ' ' . implode(';', $e));

check('y la devuelve igual', awg_client_addresses_to_line($filas) === '10.0.0.2/32, 192.168.1.0/24',
	awg_client_addresses_to_line($filas));

$e = array();
$sueltas = awg_client_parse_addresses('10.0.0.2', $e);

check('una direccion sola es un host /32',
	($sueltas[0]['mask'] ?? '') === '32', $sueltas[0]['mask'] ?? '(nada)');

$e = array();
$v6 = awg_client_parse_addresses('fd00::2', $e);

check('y una IPv6 sola es /128',
	($v6[0]['mask'] ?? '') === '128', $v6[0]['mask'] ?? '(nada)');

$e = array();
check('aguanta espacios de mas',
	count(awg_client_parse_addresses('  10.0.0.2/32 ,  10.0.0.3/32  ', $e)) === 2 && empty($e));

$e = array();
check('saltea las entradas vacias',
	count(awg_client_parse_addresses('10.0.0.2/32, , 10.0.0.3/32,', $e)) === 2 && empty($e));

$e = array();
$mala = awg_client_parse_addresses('10.0.0.2/32, no-es-una-ip', $e);

check('avisa de lo que no es una direccion', count($e) === 1, implode(';', $e));
check('y no la agrega a la lista', count($mala) === 1);

$e = array();
check('una mascara imposible tambien se rechaza',
	count(awg_client_parse_addresses('10.0.0.2/99', $e)) === 0 && count($e) === 1);

$e = array();
check('una lista vacia no es un error',
	awg_client_parse_addresses('', $e) === array() && empty($e));

$post = awg_client_addresses_to_post($filas, 'cliente');

check('arma los campos repetibles que espera el guardado nativo',
	($post['address0'] === '10.0.0.2') && ($post['address_subnet0'] === '32')
	&& ($post['address1'] === '192.168.1.0') && ($post['address_subnet1'] === '24')
	&& ($post['address_descr0'] === 'cliente'),
	implode(',', array_keys($post)));

printf("\n-- endpoint --\n\n");

check('junta host y puerto',
	awg_client_format_endpoint('vpn.example.com', '51820') === 'vpn.example.com:51820');

check('una IPv6 va entre corchetes, o el cliente no distingue el puerto',
	awg_client_format_endpoint('fd00::1', '51820') === '[fd00::1]:51820',
	awg_client_format_endpoint('fd00::1', '51820'));

check('y no se le agregan corchetes dos veces',
	awg_client_format_endpoint('[fd00::1]', '51820') === '[fd00::1]:51820',
	awg_client_format_endpoint('[fd00::1]', '51820'));

check('sin host no hay endpoint', awg_client_format_endpoint('', '51820') === '');
check('sin puerto queda el host solo',
	awg_client_format_endpoint('vpn.example.com', '') === 'vpn.example.com');

/*
 * Lo que se muestra en las listas es a donde DISCA el cliente, que es lo que
 * se cargo al crear el peer. De donde llego el cliente es otra cosa: la aprende
 * el servidor del handshake y cambia en cada reconexion. Mostrar la segunda
 * bajo el titulo de la primera hace parecer que el puerto configurado se
 * perdio, que es exactamente lo que paso el 14-08-2026.
 */
$peer_cli = array('descr' => 'telefono',
	$awgg['client_store'] => array('endpoint' => 'vpn.example.com', 'port' => '51822'));

check('la lista muestra a donde disca el cliente',
	awg_client_dialin_endpoint($peer_cli) === 'vpn.example.com:51822',
	var_export(awg_client_dialin_endpoint($peer_cli), true));

check('con el puerto que se configuro, no el que use el cliente',
	strpos(awg_client_dialin_endpoint($peer_cli), ':51822') !== false);

$peer_cli[$awgg['client_store']]['endpoint'] = 'fd00::1';

check('y una IPv6 sigue yendo entre corchetes',
	awg_client_dialin_endpoint($peer_cli) === '[fd00::1]:51822');

/*
 * La resolucion a IP es de la pagina de estado. Con una IP ya puesta no hay
 * nada que resolver -- y un hostname no se prueba aca porque seria una
 * consulta DNS real desde el harness.
 */
$peer_cli[$awgg['client_store']]['endpoint'] = '203.0.113.7';

check('resolver una IP la deja como esta',
	awg_client_dialin_endpoint($peer_cli, true) === '203.0.113.7:51822');

check('y sin pedir resolucion tampoco se toca',
	awg_client_dialin_endpoint($peer_cli) === '203.0.113.7:51822');

check('un peer sin cliente no tiene direccion de discado',
	awg_client_dialin_endpoint(array('descr' => 'site-to-site')) === null);

check('ni uno cuyo cliente no guardo endpoint',
	awg_client_dialin_endpoint(array($awgg['client_store'] => array('port' => '51822'))) === null);

printf("\n-- DNS dinamico --\n\n");

check("'@' quiere decir el dominio pelado",
	awg_client_normalize_dyndns_host('@') === '');
check("'*' tambien",
	awg_client_normalize_dyndns_host('*') === '');
check("'*.example.com' cubre cualquier nombre abajo",
	awg_client_normalize_dyndns_host('*.example.com') === 'example.com',
	awg_client_normalize_dyndns_host('*.example.com'));
check('el punto final es notacion DNS y se saca',
	awg_client_normalize_dyndns_host('vpn.example.com.') === 'vpn.example.com',
	awg_client_normalize_dyndns_host('vpn.example.com.'));
check('un hostname normal queda como esta',
	awg_client_normalize_dyndns_host(' vpn.example.com ') === 'vpn.example.com');

check('saca el hostname de una URL de actualizacion',
	awg_client_hostnames_from_updateurl('https://svc.example/update?hostname=vpn.example.com&token=SECRETO')
		=== array('vpn.example.com'));

check('acepta varios nombres separados por coma',
	awg_client_hostnames_from_updateurl('https://svc.example/u?domains=a.example.com,b.example.com')
		=== array('a.example.com', 'b.example.com'));

check('a DuckDNS le completa el dominio que omite',
	awg_client_hostnames_from_updateurl('https://www.duckdns.org/update?domains=casa&token=SECRETO')
		=== array('casa.duckdns.org'),
	implode(',', awg_client_hostnames_from_updateurl('https://www.duckdns.org/update?domains=casa&token=SECRETO')));

check('una etiqueta sola sin DuckDNS no alcanza',
	awg_client_hostnames_from_updateurl('https://svc.example/u?hostname=casa') === array());

check('una URL sin query no da nada',
	awg_client_hostnames_from_updateurl('https://svc.example/update') === array());

check('y una vacia tampoco',
	awg_client_hostnames_from_updateurl('') === array());

/*
 * La forma torcida que aparece en configuraciones actualizadas a mano: una
 * entrada asociativa suelta donde deberia haber una lista.
 */
$test_config['dyndnses/dyndns'] = array('type' => 'cloudflare', 'host' => 'vpn');

check('una entrada suelta se normaliza a lista de una',
	count(awg_client_config_entries('dyndnses/dyndns', 'type')) === 1);

$test_config['dyndnses/dyndns'] = array(
	array('type' => 'cloudflare', 'host' => 'a'),
	array('type' => 'noip', 'host' => 'b'));

check('y una lista de dos queda en dos',
	count(awg_client_config_entries('dyndnses/dyndns', 'type')) === 2);

$test_config['dyndnses/dyndns'] = array();

check('sin entradas devuelve una lista vacia',
	awg_client_config_entries('dyndnses/dyndns', 'type') === array());

unset($test_config['dyndnses/dyndns']);

echo "\n=== el .conf del cliente: lo suyo y lo del tunel ===\n";

// El techo lo sondea el firewall; aca se fija en el maximo para no recortar nada
if (!function_exists('awg_version_ceiling')) {
	function awg_version_ceiling($use_cache = true) { return 4; }
}

/*
 * Lo 'sender' sale del store del peer y lo 'shared' del tunel. Un solo campo
 * compartido que no coincida es un handshake que no cierra, y sin error.
 */
$tun_obf = array(
	'awgversion'	=> '3',
	'jc'		=> '4',
	'jmin'		=> '40',
	'jmax'		=> '70',
	's1'		=> '30',
	's2'		=> '41',
	's3'		=> '20',
	's4'		=> '12',
	'h1'		=> '100-200',
	'h2'		=> '300-400',
	'h3'		=> '500-600',
	'h4'		=> '700-800',
	'i1'		=> '<b 0xaaaa>',
	'i2'		=> '<b 0xbbbb>',
	'contentpaddingaddition' => '8-40',
	'headerprotectionkey'	=> base64_encode(str_repeat("\x07", 32)));

$propia = array('obfuscation' => array(
	'jc'	=> '9',
	'jmin'	=> '55',
	'jmax'	=> '120',
	'i1'	=> '<b 0xcccc>',
	'i2'	=> '<b 0xdddd>'));

$pares = awg_client_obfuscation_pairs($tun_obf, $propia);

check('el tren de basura es el del cliente',
      ($pares['Jc'] === '9') && ($pares['Jmin'] === '55') && ($pares['Jmax'] === '120'),
      json_encode(array($pares['Jc'], $pares['Jmin'], $pares['Jmax'])));
check('la cadena I tambien', ($pares['I1'] === '<b 0xcccc>') && ($pares['I2'] === '<b 0xdddd>'),
      json_encode(array($pares['I1'], $pares['I2'])));

check('los rellenos siguen siendo los del tunel',
      ($pares['S1'] === '30') && ($pares['S2'] === '41') && ($pares['S3'] === '20') && ($pares['S4'] === '12'));
check('los headers tambien',
      ($pares['H1'] === '100-200') && ($pares['H4'] === '700-800'));
check('y la clave de proteccion de headers tambien',
      $pares['HeaderProtectionKey'] === $tun_obf['headerprotectionkey']);

/*
 * ContentPaddingAddition es 'sender' pero no se sortea por cliente. Si el
 * merge reemplazara el bloque entero en vez de campo por campo, uno puesto a
 * mano en el tunel se perderia.
 */
check('un ContentPaddingAddition del tunel no se pierde',
      $pares['ContentPaddingAddition'] === '8-40',
      var_export($pares['ContentPaddingAddition'] ?? null, true));

// Un peer de antes de esto, sin nada guardado, cae en la del tunel
$viejo = awg_client_obfuscation_pairs($tun_obf, array());

check('un peer sin ofuscacion propia usa la del tunel entera',
      $viejo === awg_obfuscation_pairs($tun_obf), json_encode($viejo));
check('o sea el Jc del tunel', $viejo['Jc'] === '4');

printf("\n%d pasaron, %d fallaron\n\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
