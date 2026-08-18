<?php
/*
 * test-apply-list.php - que un apply fallido no se lleve lo que quedaba.
 *
 *   php tools/test-apply-list.php
 *
 * awg_apply_list_get() CONSUME la lista al leerla. Eso servia mientras leer y
 * aplicar eran la misma cosa. Desde que se puede leer sin aplicar --para poder
 * decir "el servicio esta parado" en vez de mentir con un exito mudo-- leer y
 * vaciar tienen que ser dos actos distintos: si el apply falla, lo que quedaba
 * pendiente tiene que seguir pendiente.
 *
 * El bug que motivo esto: con el servicio parado, las pantallas salteaban el
 * sync, ret_code quedaba en 0, se limpiaba el cartel de cambios pendientes y el
 * operador se quedaba creyendo que habia aplicado.
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

function unlink_if_exists($p) { if (file_exists($p)) { unlink($p); } }

foreach (array('awg_apply_list_get', 'awg_apply_list_add', 'awg_apply_list_clear') as $fn) {
	eval(extract_function("{$src}/awg.inc", $fn));
}

eval(extract_function("{$src}/awg_api.inc", 'awg_get_errors'));

define('AWG_ERROR_SVC_DISABLED',    1);
define('AWG_ERROR_SVC_CREATE',      16);
define('AWG_ERROR_SVC_NOT_RUNNING', 32);

$lista = sys_get_temp_dir() . '/awg-test-apply-' . getmypid();

$awgg = array(
	'applylist' => array('tunnels' => $lista),
	'error_flags' => array('service' => array(
		AWG_ERROR_SVC_DISABLED    => 'AmneziaWG service is disabled',
		AWG_ERROR_SVC_CREATE      => 'Unable to create AmneziaWG tunnel(s)',
		AWG_ERROR_SVC_NOT_RUNNING => 'Nothing was applied: the AmneziaWG service is not running. Start it and apply again.')));

$ok = 0; $bad = 0;

function check($what, $cond, $detail = '') {
	global $ok, $bad;

	if ($cond) { $ok++; printf("  ok    %s\n", $what); }
	else { $bad++; printf("  FALLA %s%s\n", $what, $detail === '' ? '' : "  ({$detail})"); }
}

echo "=== leer sin consumir ===\n";

unlink_if_exists($lista);
awg_apply_list_add('tunnels', array('tun9000', 'tun9001'));

$a = awg_apply_list_get('tunnels', false);
check('la lectura devuelve lo agregado', $a === array('tun9000', 'tun9001'), json_encode($a));

$b = awg_apply_list_get('tunnels', false);
check('leer de nuevo devuelve lo mismo: no consumio', $b === $a, json_encode($b));

echo "\n=== leer consumiendo, que es el modo viejo ===\n";

$c = awg_apply_list_get('tunnels', true);
check('devuelve el contenido', $c === array('tun9000', 'tun9001'), json_encode($c));
check('y ahora esta vacia', awg_apply_list_get('tunnels', false) === array());

echo "\n=== vaciar por separado ===\n";

awg_apply_list_add('tunnels', array('tun9002'));
check('hay algo pendiente', awg_apply_list_get('tunnels', false) === array('tun9002'));

awg_apply_list_clear('tunnels');
check('clear la vacia', awg_apply_list_get('tunnels', false) === array());
check('clear sobre una vacia no explota', awg_apply_list_clear('tunnels') === null);

echo "\n=== el invariante: un apply fallido no pierde nada ===\n";

unlink_if_exists($lista);
awg_apply_list_add('tunnels', array('tun9000'));

// Asi aplica una pantalla: lee sin consumir, y solo vacia si salio bien
$pendientes = awg_apply_list_get('tunnels', false);
$ret_code = AWG_ERROR_SVC_NOT_RUNNING;   // el servicio estaba parado

if ($ret_code == 0) {
	awg_apply_list_clear('tunnels');
}

check('con el apply fallido, lo pendiente sigue ahi',
      awg_apply_list_get('tunnels', false) === array('tun9000'));

$ret_code = 0;   // ahora el servicio arranco y se aplica de verdad

if ($ret_code == 0) {
	awg_apply_list_clear('tunnels');
}

check('con el apply exitoso, se vacia', awg_apply_list_get('tunnels', false) === array());

echo "\n=== el mensaje del error nuevo ===\n";

$e = awg_get_errors('service', AWG_ERROR_SVC_NOT_RUNNING);
check('devuelve un mensaje', count($e) === 1, json_encode($e));
check('dice que no aplico nada', stripos(implode(' ', $e), 'nothing was applied') !== false);
check('dice que arranque el servicio', stripos(implode(' ', $e), 'not running') !== false);

$e = awg_get_errors('service', AWG_ERROR_SVC_NOT_RUNNING | AWG_ERROR_SVC_CREATE);
check('combinado con otro flag devuelve los dos', count($e) === 2, json_encode($e));

$e = awg_get_errors('service', 0);
check('sin error no devuelve mensajes', count($e) === 0);

unlink_if_exists($lista);

printf("\n%d pasaron, %d fallaron\n", $ok, $bad);

exit($bad > 0 ? 1 : 0);
