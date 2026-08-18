<?php
/*
 * test-routes.php - las rutas de host de los endpoints, sin firewall.
 *
 *   php tools/test-routes.php
 *
 * Elegir por que WAN sale un tunel es, en FreeBSD, una ruta. No hay
 * SO_BINDTODEVICE, y forzar solo la direccion de origen deja el paquete
 * saliendo por donde diga la tabla -- con el origen de una WAN y la salida por
 * otra, que es lo que un ISP descarta.
 *
 * Lo que se prueba aca son las DECISIONES de awg_routes_reconcile(): que agrega,
 * que cambia, que borra y que deja en paz. Los tres comandos de route(8) se
 * reemplazan por espias, porque lo que puede salir mal no es el comando sino
 * cuando se lo llama.
 *
 * Las dos que mas importan:
 *
 *   - No pisar una ruta ajena. Un paquete de VPN que le gana a mano al operador
 *     termina decidiendo el ruteo del firewall entero.
 *   - No perder el registro. Si una ruta se agrega y no se anota, nadie la saca
 *     despues: queda desviando trafico a un destino para siempre.
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

function is_ipaddr($v) { return (bool) filter_var($v, FILTER_VALIDATE_IP); }
function is_ipaddrv6($v) { return (bool) filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6); }
function safe_mkdir($d) { return true; }

// El estado vive en un archivo de verdad, en el scratch de esta corrida
$state = tempnam(sys_get_temp_dir(), 'awgroutes');

$awgg = array('route_state' => $state, 'run_path' => dirname($state));

/*
 * Los espias. Reemplazan a las tres que hablan con route(8): se declaran ANTES
 * del eval, asi que las del archivo no llegan a definirse y estas quedan.
 */
$GLOBALS['hechas'] = array();
$GLOBALS['ajenas'] = array();
$GLOBALS['fallan'] = array();
$GLOBALS['logs']   = array();

function log_error($m) { $GLOBALS['logs'][] = $m; }

function awg_route_exists($dst) {
	return in_array($dst, $GLOBALS['ajenas'], true);
}

function awg_route_set($dst, $gw, $replace = false) {
	if (in_array($dst, $GLOBALS['fallan'], true)) {
		return false;
	}

	$GLOBALS['hechas'][] = ($replace ? 'change' : 'add') . " {$dst} {$gw}";

	return true;
}

function awg_route_delete($dst) {
	$GLOBALS['hechas'][] = "delete {$dst}";

	return true;
}

eval(extract_function("{$src}/awg.inc", 'awg_routes_reconcile'));
eval(extract_function("{$src}/awg.inc", 'awg_routes_state_read'));
eval(extract_function("{$src}/awg.inc", 'awg_routes_state_write'));
eval(extract_function("{$src}/awg.inc", 'awg_routes_flush'));
eval(extract_function("{$src}/awg.inc", 'awg_resolve_host'));

$pass = $fail = 0;

function check($what, $cond, $detail = '') {
	global $pass, $fail;

	if ($cond) {
		$pass++;
		printf("  ok   %s\n", $what);
	} else {
		$fail++;
		printf("  FALLA %s%s\n", $what, ($detail !== '') ? "  [{$detail}]" : '');
	}
}

// Arranca de cero: sin estado, sin rutas ajenas, nada falla
function reset_todo($previo = array(), $ajenas = array(), $fallan = array()) {
	global $state;

	$GLOBALS['hechas'] = array();
	$GLOBALS['ajenas'] = $ajenas;
	$GLOBALS['fallan'] = $fallan;
	$GLOBALS['logs']   = array();

	if (empty($previo)) {
		@unlink($state);
	} else {
		file_put_contents($state, json_encode($previo));
	}
}

function estado() {
	global $state;

	$raw = @file_get_contents($state);

	return ($raw === false) ? array() : json_decode($raw, true);
}

echo "=== de cero ===\n";

reset_todo();
awg_routes_reconcile(array('203.0.113.45' => '200.51.241.1'));

check('agrega la ruta que falta',
      $GLOBALS['hechas'] === array('add 203.0.113.45 200.51.241.1'),
      implode(' ; ', $GLOBALS['hechas']));
check('y la anota', estado() === array('203.0.113.45' => '200.51.241.1'), json_encode(estado()));

echo "\n=== sin cambios no se toca nada ===\n";

reset_todo(array('203.0.113.45' => '200.51.241.1'));
awg_routes_reconcile(array('203.0.113.45' => '200.51.241.1'));

check('no ejecuta ningun route', empty($GLOBALS['hechas']), implode(' ; ', $GLOBALS['hechas']));
check('y el registro queda igual', estado() === array('203.0.113.45' => '200.51.241.1'));

echo "\n=== cuando cambia el gateway ===\n";

reset_todo(array('203.0.113.45' => '200.51.241.1'));
awg_routes_reconcile(array('203.0.113.45' => '192.168.0.1'));

check('cambia la ruta en vez de agregarla',
      $GLOBALS['hechas'] === array('change 203.0.113.45 192.168.0.1'),
      implode(' ; ', $GLOBALS['hechas']));
check('y anota el gateway nuevo', estado() === array('203.0.113.45' => '192.168.0.1'));

echo "\n=== cuando el peer deja de pedirla ===\n";

