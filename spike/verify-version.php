<?php
/*
 * verify-version.php - corre EN EL FIREWALL, contra el binario instalado.
 *
 *   scp spike/verify-version.php admin@FIREWALL:/root/
 *   ssh admin@FIREWALL 'php /root/verify-version.php'
 *
 * El selector de version del tunel descansa entero sobre una deteccion
 * empirica: awg_backend_version() le pregunta al awg que va adentro del .pkg
 * que claves entiende, porque 'awg --version' no sirve --ese numero lo escribe
 * el port de FreeBSD con un parche a version.h--.
 *
 * Los tests locales cubren el filtrado; lo que solo se puede medir aca es si la
 * deteccion le pega, y sobre todo si le pega POR LA RAZON CORRECTA. Una sonda
 * que devuelve 2 porque su clave de 3.0 esta mal escrita da el mismo resultado
 * que una que anda, hasta el dia que el backend se actualiza y sigue diciendo 2.
 *
 * No levanta ningun daemon, no crea interfaces y no escribe config.xml: todo
 * pasa por un setconf contra una interfaz que no existe.
 */

require_once('/usr/local/pkg/amneziawg/includes/awg_guiconfig.inc');

global $awgg;
awg_globals();

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

printf("\n=== la sonda del backend ===\n\n");

$version = awg_backend_version(false);

printf("  backend detectado: %s (%d)\n\n",
	$awgg['awg_versions'][$version]['label'], $version);

check('la deteccion devuelve un nivel que existe',
	isset($awgg['awg_versions'][$version]), (string) $version);

/*
 * Las dos mitades de la sonda, por separado. Si las dos dieran lo mismo, el
 * resultado no diria nada: lo que hace util a la deteccion es que sepa
 * distinguir.
 */
$knows_2 = awg_backend_knows_keys(array('S3 = 20', 'S4 = 25'));
$knows_3 = awg_backend_knows_keys(array('ContentPaddingAddition = 5'));

check('el backend acepta las claves de 2.0', $knows_2);

check('el nivel detectado se corresponde con lo que acepta',
	($version === 3) === $knows_3 && ($version >= 2) === $knows_2,
	"version={$version} 2.0={$knows_2} 3.0={$knows_3}");

/*
 * El control negativo: una clave inventada tiene que ser rechazada. Sin esto,
 * una sonda que aceptara cualquier cosa --por ejemplo si awg dejara de fallar
 * con una interfaz inexistente-- pasaria por un backend 3.0.
 */
check('una clave inventada se rechaza, asi que la sonda distingue de verdad',
	!awg_backend_knows_keys(array('NoExisteEstaClave = 1')));

printf("\n=== el techo y el cache ===\n\n");

$ceiling = awg_version_ceiling(false);

check('el techo no pasa lo que este paquete sabe escribir',
	$ceiling <= $awgg['awg_version_implemented'], (string) $ceiling);

check('el techo no pasa lo que entiende el backend',
	$ceiling <= $version, "techo={$ceiling} backend={$version}");

@unlink($awgg['awg_version_cache']);
awg_backend_version(false);

check('la sonda deja el nivel cacheado',
	file_exists($awgg['awg_version_cache']) &&
	trim(file_get_contents($awgg['awg_version_cache'])) === (string) $version,
	@file_get_contents($awgg['awg_version_cache']));

check('el cache se lee y da lo mismo', awg_backend_version(true) === $version);

/*
 * El cache viejo guardaba 0/1 para "soporta 2.0", donde 1 significaba lo
 * contrario que ahora. Comparten directorio a proposito, con nombres distintos.
 */
check('el cache nuevo no es el archivo del cache viejo',
	basename($awgg['awg_version_cache']) !== 'backend_version');

printf("\n=== lo que termina en el .conf ===\n\n");

$tunnel = array(
	'name' => 'tun9097', 'jc' => '4', 'jmin' => '40', 'jmax' => '70',
	's1' => '30', 's2' => '40', 's3' => '15', 's4' => '0',
	'h1' => '1234567891', 'h2' => '1234567892',
	'h3' => '1234567893', 'h4' => '1234567894',
	'i1' => '<b 0xf0f0f0f0>');

$as_1x = awg_obfuscation_pairs(array_merge($tunnel, array('awgversion' => '1')));
$as_2x = awg_obfuscation_pairs(array_merge($tunnel, array('awgversion' => '2')));

check('un tunel en 1.x no escribe S3/S4/I1',
	!isset($as_1x['S3'], $as_1x['S4'], $as_1x['I1']),
	implode(',', array_keys($as_1x)));

check('un tunel en 1.x si escribe Jc/S1/H1',
	isset($as_1x['Jc'], $as_1x['S1'], $as_1x['H1']));

if ($ceiling >= 2) {
	check('un tunel en 2.0 escribe S3 e I1',
		isset($as_2x['S3'], $as_2x['I1']),
		implode(',', array_keys($as_2x)));
}

/*
 * La prueba que importa: los dos niveles tienen que producir un archivo que
 * awg(8) parsee. Es lo unico que dice que el filtrado no arma un .conf roto.
 */
function parses_ok($pairs) {
	global $awgg;

	$conf = tempnam('/tmp', 'awgver');
	$lines = array('[Interface]',
		       'PrivateKey = ' . base64_encode(random_bytes(32)));

	foreach ($pairs as $k => $v) {
		$lines[] = "{$k} = {$v}";
	}

	file_put_contents($conf, implode("\n", $lines) . "\n");

	$res = awg_exec("{$awgg['awg']} setconf awgver" . getmypid() . ' ' .
			escapeshellarg($conf) . ' 2>&1');

	$out = implode("\n", (array) $res['output']);
	unlink($conf);

	// Igual que la sonda: lo unico que se mira es si el PARSEO paso.
	return (stripos($out, 'unrecognized') === false) &&
	       (stripos($out, 'parsing error') === false);
}

check('el .conf de un tunel en 1.x lo parsea awg(8)', parses_ok($as_1x));

if ($ceiling >= 2) {
	check('el .conf de un tunel en 2.0 lo parsea awg(8)', parses_ok($as_2x));
}

printf("\n%d pasaron, %d fallaron\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
