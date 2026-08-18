<?php
/*
 * test-timings.php - las relaciones entre los cinco tiempos de 3.0, sin firewall.
 *
 *   php tools/test-timings.php
 *
 * Cada tiempo por separado ya lo valida el bucle de rangos. Lo que se prueba
 * aca es como quedan ENTRE ELLOS, que es donde el backend no mira nada: el
 * setconf entra, el tunel levanta, y despues se porta mal de una forma que no
 * se parece a un problema de configuracion.
 *
 * Las dos reglas salen de device/timers.go de amneziawg-go:
 *
 *   keyRefreshTimeoutReceiving() = max(0, Reject.PickOne() - Keepalive.Lo() - Rekey.Lo())
 *       En cero, receive.go dispara un handshake por cada paquete recibido.
 *
 *   keyRefreshTimeoutSending() = RekeyAfter.PickOne()
 *   keychainExpireTime()       = Reject.Hi()
 *       Si el rekey puede caer despues del vencimiento, la clave muere antes de
 *       renovarse y el trafico se corta una vez por sesion.
 *
 * Y el detalle que hace falta para las dos: un campo vacio NO se saltea. El
 * backend cae en su constante y la relacion se rompe igual.
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

/*
 * Los defaults y la mascara salen del archivo de verdad y no se copian aca: si
 * alguien los cambia en awg_globals.inc, el test tiene que seguirlos. Que se
 * correspondan con device/constants.go se comprueba mas abajo.
 */
$globals = file_get_contents("{$src}/awg_globals.inc");

preg_match("/'timing_defaults'\s*=>\s*array\((.*?)\),/s", $globals, $td);
preg_match("/'range_mask'\s*=>\s*('[^']+')/", $globals, $rm);

if (empty($td) || empty($rm)) {
	fwrite(STDERR, "No se pudieron leer timing_defaults o range_mask de awg_globals.inc\n");
	exit(2);
}

eval("\$awgg = array('timing_defaults' => array({$td[1]}), 'range_mask' => {$rm[1]});");

// El techo lo pone el firewall; aca se fija en el maximo para que el nivel que
// se prueba sea siempre el que dice el tunel y no uno recortado.
function awg_version_ceiling($use_cache = true) { return 4; }

eval(extract_function("{$src}/awg_api.inc", 'awg_header_bounds'));
eval(extract_function("{$src}/awg_api.inc", 'awg_tunnel_version'));
eval(extract_function("{$src}/awg_validate.inc", 'awg_validate_timings'));

/*
 * awg_s4_headroom() vive en awg_api.inc y usa otras cuatro constantes del
 * globals. Se traen aparte para no ensuciar el $awgg de los timings.
 */
preg_match("/'transport_overhead'\s*=>\s*(\d+)/", $globals, $to);
preg_match("/'outer_overhead_v4'\s*=>\s*(\d+)/", $globals, $o4);
preg_match("/'outer_overhead_v6'\s*=>\s*(\d+)/", $globals, $o6);
preg_match("/'path_mtu_assumed'\s*=>\s*(\d+)/", $globals, $pm);
preg_match("/'default_mtu'\s*=>\s*(\d+)/", $globals, $dm);
preg_match("/'header_protection_min_padding'\s*=>\s*(\d+)/", $globals, $hp);

if (empty($to) || empty($o4) || empty($o6) || empty($pm) || empty($dm) || empty($hp)) {
	fwrite(STDERR, "Faltan las constantes de overhead en awg_globals.inc\n");
	exit(2);
}

$awgg['header_protection_min_padding'] = (int) $hp[1];

$awgg['transport_overhead']	= (int) $to[1];
$awgg['outer_overhead_v4']	= (int) $o4[1];
$awgg['outer_overhead_v6']	= (int) $o6[1];
$awgg['path_mtu_assumed']	= (int) $pm[1];
$awgg['default_mtu']		= (int) $dm[1];

eval(extract_function("{$src}/awg_api.inc", 'awg_s4_headroom'));

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

function tun($extra = array()) {
	return array_merge(array('awgversion' => '3'), $extra);
}

function errores($extra = array()) {
	return awg_validate_timings(tun($extra));
}

echo "=== los defaults del paquete son los de device/constants.go ===\n";

/*
 * Si upstream mueve una constante y aca queda la vieja, las dos reglas comparan
 * contra un numero que el backend ya no usa y dejan pasar lo que rompe.
 */
$esperados = array(
	'rekeyaftertime'	=> 120,
	'rekeytimeout'		=> 5,
	'rejectaftertime'	=> 180,
	'keepalivetimeout'	=> 10,
	'maxhandshakeattempts'	=> 18);

foreach ($esperados as $campo => $valor) {
	check("{$campo} = {$valor}",
	      ($awgg['timing_defaults'][$campo] ?? null) === $valor,
	      var_export($awgg['timing_defaults'][$campo] ?? null, true));
}

