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

preg_match("/'obfuscation_fields'.*?'disablecookies'.*?\)\),/s", $globals, $fields);
preg_match("/'header_mask'\s*=>\s*'([^']+)'/", $globals, $mask);
preg_match("/'range_mask'\s*=>\s*'([^']+)'/", $globals, $range_mask);
preg_match("/'header_protection_min_padding'\s*=>\s*(\d+)/", $globals, $hp_min);

/*
 * Sin esto awg_validate_timings() se sale por su guarda y las 25 comprobaciones
 * de aca abajo pasan sin haber ejercitado el final de awg_validate_obfuscation().
 */
preg_match("/'timing_defaults'\s*=>\s*array\((.*?)\),/s", $globals, $timings);

// Sin esto awg_break_size_collisions() se sale por su guarda y no compara nada
preg_match("/'message_sizes'\s*=>\s*array\((.*?)\),/s", $globals, $sizes);

if (empty($fields) || empty($mask) || empty($range_mask) || empty($hp_min) || empty($timings) || empty($sizes)) {
	fwrite(STDERR, "Falta algo en awg_globals.inc: obfuscation_fields, header_mask, range_mask, header_protection_min_padding, timing_defaults o message_sizes\n");
	exit(2);
}

preg_match("/'awg_versions'.*?'label' => 'AmneziaWG 3\.1'.*?\)\),/s", $globals, $versions);

