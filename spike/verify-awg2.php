<?php
/*
 * verify-awg2.php - corre EN EL FIREWALL, contra el binario instalado.
 *
 *   scp spike/verify-awg2.php admin@FIREWALL:/root/
 *   ssh admin@FIREWALL 'php /root/verify-awg2.php'
 *
 * Que acepta de verdad el backend en los parametros de AmneziaWG 2.0: S3, S4 y
 * las cadenas I1-I5. Hace falta un daemon vivo porque awg(8) reconoce las
 * CLAVES por su cuenta, pero el VALOR de una cadena I lo parsea el proceso go
 * -- newObfChain() en device/obf.go --, asi que un setconf contra una interfaz
 * inexistente no prueba nada de la sintaxis.
 *
 * Usa tun9098, que no existe en la configuracion. Lo levanta, prueba, y lo baja
 * con SIGTERM. No toca ningun tunel configurado ni escribe config.xml.
 */

require_once('/usr/local/pkg/amneziawg/includes/awg_guiconfig.inc');

global $awgg;
awg_globals();

$iface = 'tun9098';
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

// Levantar el daemon de descarte
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

// Esperar el socket UAPI
for ($i = 0; $i < 30; $i++) {
	if (file_exists("{$awgg['run_path']}/{$iface}.sock")) {
		break;
	}
	usleep(200000);
}

check('el daemon de descarte esta arriba',
	file_exists("{$awgg['run_path']}/{$iface}.sock"));

/*
 * Aplica un par clave/valor y devuelve el error, o null si lo acepto.
 */
function awg_try($iface, $key, $value) {
	global $awgg;

	$tmp = tempnam('/tmp', 'awg2');
	chmod($tmp, 0600);

	$privkey = trim(shell_exec("{$awgg['awg']} genkey"));

	file_put_contents($tmp, "[Interface]\nPrivateKey = {$privkey}\n{$key} = {$value}\n");

	exec("{$awgg['awg']} setconf {$iface} " . escapeshellarg($tmp) . " 2>&1", $out, $rc);
	unlink($tmp);

	return ($rc === 0) ? null : implode(' ', $out);
}

printf("\n-- S3 y S4: el relleno que agrega 2.0 --\n\n");

check('S3 (cookie reply) se acepta', is_null($e = awg_try($iface, 'S3', '15')), (string) $e);
check('S4 (transport) se acepta', is_null($e = awg_try($iface, 'S4', '20')), (string) $e);
check('S4 en 0 se acepta (es el default)', is_null($e = awg_try($iface, 'S4', '0')), (string) $e);

printf("\n-- I1-I5: las etiquetas de la mini-gramatica --\n\n");

/*
 * Los ocho constructores de device/obf.go. Los que dependen de los datos del
 * paquete -- d, ds, dz -- se prueban igual, pero ojo: los I-packets se generan
 * con Obfuscate(buf, nil), o sea SIN datos de origen, asi que no tienen
 * sentido ahi aunque el parser los acepte.
 */
$tags = array(
	'<b 0xf0f0f0f0>'	=> 'bytes literales en hex',
	'<b f0f0>'		=> 'bytes sin el prefijo 0x',
	'<t>'			=> 'timestamp Unix de 4 bytes',
	'<r 64>'		=> '64 bytes al azar',
	'<rc 16>'		=> '16 letras al azar',
	'<rd 8>'		=> '8 digitos al azar',
	'<b 0x16030100><r 32>'	=> 'cadena: bytes fijos + azar',
	'<rc 4><rd 4><r 8>'	=> 'cadena de tres',
	'<d>'			=> 'los datos del paquete (sin sentido en un I)',
	'<ds>'			=> 'los datos en base64',
	'<dz 4>'		=> 'el tamano de los datos');

foreach ($tags as $spec => $descr) {
	$e = awg_try($iface, 'I1', $spec);

	check(sprintf('%-22s %s', $spec, $descr), is_null($e), (string) $e);
}

printf("\n-- lo que NO tiene que aceptar --\n\n");

$malas = array(
	'<x 10>'	=> 'etiqueta inexistente',
	'<b 0xf0f>'	=> 'hex de largo impar',
	'<b>'		=> 'bytes sin argumento',
	'<r>'		=> 'random sin largo',
	'<b 0xzz>'	=> 'hex invalido',
	'<r 64'		=> 'sin cerrar el >');

foreach ($malas as $spec => $descr) {
	$e = awg_try($iface, 'I1', $spec);

	check(sprintf('%-22s %s', $spec, $descr), !is_null($e),
		'lo acepto y no deberia');
}

printf("\n-- los cinco a la vez, como quedarian en un tunel --\n\n");

$tmp = tempnam('/tmp', 'awg2');
chmod($tmp, 0600);

$privkey = trim(shell_exec("{$awgg['awg']} genkey"));

$conf = "[Interface]\nPrivateKey = {$privkey}\nListenPort = 51899\n"
	. "Jc = 4\nJmin = 40\nJmax = 70\nS1 = 30\nS2 = 40\nS3 = 15\nS4 = 20\n"
	. "H1 = 1234567891\nH2 = 1234567892\nH3 = 1234567893\nH4 = 1234567894\n"
	. "I1 = <b 0x16030100><r 32>\nI2 = <rc 24>\nI3 = <t><r 16>\n"
	. "I4 = <rd 12>\nI5 = <b 0xdeadbeef><rc 8>\n";

file_put_contents($tmp, $conf);

exec("{$awgg['awg']} setconf {$iface} " . escapeshellarg($tmp) . " 2>&1", $out2, $rc2);
unlink($tmp);

check('un tunel 2.0 completo se aplica', $rc2 === 0, implode(' ', $out2));

if ($rc2 === 0) {
	$shown = shell_exec("{$awgg['awg']} show {$iface} 2>&1");

	printf("\n%s\n", preg_replace('/^/m', '      ', trim($shown)));

	foreach (array('s3', 's4', 'i1', 'i5') as $k) {
		check("awg show devuelve {$k}", stripos($shown, "{$k}:") !== false);
	}
}

printf("\n%d pasaron, %d fallaron\n\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