check('y los de fabrica no se rechazan a si mismos', empty(errores()), implode(' | ', errores()));

echo "\n=== abajo de 3.0 no se escribe ninguno, asi que no se valida ===\n";

check('un tunel 2.0 con timings imposibles pasa',
      empty(awg_validate_timings(array('awgversion' => '2', 'rejectaftertime' => '1'))));
check('y uno 1.x tambien',
      empty(awg_validate_timings(array('awgversion' => '1', 'rejectaftertime' => '1'))));

echo "\n=== RejectAfterTime contra KeepaliveTimeout + RekeyTimeout ===\n";

check('180 sobre 10+5 esta bien', empty(errores(array('rejectaftertime' => '180'))));

/*
 * Bajar RejectAfterTime obliga a bajar RekeyAfterTime con el: el default de
 * este ultimo son 120, y la segunda regla lo agarra. Los casos de aca abajo
 * llevan los dos para poder mirar UNA regla por vez; que sea asi se prueba
 * aparte, al final de esta seccion.
 */
check('16 sobre 10+5 esta bien, apenas',
      empty(errores(array('rejectaftertime' => '16', 'rekeyaftertime' => '10'))),
      implode(' | ', errores(array('rejectaftertime' => '16', 'rekeyaftertime' => '10'))));

$e = errores(array('rejectaftertime' => '15', 'rekeyaftertime' => '10'));
check('15 sobre 10+5 se rechaza: es igual, no mayor', !empty($e));
check('y el error nombra el piso', (bool) preg_grep('/10 \+ 5 = 15/', $e), implode(' | ', $e));

check('12 se rechaza', !empty(errores(array('rejectaftertime' => '12'))));

/*
 * El caso que motivo timing_defaults: subir los otros dos con RejectAfterTime
 * vacio. Antes de tenerlos, esto pasaba la validacion porque no habia contra
 * que comparar -- y el backend comparaba igual, contra sus constantes.
 */
check('subir Keepalive y Rekey con Reject VACIO se rechaza contra el default de 180',
      !empty(errores(array('keepalivetimeout' => '150', 'rekeytimeout' => '40'))),
      implode(' | ', errores(array('keepalivetimeout' => '150', 'rekeytimeout' => '40'))));
check('y con margen no se rechaza',
      empty(errores(array('keepalivetimeout' => '100', 'rekeytimeout' => '40'))));

// PickOne() puede sortear el piso del rango, asi que el piso es lo que manda
check('un rango de Reject se juzga por su piso: 14-900 se rechaza',
      !empty(errores(array('rejectaftertime' => '14-900'))));
check('y 20-900 no', empty(errores(array('rejectaftertime' => '20-900'))));

// Lo/Lo de los otros dos, que es lo que resta keyRefreshTimeoutReceiving()
check('de Keepalive y Rekey se toma el piso: 30-90 y 30-90 contra Reject 100 esta bien',
      empty(errores(array('keepalivetimeout' => '30-90', 'rekeytimeout' => '30-90',
                          'rejectaftertime' => '100', 'rekeyaftertime' => '80'))),
      implode(' | ', errores(array('keepalivetimeout' => '30-90', 'rekeytimeout' => '30-90',
                                   'rejectaftertime' => '100', 'rekeyaftertime' => '80'))));

/*
 * Que bajar uno solo no alcanza, que es la trampa de arriba y vale pincharla:
 * RejectAfterTime a 100 deja de estar sobre el piso, pero RekeyAfterTime sigue
 * en su default de 120 y la segunda regla lo agarra.
 */
$e = errores(array('rejectaftertime' => '100'));
check('bajar Reject sin bajar Rekey lo agarra la segunda regla',
      (bool) preg_grep('/RekeyAfterTime/', $e), implode(' | ', $e));

echo "\n=== RekeyAfterTime contra RejectAfterTime ===\n";

check('120 debajo de 180 esta bien', empty(errores(array('rekeyaftertime' => '120'))));

$e = errores(array('rekeyaftertime' => '200'));
check('200 contra 180 se rechaza', !empty($e));
check('y el error dice los dos numeros', (bool) preg_grep('/200.*180/', $e), implode(' | ', $e));

check('iguales tambien se rechaza: la clave vence justo cuando se renovaria',
      !empty(errores(array('rekeyaftertime' => '180'))));

// keychainExpireTime() usa Hi(), y el rekey puede sortear hasta su Hi()
check('se juzga por el techo de los dos: 100-200 contra 180 se rechaza',
      !empty(errores(array('rekeyaftertime' => '100-200'))));
check('100-170 contra 180 pasa',
      empty(errores(array('rekeyaftertime' => '100-170'))));
check('subir los dos juntos pasa',
      empty(errores(array('rekeyaftertime' => '300', 'rejectaftertime' => '400'))));

echo "\n=== el cero, que no significa cero ===\n";

