<?php
/*
 * test-obfuscation.php - Tests de la validacion de los 16 campos de ofuscacion.
 *
 *   .tools\php\php.exe tools\test-obfuscation.php      (el php de wgeasy)
 *
 * Corre en la maquina de desarrollo, sin firewall y sin pfSense: necesita el
 * arbol src/ al lado, asi que se corre desde el repo y no desde un paquete
 * instalado.
 *
 * awg_validate.inc no se puede incluir tal cual: arranca con require_once de
 * config.inc y companyia, que solo existen en un pfSense. Asi que se extraen
 * del arbol las tres funciones bajo prueba y se evaluan contra un $awgg armado
 * con los valores REALES de awg_globals.inc. De esa forma el test corre sobre
 * el codigo que se publica, no sobre una copia que se desincroniza sola.
 *
 * Lo que se prueba es la logica de validacion. Que el .conf resultante lo
 * acepte awg(8) es otra cosa y se verifica en el firewall.
 */

$src = __DIR__ . '/../src/usr/local/pkg/amneziawg/includes';

function extract_function($path, $name) {
	$code = @file_get_contents($path);

	if ($code === false) {
		fwrite(STDERR, "No se pudo leer {$path}\n");
		exit(2);
	}

	// Las funciones del arbol abren en la columna 0 y cierran igual.
	if (!preg_match('/^function\s+' . preg_quote($name, '/') . '\s*\(.*?^\}$/ms', $code, $m)) {
		fwrite(STDERR, "No se encontro {$name}() en {$path}\n");
		exit(2);
	}

	return $m[0];
}

// gettext no existe fuera de pfSense y aca solo interesa el texto crudo.
if (!function_exists('gettext')) {
	function gettext($s) { return $s; }
}

// Los rangos y la mascara salen del globals de verdad, no de una copia.
$globals = file_get_contents("{$src}/awg_globals.inc");

preg_match("/'obfuscation_fields'.*?'i5'.*?\)\),/s", $globals, $fields);
preg_match("/'header_mask'\s*=>\s*'([^']+)'/", $globals, $mask);

if (empty($fields) || empty($mask)) {
	fwrite(STDERR, "No se pudieron leer obfuscation_fields/header_mask de awg_globals.inc\n");
	exit(2);
}

eval('$awgg = array(' . rtrim($fields[0], ',') . ", 'header_mask' => '{$mask[1]}');");

eval(extract_function("{$src}/awg_api.inc", 'awg_header_bounds'));
eval(extract_function("{$src}/awg_api.inc", 'awg_gen_headers'));
eval(extract_function("{$src}/awg_validate.inc", 'awg_validate_obfuscation'));

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

// Un $pconfig real trae las 16 claves, aunque esten vacias.
function pc($over = array()) {
	$base = array_fill_keys(array('jc', 'jmin', 'jmax', 's1', 's2', 's3', 's4',
				      'h1', 'h2', 'h3', 'h4',
				      'i1', 'i2', 'i3', 'i4', 'i5'), '');

	return array_merge($base, $over);
}

function errs($over = array()) { return awg_validate_obfuscation(pc($over)); }
function n($over = array())    { return count(errs($over)); }

echo "=== awg_header_bounds ===\n";
check('un valor suelto es el rango degenerado', awg_header_bounds('12345') === array(12345, 12345));
check('el rango con guion se parte',            awg_header_bounds('100-200') === array(100, 200));
check('tolera espacios',                        awg_header_bounds('  7-9 ') === array(7, 9));
check('uint32 maximo sin overflow',             awg_header_bounds('4294967295') === array(4294967295, 4294967295));

echo "\n=== la ofuscacion es opcional ===\n";
check('un tunel sin nada no da errores', n() === 0, json_encode(errs()));

