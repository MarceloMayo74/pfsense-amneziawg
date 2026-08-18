<?php
/*
 * test-platform-guard.php - la guarda de version, sin firewall.
 *
 *   php tools/test-platform-guard.php
 *
 * Por que existe esta guarda: el ABI del .pkg NO es un control de version de
 * pfSense. FreeBSD:16:amd64 lo cumple 2.9.0 CE y lo cumplen seis releases de
 * pfSense Plus --25.11, 25.11.1, 26.03, 26.03.1, 26.07 y 26.10-- y en todas
 * ellas pkg(8) acepta el paquete sin una sola advertencia. Sin la guarda, el
 * paquete se cablea al arranque de un firewall que nadie probo.
 *
 * Lo que se prueba aca es la decision: que versiones se aceptan, cuales no, y
 * que la comparacion sea por PREFIJO -- para que 2.9.0-BETA, 2.9.0 y 2.9.0-p1
 * sean la misma version probada sin tener que enumerar cada parche.
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

// Lo que la guarda le pide al sistema, y aca no hay sistema
function get_single_sysctl($name) { return '1600018'; }

foreach (array('awg_platform', 'awg_platform_supported', 'awg_platform_forced',
	       'awg_platform_refusal_text') as $fn) {
	eval(extract_function("{$src}/awg_api.inc", $fn));
}

$flag = sys_get_temp_dir() . '/awg-test-force-' . getmypid();

$g = array('product_label' => 'pfSense', 'product_version' => '2.9.0-BETA');
$awgg = array('supported_versions' => array('2.9.'), 'force_install_flag' => $flag);

$ok = 0; $bad = 0;

function check($what, $cond, $detail = '') {
	global $ok, $bad;

	if ($cond) { $ok++; printf("  ok    %s\n", $what); }
	else { $bad++; printf("  FALLA %s%s\n", $what, $detail === '' ? '' : "  ({$detail})"); }
}

function con_version($v, $soportadas = null) {
	global $g, $awgg;

	$g['product_version'] = $v;

	if (!is_null($soportadas)) {
		$awgg['supported_versions'] = $soportadas;
	}

	return awg_platform_supported();
}

echo "=== que versiones acepta ===\n";

check('2.9.0-BETA es la probada',      con_version('2.9.0-BETA') === true);
check('2.9.0 tambien, sin el -BETA',   con_version('2.9.0') === true);
check('2.9.0-p1 tambien: es prefijo',  con_version('2.9.0-p1') === true);
check('2.9.1 tambien',                 con_version('2.9.1') === true);

echo "\n=== y cuales no ===\n";

check('2.8.1 no (FreeBSD 15)',         con_version('2.8.1') === false);
check('2.7.2 no (FreeBSD 14)',         con_version('2.7.2') === false);
check('Plus 26.03.1 NO, aunque comparta ABI', con_version('26.03.1') === false);
check('Plus 26.07 NO, aunque comparta el commit de FreeBSD', con_version('26.07') === false);
check('Plus 25.11 no',                 con_version('25.11') === false);
check('2.9 a secas no: mas corta que el prefijo', con_version('2.9') === false);
check('vacia no: sin saber que es, no se afirma', con_version('') === false);

echo "\n=== la lista es de datos, no de codigo ===\n";

check('agregar 26.07 a la lista la acepta',
      con_version('26.07', array('2.9.', '26.07')) === true);
check('y 2.9.0 sigue aceptada',
      con_version('2.9.0-BETA', array('2.9.', '26.07')) === true);
check('pero 26.03.1 sigue afuera',
      con_version('26.03.1', array('2.9.', '26.07')) === false);

con_version('2.9.0-BETA', array('2.9.'));

echo "\n=== la salida de emergencia ===\n";

check('sin el archivo, no esta forzado', awg_platform_forced() === false);

touch($flag);
check('con el archivo, esta forzado', awg_platform_forced() === true);
unlink($flag);
check('y borrarlo lo desactiva', awg_platform_forced() === false);

echo "\n=== el mensaje dice lo que hace falta ===\n";

$g['product_label'] = 'pfSense Plus';
$g['product_version'] = '26.03.1';
$txt = awg_platform_refusal_text();

check('nombra el producto encontrado', strpos($txt, 'pfSense Plus') !== false);
check('nombra la version encontrada',  strpos($txt, '26.03.1') !== false);
check('nombra la version esperada',    strpos($txt, '2.9.') !== false);
check('explica que el ABI no alcanza', stripos($txt, 'ABI') !== false);
check('dice que no cableo nada',       stripos($txt, 'wired') !== false);
check('dice como forzarlo',            strpos($txt, $flag) !== false);

printf("\n%d pasaron, %d fallaron\n", $ok, $bad);

exit($bad > 0 ? 1 : 0);
