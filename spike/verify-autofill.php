<?php
/*
 * verify-autofill.php - corre EN EL FIREWALL, contra el binario instalado.
 *
 *   scp spike/verify-autofill.php admin@FIREWALL:/root/
 *   ssh admin@FIREWALL 'php /root/verify-autofill.php'
 *
 * El auto-relleno sortea valores y plantillas por tunel. Los tests locales
 * comprueban que lo sorteado pase NUESTRA validacion; lo que solo se puede
 * medir aca es si lo acepta el backend de verdad, que es otra cosa y es la que
 * importa: una plantilla que nuestro validador aprueba y el proceso go rechaza
 * da un tunel que no levanta, sin ningun error que apunte al campo.
 *
 * Y al reves: lo que nuestro validador rechaza tiene que ser rechazado tambien
 * alla, o estariamos bloqueando configuraciones que en otro lado funcionan. Las
 * tres excepciones a eso son deliberadas y se prueban como tales.
 *
 * Hace falta un daemon vivo: awg(8) reconoce las CLAVES por su cuenta, pero el
 * VALOR de una cadena I lo parsea el proceso go -- newObfChain() en
 * device/obf.go -- asi que un setconf contra una interfaz inexistente no prueba
 * nada de la sintaxis. Usa tun9096, que no esta en la configuracion, y lo baja
 * al terminar.
 */

require_once('/usr/local/pkg/amneziawg/includes/awg_guiconfig.inc');

global $awgg;
awg_globals();

$iface = 'tun9096';
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

if (!empty(shell_exec("ifconfig {$iface} 2>/dev/null"))) {
	fwrite(STDERR, "{$iface} ya existe, abortando\n");
	exit(2);
}

exec("{$awgg['awg_go']} {$iface} 2>&1", $out, $rc);

if ($rc !== 0) {
	fwrite(STDERR, "no arranco el daemon: " . implode(' ', $out) . "\n");
	exit(2);
}

register_shutdown_function(function() use ($iface) {
	exec("pkill -f 'amneziawg-go {$iface}' 2>/dev/null");
	usleep(500000);
	exec("ifconfig {$iface} destroy 2>/dev/null");
});

for ($i = 0; $i < 30; $i++) {
	if (file_exists("{$awgg['run_path']}/{$iface}.sock")) {
		break;
	}
	usleep(200000);
}

check('el daemon de descarte esta arriba',
	file_exists("{$awgg['run_path']}/{$iface}.sock"));

/*
 * Aplica un juego de pares al device vivo. Devuelve null si lo acepto, o el
 * error. Es el mismo camino que usa el paquete al sincronizar un tunel.
 */
function awg_apply($iface, $pairs) {
	global $awgg;

	$tmp = tempnam('/tmp', 'awgauto');
	chmod($tmp, 0600);

	$lines = array('[Interface]',
		       'PrivateKey = ' . trim(shell_exec("{$awgg['awg']} genkey")));

	foreach ($pairs as $key => $value) {
		$lines[] = "{$key} = {$value}";
	}

	file_put_contents($tmp, implode("\n", $lines) . "\n");

	exec("{$awgg['awg']} setconf {$iface} " . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
	unlink($tmp);

	return ($rc === 0) ? null : implode(' ', $out);
}

printf("\n=== lo que sortea el paquete, contra el backend de verdad ===\n\n");

$rechazados = 0;
$primer_error = '';

for ($i = 0; $i < 25; $i++) {
	$gen = awg_gen_obfuscation(awg_version_ceiling());
	$pairs = array();

	foreach ($gen as $field => $value) {
		$pairs[ucfirst($field)] = $value;
	}

	$e = awg_apply($iface, $pairs);

	if (!is_null($e)) {
		$rechazados++;

		if ($primer_error === '') {
			$primer_error = json_encode($pairs) . ' -> ' . $e;
		}
	}
}

check('25 juegos sorteados: el backend los acepta todos',
	$rechazados === 0, $primer_error);

printf("\n=== las plantillas de los I, que es lo que no se puede probar sin daemon ===\n\n");

$rechazadas = 0;
$primer_error = '';
$vistas = array();

for ($i = 0; $i < 40; $i++) {
	$plantilla = awg_gen_junk_payload();
	$vistas[] = $plantilla;

	$e = awg_apply($iface, array('I1' => $plantilla));

	if (!is_null($e)) {
		$rechazadas++;

		if ($primer_error === '') {
			$primer_error = "{$plantilla} -> {$e}";
		}
	}
}

check('40 plantillas sorteadas: el backend las acepta todas',
	$rechazadas === 0, $primer_error);

check('40 plantillas sorteadas: ninguna se repitio',
	count(array_unique($vistas)) === 40,
	sprintf('distintas=%d', count(array_unique($vistas))));

// Las cinco juntas, que es como quedan en un tunel de verdad.
$cinco = array();

foreach (array('I1', 'I2', 'I3', 'I4', 'I5') as $slot) {
	$cinco[$slot] = awg_gen_junk_payload();
}

check('las cinco plantillas juntas entran en un mismo tunel',
	is_null($e = awg_apply($iface, $cinco)), (string) $e);

printf("\n=== nuestro validador contra el backend ===\n\n");

/*
 * La propiedad que importa: que no aceptemos nada que el backend rechace. Lo
 * contrario -- rechazar algo que el backend acepta -- se permite solo en los
 * tres casos de abajo, y por eso van aparte.
 */
$corpus = array(
	'<b 0xf0f0>', '<b f0f0>', '<t>', '<r 64>', '<rc 16>', '<rd 8>',
	'<b 0x16030100><r 32>', '<rc 4><rd 4><r 8>', '<d>', '<ds>', '<dz 4>',
	'<b 0xaa> <r 8>', '<r 1000>',
	'<x 10>', '<b 0xf0f>', '<b>', '<r>', '<b 0xzz>', '<r 64', '<>',
	'', '   ');

$falsos_ok = array();

foreach ($corpus as $spec) {
	$nuestro = ($spec === '') ? '' : awg_validate_junk_payload($spec);
	$suyo    = ($spec === '') ? null : awg_apply($iface, array('I1' => $spec));

	// Nosotros lo damos por bueno y el backend lo rechaza: eso no puede pasar.
	if (($nuestro === '') && !is_null($suyo)) {
		$falsos_ok[] = "{$spec} -> {$suyo}";
	}
}

check('no aprobamos nada que el backend rechace',
	empty($falsos_ok), implode(' | ', $falsos_ok));

printf("\n=== las tres veces que somos MAS estrictos, a proposito ===\n\n");

$estrictos = array(
	'<r -5>'	=> 'largo negativo: el backend lo toma y el proceso go se cae despues',
	'<r 5000>'	=> 'largo que no entra en un paquete UDP',
	'<r 8>basura'	=> 'texto suelto que el backend ignora en silencio');

foreach ($estrictos as $spec => $por_que) {
	$nuestro = awg_validate_junk_payload($spec);

	check(sprintf('lo rechazamos: %s', $por_que), $nuestro !== '',
		'lo aceptamos y no deberiamos');
}

printf("\n%d pasaron, %d fallaron\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