echo "\n=== rangos de los enteros ===\n";
check('jc=4 vale',           n(array('jc' => '4')) === 0);
check('jc=0 se rechaza',     n(array('jc' => '0')) === 1);
check('jc=128 vale',         n(array('jc' => '128')) === 0);
check('jc=129 se rechaza',   n(array('jc' => '129')) === 1);
check('s1=0 vale, su minimo es 0', n(array('s1' => '0')) === 0);
check('s1=1281 se rechaza',  n(array('s1' => '1281')) === 1);
check('jc no numerico se rechaza', n(array('jc' => 'abc')) === 1);
check('jc negativo se rechaza',    n(array('jc' => '-5')) === 1);

echo "\n=== jmin contra jmax ===\n";
check('jmin<jmax vale',        n(array('jmin' => '10', 'jmax' => '50')) === 0);
check('jmin==jmax vale',       n(array('jmin' => '50', 'jmax' => '50')) === 0);
check('jmin>jmax se rechaza',  n(array('jmin' => '60', 'jmax' => '50')) === 1);

echo "\n=== la trampa de H1-H4: son texto, no enteros ===\n";
check('numero suelto vale',            n(array('h1' => '787134324')) === 0);
check('RANGO con guion vale',          n(array('h1' => '787134324-1593815189')) === 0,
      json_encode(errs(array('h1' => '787134324-1593815189'))));
check('por debajo de 5 se rechaza',    n(array('h1' => '3')) === 1);
check('5 vale, es el borde',           n(array('h1' => '5')) === 0);
check('4294967295 vale',               n(array('h1' => '4294967295')) === 0);
check('mas que uint32 se rechaza',     n(array('h1' => '9999999999')) >= 1);
check('rango que empieza abajo de 5 se rechaza', n(array('h1' => '2-900')) === 1);
check('rango al reves se rechaza',     n(array('h1' => '900-100')) >= 1);
check('basura se rechaza',             n(array('h1' => '12,34')) === 1);
check('decimal se rechaza',            n(array('h1' => '12.5')) === 1);

echo "\n=== los headers no se pueden solapar ===\n";
check('cuatro distintos valen',
      n(array('h1' => '10', 'h2' => '20', 'h3' => '30', 'h4' => '40')) === 0);
check('dos iguales se rechazan',
      n(array('h1' => '10', 'h2' => '10')) === 1);
check('rangos que se pisan se rechazan',
      n(array('h1' => '100-200', 'h2' => '150-250')) === 1);
check('rangos pegados pero disjuntos valen',
      n(array('h1' => '100-200', 'h2' => '201-300')) === 0,
      json_encode(errs(array('h1' => '100-200', 'h2' => '201-300'))));
check('un rango que contiene a un suelto se rechaza',
      n(array('h1' => '100-200', 'h2' => '150')) === 1);
check('los cuatro iguales dan los 6 pares',
      n(array('h1' => '10', 'h2' => '10', 'h3' => '10', 'h4' => '10')) === 6);

echo "\n=== los campos de AWG 2.0 se validan, pero no se rechazan por ser 2.0 ===\n";
check('s3 con valor valido pasa', n(array('s3' => '20')) === 0, json_encode(errs(array('s3' => '20'))));
check('s3 fuera de rango falla',  n(array('s3' => '2000')) === 1);
check('i1 es texto libre',        n(array('i1' => '<b 0xf1>')) === 0, json_encode(errs(array('i1' => '<b 0xf1>'))));

echo "\n=== awg_gen_headers ===\n";
$dupes = $out_of_range = 0;

for ($i = 0; $i < 400; $i++) {
	$h = awg_gen_headers();

	if ((count($h) !== 4) || (count(array_unique($h)) !== 4)) {
		$dupes++;
	}

	foreach ($h as $v) {
		if (($v < 5) || ($v > 4294967295)) {
			$out_of_range++;
		}
	}
}

check('400 sorteos: siempre 4 headers distintos', $dupes === 0, "repetidos={$dupes}");
check('400 sorteos: siempre entre 5 y 4294967295', $out_of_range === 0, "fuera={$out_of_range}");

$h = awg_gen_headers();
check('lo que sortea pasa su propia validacion',
      count(awg_validate_obfuscation(pc($h))) === 0,
      json_encode(awg_validate_obfuscation(pc($h))));

printf("\n%d pasaron, %d fallaron\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