reset_todo(array('203.0.113.45' => '200.51.241.1'));
awg_routes_reconcile(array());

check('la borra', $GLOBALS['hechas'] === array('delete 203.0.113.45'), implode(' ; ', $GLOBALS['hechas']));
check('y se queda sin registro', estado() === array(), json_encode(estado()));

// El caso del endpoint que cambia de IP: se va la vieja y entra la nueva
reset_todo(array('203.0.113.45' => '200.51.241.1'));
awg_routes_reconcile(array('203.0.113.99' => '200.51.241.1'));

check('un endpoint que cambio de IP borra la vieja y agrega la nueva',
      $GLOBALS['hechas'] === array('delete 203.0.113.45', 'add 203.0.113.99 200.51.241.1'),
      implode(' ; ', $GLOBALS['hechas']));
check('y el registro queda solo con la nueva',
      estado() === array('203.0.113.99' => '200.51.241.1'), json_encode(estado()));

echo "\n=== una ruta ajena no se toca ===\n";

reset_todo(array(), array('203.0.113.45'));
awg_routes_reconcile(array('203.0.113.45' => '200.51.241.1'));

check('no la pisa', empty($GLOBALS['hechas']), implode(' ; ', $GLOBALS['hechas']));
check('no la anota como propia', estado() === array(), json_encode(estado()));
check('y lo dice en el log', !empty($GLOBALS['logs']), implode(' | ', $GLOBALS['logs']));

/*
 * Pero una que YA es nuestra si se cambia aunque route get la vea: la vio
 * porque la pusimos nosotros la vuelta pasada.
 */
reset_todo(array('203.0.113.45' => '200.51.241.1'), array('203.0.113.45'));
awg_routes_reconcile(array('203.0.113.45' => '192.168.0.1'));

check('una que ya era nuestra si se cambia',
      $GLOBALS['hechas'] === array('change 203.0.113.45 192.168.0.1'),
      implode(' ; ', $GLOBALS['hechas']));

echo "\n=== si route(8) falla no se anota ===\n";

/*
 * Anotar una ruta que no se pudo poner es la peor de las dos formas de
 * equivocarse: el ciclo siguiente la ve en el registro, cree que ya esta, y no
 * la intenta nunca mas.
 */
reset_todo(array(), array(), array('203.0.113.45'));
awg_routes_reconcile(array('203.0.113.45' => '200.51.241.1'));

check('no queda en el registro', estado() === array(), json_encode(estado()));

// Y al ciclo siguiente se vuelve a intentar
reset_todo(estado());
awg_routes_reconcile(array('203.0.113.45' => '200.51.241.1'));

check('asi que el ciclo siguiente lo reintenta',
      $GLOBALS['hechas'] === array('add 203.0.113.45 200.51.241.1'),
      implode(' ; ', $GLOBALS['hechas']));

echo "\n=== varias a la vez ===\n";

reset_todo(array('203.0.113.45' => '200.51.241.1', '198.51.100.7' => '192.168.0.1'));
awg_routes_reconcile(array(
	'203.0.113.45'	=> '200.51.241.1',	// igual
	'198.51.100.7'	=> '200.51.241.1',	// cambia
	'192.0.2.10'	=> '192.168.0.1'));	// nueva

check('deja la igual, cambia la que cambio y agrega la nueva',
      count($GLOBALS['hechas']) === 2
      && in_array('change 198.51.100.7 200.51.241.1', $GLOBALS['hechas'], true)
      && in_array('add 192.0.2.10 192.168.0.1', $GLOBALS['hechas'], true),
      implode(' ; ', $GLOBALS['hechas']));
check('y las anota a las tres', count(estado()) === 3, json_encode(estado()));

echo "\n=== el flush del apagado ===\n";

reset_todo(array('203.0.113.45' => '200.51.241.1', '198.51.100.7' => '192.168.0.1'));
awg_routes_flush();

check('borra todas', count($GLOBALS['hechas']) === 2, implode(' ; ', $GLOBALS['hechas']));
check('y vacia el registro', estado() === array(), json_encode(estado()));

echo "\n=== un registro ilegible se trata como vacio ===\n";

file_put_contents($state, 'esto no es json');
reset_todo(array());
file_put_contents($state, 'esto no es json');

awg_routes_reconcile(array('203.0.113.45' => '200.51.241.1'));

check('no rompe y agrega igual',
      $GLOBALS['hechas'] === array('add 203.0.113.45 200.51.241.1'),
      implode(' ; ', $GLOBALS['hechas']));

echo "\n=== resolver nombres ===\n";

check('una IP se devuelve tal cual', awg_resolve_host('203.0.113.45') === '203.0.113.45');
check('una IPv6 tambien', awg_resolve_host('2001:db8::1') === '2001:db8::1');
check('vacio da vacio', awg_resolve_host('') === '');
check('espacios solos dan vacio', awg_resolve_host('   ') === '');

/*
 * gethostbyname() devuelve el nombre INTACTO cuando falla, y sin el corte eso
 * termina en "route add <nombre>".
 */
check('un nombre que no resuelve da vacio, no el nombre',
      awg_resolve_host('no.existe.invalid.') === '',
      var_export(awg_resolve_host('no.existe.invalid.'), true));

@unlink($state);

printf("\n%d pasaron, %d fallaron\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