if (empty($versions)) {
	fwrite(STDERR, "No se pudieron leer awg_versions de awg_globals.inc
");
	exit(2);
}

eval('$awgg = array(' . rtrim($fields[0], ',') . ', ' . rtrim($versions[0], ',') .
     ", 'header_mask' => '{$mask[1]}', 'range_mask' => '{$range_mask[1]}'" .
     ", 'header_protection_min_padding' => {$hp_min[1]}" .
     ", 'timing_defaults' => array({$timings[1]})" .
     ", 'message_sizes' => array({$sizes[1]}));");

/*
 * El techo depende de una sonda al backend, que fuera del firewall no existe.
 * Se fija a mano para poder probar las dos orillas de la validacion.
 */
$test_ceiling = 2;

function awg_version_ceiling($use_cache = true) {
	global $test_ceiling;

	return $test_ceiling;
}

eval(extract_function("{$src}/awg_api.inc", 'awg_header_bounds'));
eval(extract_function("{$src}/awg_api.inc", 'awg_min_version'));
eval(extract_function("{$src}/awg_api.inc", 'awg_max_version'));
eval(extract_function("{$src}/awg_api.inc", 'awg_tunnel_version'));
eval(extract_function("{$src}/awg_api.inc", 'awg_gen_headers'));
eval(extract_function("{$src}/awg_api.inc", 'awg_break_size_collisions'));
eval(extract_function("{$src}/awg_api.inc", 'awg_gen_obfuscation'));
eval(extract_function("{$src}/awg_api.inc", 'awg_gen_junk_payload'));
eval(extract_function("{$src}/awg_validate.inc", 'awg_validate_junk_payload'));
eval(extract_function("{$src}/awg_validate.inc", 'awg_validate_obfuscation'));

// La llama awg_validate_obfuscation() al final; las relaciones entre los cinco
// tiempos las prueba tools/test-timings.php.
eval(extract_function("{$src}/awg_validate.inc", 'awg_validate_timings'));

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

// Un $pconfig real trae las 25 claves, aunque esten vacias.
function pc($over = array()) {
	$base = array_fill_keys(array('jc', 'jmin', 'jmax', 's1', 's2', 's3', 's4',
				      'h1', 'h2', 'h3', 'h4',
				      'i1', 'i2', 'i3', 'i4', 'i5',
				      'headerprotectionkey', 'contentpaddingaddition',
				      'rekeyaftertime', 'rekeytimeout', 'rejectaftertime',
				      'keepalivetimeout', 'maxhandshakeattempts',
				      'randomtrailers', 'disablecookies'), '');

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

echo "\n=== los rangos u16 de 3.0 ===\n";
check('un valor suelto vale',      n(array('contentpaddingaddition' => '20')) === 0,
      json_encode(errs(array('contentpaddingaddition' => '20'))));
check('un rango con guion vale',   n(array('contentpaddingaddition' => '12-40')) === 0);
check('cero vale: es "no toques nada"', n(array('contentpaddingaddition' => '0')) === 0);
check('65535 vale, es el borde',   n(array('rekeyaftertime' => '65535')) === 0);
check('65536 se rechaza',          n(array('rekeyaftertime' => '65536')) === 1);
check('un rango al reves se rechaza', n(array('rekeytimeout' => '40-12')) === 1);
check('un decimal se rechaza',     n(array('keepalivetimeout' => '12.5')) === 1);
check('basura se rechaza',         n(array('maxhandshakeattempts' => 'muchas')) === 1);

echo "\n=== la clave de proteccion de headers ===\n";
$clave_ok = base64_encode(str_repeat("\x41", 32));

/*
 * Con la clave puesta hay que darle relleno a los cuatro tipos de paquete, o
 * salta ADEMAS el error de esa regla. Se acompaña para poder probar la forma de
 * la clave por separado.
 */
$con_relleno = array('s1' => '30', 's2' => '30', 's3' => '30', 's4' => '16');

function k($clave) { global $con_relleno; return array_merge($con_relleno, array('headerprotectionkey' => $clave)); }

check('32 bytes en base64 valen', n(k($clave_ok)) === 0, json_encode(errs(k($clave_ok))));
check('31 bytes se rechazan',
      n(k(base64_encode(str_repeat("\x41", 31)))) === 1);
check('33 bytes se rechazan, aunque midan 44 caracteres',
      n(k(base64_encode(str_repeat("\x41", 33)))) === 1);
check('texto que no es base64 se rechaza',
      n(k('esto no es una clave')) === 1);
check('44 caracteres que no decodifican a 32 bytes se rechazan',
      n(k(str_repeat('!', 44))) === 1);

/*
 * Y la regla que ata la clave con S1-S4, que es la unica del backend que cruza
 * dos niveles. Sin ella el .conf sale bien formado, awg(8) lo parsea, y el
 * setconf muere con un 'Invalid argument' que no dice cual de los cuatro falto.
 */
echo "\n=== la clave necesita lugar para su nonce ===\n";

// La regla solo aplica al nivel al que la clave se escribe de verdad
$test_ceiling = 4;

check('sin S1-S4 la clave se rechaza',
      n(array('headerprotectionkey' => $clave_ok)) === 1,
      json_encode(errs(array('headerprotectionkey' => $clave_ok))));
check('con S4 en cero tambien, que es el default de 2.0',
      n(array_merge(k($clave_ok), array('s4' => '0'))) === 1);
check('con S4 en 11 tambien: el minimo es 12',
      n(array_merge(k($clave_ok), array('s4' => '11'))) === 1);
check('con S4 en 12 justo, vale',
      n(array_merge(k($clave_ok), array('s4' => '12'))) === 0,
      json_encode(errs(array_merge(k($clave_ok), array('s4' => '12')))));
check('sin clave, S4 en cero sigue estando bien',
      n(array('s4' => '0')) === 0);
/*
 * Y la otra mitad de la regla: abajo de 3.0 la clave no viaja a ningun .conf,
 * asi que exigir relleno ahi es cobrarle MTU al usuario por nada. Un tunel
 * bajado a 2.0 conserva la clave guardada --eso hace el selector-- y tiene que
 * poder guardarse con S4 en cero.
 */
check('en un tunel 2.0 la clave guardada no exige relleno',
      n(array_merge(k($clave_ok), array('awgversion' => '2', 's4' => '0'))) === 0,
      json_encode(errs(array_merge(k($clave_ok), array('awgversion' => '2', 's4' => '0')))));
/*
 * Un tunel guardado en 1.x --escalon que salio de la lista-- se sube al piso y
 * se juzga como 2.0, que tampoco exige relleno.
 */
check('y uno guardado en el 1.x que ya no se ofrece tampoco',
      n(array_merge(k($clave_ok), array('awgversion' => '1', 's4' => '0'))) === 0,
      json_encode(errs(array_merge(k($clave_ok), array('awgversion' => '1', 's4' => '0')))));
check('pero en 3.0 si',
      n(array_merge(k($clave_ok), array('awgversion' => '3', 's4' => '0'))) === 1);

check('el error nombra los campos que faltan',
      strpos(implode(' ', errs(array_merge(k($clave_ok), array('s2' => '', 's4' => '')))), 'S2, S4') !== false,
      json_encode(errs(array_merge(k($clave_ok), array('s2' => '', 's4' => '')))));

$test_ceiling = 2;

echo "\n=== los booleanos de 3.1 ===\n";
check('on vale',            n(array('randomtrailers' => 'on')) === 0);
check('off vale',           n(array('disablecookies' => 'off')) === 0);
check('vacio vale: es "no lo escribas"', n(array('randomtrailers' => '')) === 0);
check('true se rechaza',    n(array('randomtrailers' => 'true')) === 1);
check('1 se rechaza',       n(array('disablecookies' => '1')) === 1);
check('yes se rechaza',     n(array('randomtrailers' => 'yes')) === 1);

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

echo "\n=== awg_gen_headers: rangos de 2.0 en adelante ===\n";

/*
 * Un header fijo son cuatro bytes constantes en cada paquete de datos, que es
 * una firma por si sola. De 2.0 en adelante el nivel permite un rango y cada
 * paquete elige adentro -- UintRange.PickOne() en device/noise-types.go.
 */
$sueltos = $solapan = $fuera = $invertidos = 0;
$por_banda = array();

for ($i = 0; $i < 400; $i++) {
	$h = awg_gen_headers(2);

	$rangos = array();

	foreach ($h as $name => $v) {
		if (strpos((string) $v, '-') === false) {
			$sueltos++;

			continue;
		}

		[$lo, $hi] = awg_header_bounds($v);

		if ($hi <= $lo) {
			$invertidos++;
		}

		if (($lo < 5) || ($hi > 4294967295)) {
			$fuera++;
		}

		$rangos[$name] = array($lo, $hi);

		// En que cuarto del espacio cayo, para ver que no sea siempre el mismo
		$por_banda[$name][intdiv($lo, 1073741824)] = true;
	}

	foreach ($rangos as $a => $ra) {
		foreach ($rangos as $b => $rb) {
			if (($a < $b) && ($ra[0] <= $rb[1]) && ($rb[0] <= $ra[1])) {
				$solapan++;
			}
		}
	}
}

check('400 sorteos: los cuatro son rangos', $sueltos === 0, "sueltos={$sueltos}");
check('400 sorteos: ninguno invertido', $invertidos === 0, "invertidos={$invertidos}");
check('400 sorteos: ninguno fuera de 5..4294967295', $fuera === 0, "fuera={$fuera}");
check('400 sorteos: ningun par se solapa', $solapan === 0, "solapados={$solapan}");

/*
 * Que banda le toca a cual se sortea. Si H1 cayera siempre en la mas baja y H4
 * en la mas alta, el orden entre los cuatro seria en si mismo un patron.
 */
$fijos = 0;

foreach ($por_banda as $name => $bandas) {
	if (count($bandas) < 2) {
		$fijos++;
	}
}

check('y ninguno cae siempre en la misma banda', $fijos === 0, "fijos={$fijos}");

check('en 1.x siguen siendo valores sueltos, que es lo que ese nivel entiende',
      strpos((string) awg_gen_headers(1)['h1'], '-') === false);

check('un tunel 2.0 entero pasa su propia validacion',
      count(awg_validate_obfuscation(pc(awg_gen_obfuscation(2)))) === 0,
      json_encode(awg_validate_obfuscation(pc(awg_gen_obfuscation(2)))));

echo "\n=== que dos tipos de paquete no midan lo mismo ===\n";

/*
 * El relleno se suma al tamano del mensaje: init 148, response 92, cookie 64.
 * Si dos totales coinciden, esos dos tipos vuelven a tener el mismo largo en el
 * cable y se recupera la firma de la que se estaba escapando. Venia mirandose
 * solo el par init/response.
 */
$tam = array('s1' => 148, 's2' => 92, 's3' => 64);

$choques = 0;

for ($i = 0; $i < 400; $i++) {
	$g = awg_gen_obfuscation(2);

	$largos = array();

	foreach ($tam as $campo => $base) {
		$largos[$campo] = (int) $g[$campo] + $base;
	}

	foreach ($largos as $a => $la) {
		foreach ($largos as $b => $lb) {
			if (($a < $b) && ($la === $lb)) {
				$choques++;
			}
		}
	}
}

check('400 tuneles 2.0: ningun par de tipos mide igual', $choques === 0, "choques={$choques}");

// Y los tres pares a mano, con los valores que chocan exactamente
check('S2 = S1 + 56 se corrige',
      awg_break_size_collisions(array('s1' => '30', 's2' => '86'))['s2'] !== '86');
check('S3 = S1 + 84 se corrige',
      awg_break_size_collisions(array('s1' => '30', 's2' => '40', 's3' => '114'))['s3'] !== '114');
check('S3 = S2 + 28 se corrige',
      awg_break_size_collisions(array('s1' => '30', 's2' => '40', 's3' => '68'))['s3'] !== '68');
check('lo que no choca no se toca',
      awg_break_size_collisions(array('s1' => '30', 's2' => '40', 's3' => '50'))
      === array('s1' => '30', 's2' => '40', 's3' => '50'));
check('sin S3 --nivel 1.x-- no rompe',
      awg_break_size_collisions(array('s1' => '30', 's2' => '40')) === array('s1' => '30', 's2' => '40'));

printf("\n=== el nivel de AmneziaWG del tunel ===\n\n");

$test_ceiling = 2;

check('sin campo no se valida nada: es un tunel de antes del selector',
	count(awg_validate_obfuscation(pc())) === 0);

foreach (array('2') as $ok) {
	check("acepta el nivel {$ok}, que esta bajo el techo",
		count(awg_validate_obfuscation(pc(array('awgversion' => $ok)))) === 0,
		json_encode(awg_validate_obfuscation(pc(array('awgversion' => $ok)))));
}

check('rechaza un nivel por encima del techo del firewall',
	count(awg_validate_obfuscation(pc(array('awgversion' => '3')))) === 1,
	json_encode(awg_validate_obfuscation(pc(array('awgversion' => '3')))));

check('rechaza un nivel que no existe',
	count(awg_validate_obfuscation(pc(array('awgversion' => '7')))) === 1);

check('rechaza cualquier cosa que no sea un nivel',
	count(awg_validate_obfuscation(pc(array('awgversion' => 'dos')))) === 1);

$test_ceiling = 1;

/*
 * Un techo de 1 es alcanzable de verdad: awg_backend_version() arranca en 1 y
 * solo sube si sus sondas contestan, asi que un backend roto o ausente da 1 --
 * que ya no es un escalon de la tabla. El mensaje no puede salir con el nombre
 * vacio.
 */
$errs_techo_1 = awg_validate_obfuscation(pc(array('awgversion' => '2')));

check('con un backend 1.x rechaza el 2.0 que antes aceptaba',
	count($errs_techo_1) === 1, json_encode($errs_techo_1));
check('y lo explica sin dejar el nombre del nivel en blanco',
	(strpos($errs_techo_1[0], 'does not understand any level') !== false),
	$errs_techo_1[0] ?? '(sin error)');

/*
 * 1.x salio de la lista, asi que ya no es un nivel elegible: awg_tunnel_version()
 * sube al piso, y contra un techo de 1 el techo gana y queda en 1. Lo que se
 * comprueba es que un valor viejo guardado no bloquee el guardado.
 */
check('un 1.x guardado no bloquea el guardado',
	count(awg_validate_obfuscation(pc(array('awgversion' => '1')))) === 0,
	json_encode(awg_validate_obfuscation(pc(array('awgversion' => '1')))));

/*
 * Y con un backend 3.1, que es donde el techo llega a lo que este paquete sabe
 * escribir. Los tres escalones que se ofrecen tienen que ser elegibles.
 */
$test_ceiling = 4;

foreach (array('2', '3', '4') as $ok) {
	check("con techo 3.1 acepta el nivel {$ok}",
		count(awg_validate_obfuscation(pc(array('awgversion' => $ok)))) === 0,
		json_encode(awg_validate_obfuscation(pc(array('awgversion' => $ok)))));
}

check('con techo 3.1 el 5 sigue sin existir',
	count(awg_validate_obfuscation(pc(array('awgversion' => '5')))) === 1);

$test_ceiling = 3;

check('con un backend 3.0 se rechaza el 3.1',
	count(awg_validate_obfuscation(pc(array('awgversion' => '4')))) === 1);

$test_ceiling = 2;

printf("\n=== la gramatica de los I1-I5 ===\n\n");

/*
 * Las reglas son las de newObfChain() y sus builders, en device/obf.go. Lo que
 * el backend acepta tiene que pasar, y lo que rechaza tiene que fallar: un
 * validador mas laxo deja pasar un tunel que despues no levanta, y uno mas
 * estricto bloquea una config que en otro lado funciona.
 */
$buenos = array(
	'<b 0xf0f0>',
	'<b f0f0>',
	'<b 0x16030100><r 32>',
	'<rc 8><t><rd 4>',
	'<t>',
	'<r 1000>',
	'<b 0xaa> <r 8>',
	'<d><ds><dz 2>');

foreach ($buenos as $bueno) {
	check("acepta {$bueno}", awg_validate_junk_payload($bueno) === '',
		awg_validate_junk_payload($bueno));
}

$malos = array(
	'<r 8'		=> 'un < sin cerrar',
	'<z 5>'		=> 'una etiqueta que no existe',
	'<b>'		=> '<b> sin argumento',
	'<b 0xf>'	=> 'hex de largo impar',
	'<b 0xzz>'	=> 'hex que no es hex',
	'<r>'		=> '<r> sin largo',
	'<r -5>'	=> 'largo negativo, que del otro lado revienta el proceso',
	'<r 1001>'	=> 'largo que no entra en un paquete',
	'<>'		=> 'etiqueta vacia',
	'hola'		=> 'texto suelto sin etiquetas',
	'<r 8>hola'	=> 'texto suelto despues de una etiqueta',
	'hola<r 8>'	=> 'texto suelto antes de una etiqueta');

foreach ($malos as $malo => $por_que) {
	check("rechaza {$por_que}", awg_validate_junk_payload($malo) !== '',
		"acepto '{$malo}'");
}

printf("\n=== el sorteo, que es lo que reemplaza a las constantes ===\n\n");

$muestras = array();
$fuera = $invalidos = 0;

for ($i = 0; $i < 200; $i++) {
	$gen = awg_gen_obfuscation(2);

	$muestras[] = implode(',', $gen);

	if (count(awg_validate_obfuscation(pc($gen))) !== 0) {
		$invalidos++;
	}

	if (((int) $gen['jmin'] > (int) $gen['jmax']) ||
	    ((int) $gen['jc'] < 1) || ((int) $gen['jc'] > 128) ||
	    ((int) $gen['s1'] < 0) || ((int) $gen['s1'] > 1280) ||
	    ((int) $gen['s4'] !== 0)) {
		$fuera++;
	}
}

check('200 sorteos: todos pasan su propia validacion', $invalidos === 0, "invalidos={$invalidos}");
check('200 sorteos: todos dentro de rango, jmin<=jmax y S4 en cero', $fuera === 0, "fuera={$fuera}");
check('200 sorteos: ninguno repetido, no hay constante escondida',
	count(array_unique($muestras)) === 200,
	sprintf('distintos=%d', count(array_unique($muestras))));

$sin_awg2 = awg_gen_obfuscation(1);

check('en 1.x no sortea S3/S4, que ese backend no entiende',
	!isset($sin_awg2['s3']) && !isset($sin_awg2['s4']),
	implode(',', array_keys($sin_awg2)));

check('en 2.0 si los sortea', isset(awg_gen_obfuscation(2)['s3']));

/*
 * De 3.0 en adelante S4 no puede quedar en cero: un tunel nuevo nace con
 * HeaderProtectionKey y el backend exige relleno en los cuatro tipos de
 * paquete. Lo que se prueba es que lo sorteado alcance la regla por si solo.
 */
$cortos = $no_valida = 0;
$min_hp = $awgg['header_protection_min_padding'];

for ($i = 0; $i < 200; $i++) {
	$gen = awg_gen_obfuscation(3);

	foreach (array('s1', 's2', 's3', 's4') as $padding) {
		if ((int) $gen[$padding] < $min_hp) {
			$cortos++;
		}
	}

	if (count(awg_validate_obfuscation(pc(array_merge($gen,
	    array('headerprotectionkey' => $clave_ok))))) !== 0) {
		$no_valida++;
	}
}

check("200 sorteos de 3.0: los cuatro rellenos llegan a {$min_hp}", $cortos === 0, "cortos={$cortos}");
check('200 sorteos de 3.0: todos valen con una clave puesta', $no_valida === 0, "invalidos={$no_valida}");

/*
 * La razon por la que S1+148 != S2+92: que un init y un response no midan igual
 * en el cable. Este backend los desempata por el header, pero otro extremo
 * puede ser otra implementacion.
 */
$colisiones = 0;

for ($i = 0; $i < 300; $i++) {
	$gen = awg_gen_obfuscation(2);

	if (((int) $gen['s1'] + 148) === ((int) $gen['s2'] + 92)) {
		$colisiones++;
	}
}

check('300 sorteos: ningun init mide lo mismo que un response', $colisiones === 0,
	"colisiones={$colisiones}");

printf("\n=== las plantillas sorteadas de los I ===\n\n");

$plantillas = array();
$rechazadas = 0;

for ($i = 0; $i < 200; $i++) {
	$p = awg_gen_junk_payload();
	$plantillas[] = $p;

	if (awg_validate_junk_payload($p) !== '') {
		$rechazadas++;
		if ($rechazadas === 1) { printf("       primera mala: %s -> %s\n", $p, awg_validate_junk_payload($p)); }
	}
}

check('200 plantillas: todas pasan la validacion de la gramatica',
	$rechazadas === 0, "rechazadas={$rechazadas}");

check('200 plantillas: ninguna repetida',
	count(array_unique($plantillas)) === 200,
	sprintf('distintas=%d', count(array_unique($plantillas))));

check('200 plantillas: todas empiezan con bytes literales propios',
	count(array_filter($plantillas, function($p) { return strpos($p, '<b 0x') === 0; })) === 200);

check('200 plantillas: ninguna repite el timestamp',
	count(array_filter($plantillas, function($p) { return substr_count($p, '<t>') > 1; })) === 0);

/*
 * El ejemplo que publica la documentacion de Amnezia es justamente lo que no
 * hay que emitir: si esta publicado, un DPI puede tenerlo.
 */
check('200 plantillas: ninguna es el ejemplo publicado en la documentacion',
	count(array_filter($plantillas, function($p) {
		return $p === '<b 0xd100000001><rc 8><t><r 50>';
	})) === 0);

printf("\n%d pasaron, %d fallaron\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
