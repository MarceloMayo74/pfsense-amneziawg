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
if (!function_exists('config_get_path')) {
	function config_get_path($path, $default = null) {
		return ($path === 'system/hostname') ? 'pfSense.home.arpa' : $default;
	}
}

// Los campos y sus specs salen del globals de verdad.
$globals = file_get_contents("{$src}/awg_globals.inc");
preg_match("/'obfuscation_fields'.*?'i5'.*?\)\),/s", $globals, $fields);

if (empty($fields)) {
	fwrite(STDERR, "No se pudieron leer obfuscation_fields de awg_globals.inc\n");
	exit(2);
}

eval('$awgg = array(' . rtrim($fields[0], ',') . ');');

eval(extract_function("{$src}/awg_api.inc", 'awg_obfuscation_pairs'));
eval(extract_function("{$src}/awg_client.inc", 'awg_client_build_conf'));
eval(extract_function("{$src}/awg_client.inc", 'awg_client_conf_filename'));

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

$pairs_1x = awg_obfuscation_pairs($tunnel, false);
$pairs_2x = awg_obfuscation_pairs($tunnel, true);

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
	awg_obfuscation_pairs(array('name' => 'tun9001'), true) === array());

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

printf("\n%d pasaron, %d fallaron\n\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
