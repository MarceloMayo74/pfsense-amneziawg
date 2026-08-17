<?php
/*
 * verify-awg3.php - corre EN EL FIREWALL, contra los binarios instalados.
 *
 *   scp spike/verify-awg3.php admin@FIREWALL:/root/
 *   ssh admin@FIREWALL 'php /root/verify-awg3.php'
 *
 * Hermano de verify-awg2.php, para los nueve parametros que estrenan 3.0 y 3.1.
 * Lo que solo se puede medir aca es si el backend ACEPTA lo que este paquete
 * escribe: la validacion y el filtrado por nivel ya los cubren los tests de
 * tools/, pero que awg(8) y amneziawg-go se traguen el archivo no lo prueba
 * nadie fuera del firewall.
 *
 * Hace falta un daemon vivo y no alcanza un setconf contra una interfaz que no
 * existe: awg(8) reconoce las CLAVES por su cuenta, pero los VALORES --que la
 * clave sea de 32 bytes, que un rango sea coherente-- los mira el proceso go.
 *
 * Usa tun9097, que no existe en la configuracion. Lo levanta, prueba y lo baja
 * con SIGTERM, que es lo unico que se lleva la interfaz y el socket. No toca
 * ningun tunel configurado ni escribe config.xml.
 */

require_once('/usr/local/pkg/amneziawg/includes/awg_guiconfig.inc');

global $awgg;
awg_globals();

$iface = 'tun9097';
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

printf("\n=== el backend ===\n\n");

$backend = awg_backend_version(false);

printf("  awg          : %s\n", trim(shell_exec("{$awgg['awg']} --version 2>&1 | head -1")));
printf("  amneziawg-go : %s\n", trim(shell_exec("{$awgg['awg_go']} --version 2>&1 | head -1")));
printf("  detectado    : %s (%d)\n\n", $awgg['awg_versions'][$backend]['label'], $backend);

check('el backend llega a 3.1', $backend === 4, "devolvio {$backend}");
check('el techo es lo que el paquete sabe escribir',
	awg_version_ceiling(false) === min($backend, $awgg['awg_version_implemented']));

if ($backend < 4) {
	fwrite(STDERR, "\nEl backend no llega a 3.1; el resto no tiene sentido.\n");
	exit(1);
}

// Levantar el daemon de descarte
exec("{$awgg['awg_go']} {$iface} 2>&1", $out, $rc);

if ($rc !== 0) {
	fwrite(STDERR, "no arranco {$iface}: " . implode("\n", $out) . "\n");
	exit(2);
}

register_shutdown_function(function() use ($iface, $awgg) {
	exec("pkill -f '{$awgg['awg_go']} {$iface}' 2>/dev/null");
});

// El socket tarda ~100 ms en aparecer
for ($i = 0; ($i < 40) && !file_exists("{$awgg['run_path']}/{$iface}.sock"); $i++) {
	usleep(100000);
}

/*
 * Un tunel de nivel 3.1 completo, con los nueve campos nuevos puestos. Los
 * valores son de prueba: lo que se mide es que el backend los acepte, no que
 * sean una buena eleccion.
 */
$tunnel = array(
	'name'			=> $iface,
	'awgversion'		=> '4',
	'jc'			=> '4',
	'jmin'			=> '40',
	'jmax'			=> '70',
	's1'			=> '30',
	's2'			=> '40',
	's3'			=> '15',
	's4'			=> '16',
	'h1'			=> '1234567891',
	'h2'			=> '1234567892',
	'h3'			=> '1234567893',
	'h4'			=> '1234567894',
	'i1'			=> '<b 0x16030100><r 32>',
	'i2'			=> '', 'i3' => '', 'i4' => '', 'i5' => '',
	'headerprotectionkey'	=> awg_gen_header_protection_key(),
	'contentpaddingaddition'=> '12-40',
	'rekeyaftertime'	=> '110',
	'rekeytimeout'		=> '5',
	'rejectaftertime'	=> '180',
	'keepalivetimeout'	=> '10',
	'maxhandshakeattempts'	=> '18',
	'randomtrailers'	=> 'on',
	'disablecookies'	=> 'off');

printf("\n=== lo que el paquete escribe ===\n\n");