/*
 * UintRange.IsZero() es "el uint64 empaquetado vale 0". Un 0 pelado y un 0-0
 * dan cero empaquetado, asi que el backend los lee como "sin definir" y cae en
 * su constante. Un 0-N no, porque el hi va en los 32 bits de arriba.
 */
check('RejectAfterTime = 0 no se rechaza: el backend lee 180, no 0',
      empty(errores(array('rejectaftertime' => '0'))),
      implode(' | ', errores(array('rejectaftertime' => '0'))));
check('0-0 es lo mismo', empty(errores(array('rejectaftertime' => '0-0'))));
check('pero 0-14 si se rechaza: ese rango si puede sortear cerca de cero',
      !empty(errores(array('rejectaftertime' => '0-14'))));
check('y RekeyAfterTime = 0 tampoco se rechaza',
      empty(errores(array('rekeyaftertime' => '0'))));

echo "\n=== lo mal formado no se reporta dos veces ===\n";

/*
 * La forma de cada campo la valida el bucle de rangos de awg_validate_obfuscation().
 * Aca solo hay que no romperse ni agregar un segundo error por lo mismo.
 */
check('un valor que no es un rango se ignora aca',
      empty(errores(array('rejectaftertime' => 'hola'))),
      implode(' | ', errores(array('rejectaftertime' => 'hola'))));
check('un rango al reves se ignora aca',
      empty(errores(array('rejectaftertime' => '900-100'))));
check('un campo ausente no rompe', empty(awg_validate_timings(array('awgversion' => '3'))));

echo "\n=== las dos reglas son independientes ===\n";

$e = errores(array('rejectaftertime' => '12', 'rekeyaftertime' => '500'));
check('un config que rompe las dos da los dos errores', count($e) === 2, implode(' | ', $e));

echo "\n=== sin defaults no se inventan errores ===\n";

/*
 * Un indice ausente en PHP vale cero, y ese cero rompe las dos desigualdades a
 * la vez: sin la guarda, un $awgg incompleto producia dos errores sobre campos
 * que el usuario habia dejado vacios. Lo agarro test-obfuscation.php, que arma
 * su propio $awgg y no traia timing_defaults.
 */
$completos = $awgg['timing_defaults'];

$awgg['timing_defaults'] = array();
check('con timing_defaults vacio no valida', empty(errores(array('rejectaftertime' => '1'))),
      implode(' | ', errores(array('rejectaftertime' => '1'))));

unset($awgg['timing_defaults']);
check('sin timing_defaults tampoco', empty(errores(array('rejectaftertime' => '1'))));

$awgg['timing_defaults'] = array_slice($completos, 0, 3);
check('con timing_defaults incompleto tampoco', empty(errores(array('rejectaftertime' => '1'))));

$awgg['timing_defaults'] = array_merge($completos, array('rejectaftertime' => 'ciento ochenta'));
check('con un default que no es un numero tampoco', empty(errores(array('rejectaftertime' => '1'))));

$awgg['timing_defaults'] = $completos;
check('y con los defaults de vuelta vuelve a validar', !empty(errores(array('rejectaftertime' => '1'))));

echo "\n=== cuanto S4 entra sin fragmentar ===\n";

/*
 * S4 se paga en cada paquete de datos, asi que su limite real es el MTU y no un
 * numero del protocolo: en el cable un paquete mide MTU + S4 + 32, mas 28 de
 * IPv4 o 48 de IPv6.
 */
$m = awg_s4_headroom(1420);

check('con el MTU de fabrica quedan 20 bytes sobre IPv4', $m['v4'] === 20, var_export($m, true));
check('y ninguno sobre IPv6: 1420 llena los 1500 justo', $m['v6'] === 0, var_export($m, true));

$m = awg_s4_headroom(1400);
check('bajando el MTU a 1400 entran 40 sobre IPv4', $m['v4'] === 40);
check('y 20 sobre IPv6', $m['v6'] === 20);

$m = awg_s4_headroom(1460);
check('con 1460 ya no entra ni S4 en cero sobre IPv4', $m['v4'] < 0, var_export($m, true));

/*
 * El piso de la proteccion de headers contra el MTU: 12 bytes de S4 necesitan
 * un MTU de 1428 o menos sobre IPv4, y de 1408 o menos sobre IPv6.
 */
$min = $awgg['header_protection_min_padding'];

check('la proteccion de headers entra sobre IPv4 con el MTU de fabrica',
      awg_s4_headroom(1420)['v4'] >= $min);
check('pero NO sobre IPv6 con el MTU de fabrica',
      awg_s4_headroom(1420)['v6'] < $min);
check('sobre IPv6 hace falta bajar a 1408', awg_s4_headroom(1408)['v6'] >= $min,
      var_export(awg_s4_headroom(1408), true));

check('un MTU invalido cae en el de fabrica y no rompe',
      awg_s4_headroom(0) === awg_s4_headroom($awgg['default_mtu']));

printf("\n%d pasaron, %d fallaron\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