$pairs = awg_obfuscation_pairs($tunnel, 4);

foreach (array('HeaderProtectionKey', 'ContentPaddingAddition', 'RekeyAfterTime',
	       'RekeyTimeout', 'RejectAfterTime', 'KeepaliveTimeout',
	       'MaxHandshakeAttempts', 'RandomTrailers', 'DisableCookies') as $key) {
	check("escribe {$key}", isset($pairs[$key]), implode(',', array_keys($pairs)));
}

// El .conf, armado a mano con las mismas parejas que usa awg_make_tunnel_conf_file()
$conf = "[Interface]\nPrivateKey = " . base64_encode(random_bytes(32)) . "\nListenPort = 51897\n";

foreach ($pairs as $key => $value) {
	$conf .= "{$key} = {$value}\n";
}

$conf_path = "/tmp/{$iface}.conf";
file_put_contents($conf_path, $conf);

printf("\n=== lo que el backend acepta ===\n\n");

exec("{$awgg['awg']} setconf {$iface} {$conf_path} 2>&1", $set_out, $set_rc);

check('un tunel 3.1 completo se aplica', $set_rc === 0, implode(' | ', $set_out));

// Y lo que devuelve, que es la prueba de que no se comio nada en silencio
exec("{$awgg['awg']} showconf {$iface} 2>&1", $show_out, $show_rc);

$shown = implode("\n", $show_out);

check('showconf devuelve HeaderProtectionKey', strpos($shown, 'HeaderProtectionKey') !== false);
check('y la misma clave que se le puso',
	strpos($shown, $tunnel['headerprotectionkey']) !== false,
	'la clave volvio distinta');
check('devuelve ContentPaddingAddition como rango',
	strpos($shown, 'ContentPaddingAddition = 12-40') !== false, $shown);
check('devuelve los timings', strpos($shown, 'RekeyAfterTime = 110') !== false);
check('devuelve RandomTrailers en on', strpos($shown, 'RandomTrailers = on') !== false);
check('devuelve DisableCookies en off', strpos($shown, 'DisableCookies = off') !== false);
check('y no perdio lo de 2.0', (strpos($shown, 'S3 = 15') !== false) &&
				(strpos($shown, 'I1 = ') !== false));

printf("\n%s\n", $shown);

/*
 * La regla que ata la clave con el relleno: con HeaderProtectionKey puesta, los
 * cuatro S tienen que llegar a HeaderCipherNonceSize o mergeWithDevice() rechaza
 * el setconf entero. Se comprueba contra el backend de verdad para que la
 * validacion del paquete no se quede diciendo lo que ya no vale.
 */
$conf_sin_relleno = str_replace("S4 = {$tunnel['s4']}\n", "S4 = 0\n", $conf);
file_put_contents($conf_path, $conf_sin_relleno);

exec("{$awgg['awg']} setconf {$iface} {$conf_path} 2>&1", $bad_out, $bad_rc);

check('con S4 en cero y clave puesta, el backend lo rechaza',
	$bad_rc !== 0, 'lo acepto, y la validacion del paquete lo prohibe');

/*
 * Un tunel de nivel 2.0 contra el mismo backend: lo de 3.x no se escribe, y el
 * archivo tiene que seguir entrando igual. Es la garantia de que actualizar el
 * binario no obliga a tocar ningun tunel.
 */
$conf_2x = "[Interface]\nPrivateKey = " . base64_encode(random_bytes(32)) . "\nListenPort = 51897\n";

foreach (awg_obfuscation_pairs(array_merge($tunnel, array('awgversion' => '2')), 4) as $key => $value) {
	$conf_2x .= "{$key} = {$value}\n";
}

check('el .conf de un tunel 2.0 no lleva nada de 3.x',
	(strpos($conf_2x, 'HeaderProtection') === false) &&
	(strpos($conf_2x, 'RandomTrailers') === false));

file_put_contents($conf_path, $conf_2x);

exec("{$awgg['awg']} setconf {$iface} {$conf_path} 2>&1", $set2_out, $set2_rc);

check('y el backend 3.1 lo acepta igual', $set2_rc === 0, implode(' | ', $set2_out));

unlink($conf_path);

printf("\n%d pasaron, %d fallaron\n\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
