<?php
/*
 * verify-client-conf.php - corre EN EL FIREWALL, sobre el paquete instalado.
 *
 *   scp spike/verify-client-conf.php admin@FIREWALL:/root/
 *   ssh admin@FIREWALL 'php /root/verify-client-conf.php'
 *
 * Los tests de tools/test-client-conf.php corren en la maquina de desarrollo y
 * prueban la logica. Lo que no pueden probar es lo unico que decide si esto
 * sirve: que awg(8) acepte el archivo que se le entrega al cliente. Eso es lo
 * que se prueba aca, contra el binario real.
 *
 * El truco para probarlo sin levantar ningun tunel es el de la fase 3: awg(8)
 * parsea el archivo ENTERO antes de tocar la interfaz, asi que un setconf
 * contra una interfaz que no existe falla siempre, pero falla distinto --
 * "Configuration parsing error" si la sintaxis esta mal, "Device not
 * configured" si esta bien--.
 *
 * OJO: el .conf del cliente no se le puede dar a awg(8) tal cual. Address, DNS
 * y MTU son directivas de awg-quick y las rechaza. Por eso se sacan antes.
 *
 * No escribe config.xml ni crea interfaces.
 */

require_once('/usr/local/pkg/amneziawg/includes/awg_guiconfig.inc');
require_once('/usr/local/pkg/amneziawg/includes/awg_client.inc');

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

/*
 * Le pasa un .conf a awg(8) contra una interfaz inexistente y devuelve si lo
 * parseo bien. La interfaz no existe a proposito.
 */
function awg_parses($conf) {
	global $awgg;

	$tmp = tempnam('/tmp', 'awgclient');
	chmod($tmp, 0600);
	file_put_contents($tmp, $conf);

	exec("{$awgg['awg']} setconf awg-noexiste " . escapeshellarg($tmp) . " 2>&1", $out, $rc);
	unlink($tmp);

	$salida = implode(' ', $out);

	// Parseo bien si la queja es por la interfaz y no por el contenido.
	return array(stripos($salida, 'parsing error') === false, $salida);
}

/*
 * Deja el .conf del cliente en algo que awg(8) pueda leer. Dos cambios, los dos
 * por limitaciones del binario y no del archivo:
 *
 *   - Address, DNS y MTU son directivas de awg-quick. awg(8) rechaza el archivo
 *     entero por ellas.
 *   - El Endpoint se reemplaza por una IP literal porque awg(8) RESUELVE el
 *     hostname al hacer setconf, y si no resuelve reintenta con backoff durante
 *     ~90 segundos antes de rendirse... reportando "Configuration parsing
 *     error", que no tiene nada que ver con la causa. Con un hostname que no
 *     resuelve, este test tardaba minuto y medio y culpaba a la sintaxis.
 */
function para_awg($conf) {
	$conf = preg_replace('/^(Address|DNS|MTU)\s*=.*$\n?/mi', '', $conf);

	// 192.0.2.0/24 es la red de documentacion: no resuelve ni rutea a ningun lado.
	return preg_replace('/^Endpoint\s*=.*$/mi', 'Endpoint = 192.0.2.1:51820', $conf);
}

printf("\n=== paquete instalado ===\n\n");

printf("  awg:       %s\n", $awgg['awg']);
printf("  backend:   %s\n", awg_backend_supports_awg2() ? 'AWG 2.0' : 'AWG 1.x');

$awg2 = awg_backend_supports_awg2();

/*
 * Un tunel con los 16 campos cargados. Los que son de AWG 2.0 se ponen igual:
 * la gracia es comprobar que awg_obfuscation_pairs() los filtre segun el
 * backend, que es lo que decide si el tunel levanta o no.
 */
$tunnel = array(
	'name' => 'tun9000', 'descr' => 'prueba',
	'jc' => '4', 'jmin' => '40', 'jmax' => '70',
	's1' => '30', 's2' => '40', 's3' => '15', 's4' => '20',
	'h1' => '787134324-1593815189', 'h2' => '1234567892',
	'h3' => '1234567893', 'h4' => '1234567894',
	'i1' => '<b 0xf0f0f0f0>', 'i2' => '', 'i3' => '', 'i4' => '', 'i5' => '');

$cliente = awg_gen_keypair();
$servidor = awg_gen_keypair();

printf("\n=== el .conf del cliente ===\n\n");

$conf = awg_client_build_conf(array(
	'descr'			=> 'telefono de prueba',
	'privatekey'		=> $cliente['privkey_clamped'],
	'address'		=> '10.253.90.2/32',
	'dns'			=> '10.253.90.1',
	'mtu'			=> $awgg['default_mtu'],
	'publickey'		=> $servidor['pubkey'],
	'allowedips'		=> '0.0.0.0/0, ::/0',
	'endpoint'		=> 'vpn.example.com:51820',
	'persistentkeepalive'	=> 25,
	'obfuscation'		=> awg_obfuscation_pairs($tunnel)), $err);

check('se genera', $conf !== false, (string) $err);

if ($conf === false) {
	printf("\n%d pasaron, %d fallaron\n\n", $pass, $fail);
	exit(1);
}

print(preg_replace('/^/m', '      ', $conf));

printf("\n=== lo que dice awg(8) ===\n\n");

[$ok, $salida] = awg_parses(para_awg($conf));
check('awg(8) parsea el archivo del cliente', $ok, $salida);

// Control negativo: si esto tambien "pasa", el test no esta probando nada.
[$ok_malo, $salida_mala] = awg_parses(para_awg($conf) . "\nNoExiste = 1\n");
check('y rechaza uno con una clave inventada (control negativo)',
	!$ok_malo, $salida_mala);

// La ofuscacion de AWG 2.0 contra un backend 1.x es justo lo que rompe un
// tunel entero, porque awg(8) aborta el archivo completo.
$pairs = awg_obfuscation_pairs($tunnel);

if (!$awg2) {
	check('contra un backend 1.x el cliente no lleva S3/S4/I1',
		!isset($pairs['S3']) && !isset($pairs['S4']) && !isset($pairs['I1']),
		implode(',', array_keys($pairs)));

	[$ok_awg2, $salida_awg2] = awg_parses(para_awg($conf) . "\nS3 = 15\n");
	check('y awg(8) confirma que un S3 rompe el archivo entero',
		!$ok_awg2, $salida_awg2);
} else {
	check('contra un backend 2.0 el cliente si lleva S3/S4/I1',
		isset($pairs['S3'], $pairs['S4'], $pairs['I1']),
		implode(',', array_keys($pairs)));
}

check('el header viaja como rango, no truncado a entero',
	strpos($conf, 'H1 = 787134324-1593815189') !== false);

check('la clave publica del servidor va en [Peer]',
	strpos(substr($conf, strpos($conf, '[Peer]')), $servidor['pubkey']) !== false);

check('la privada del cliente no aparece en [Peer]',
	strpos(substr($conf, strpos($conf, '[Peer]')), $cliente['privkey_clamped']) === false);

printf("\n=== nombre de archivo ===\n\n");

printf("  'telefono de prueba' -> %s\n", awg_client_conf_filename('telefono de prueba'));

check('el nombre lo acepta un cliente de WireGuard',
	preg_match('/^[a-zA-Z0-9_=+.-]{1,15}\.conf$/', awg_client_conf_filename('telefono de prueba')) === 1);

/*
 * Todo lo que la pagina de peers le pide al tunel elegido. Se corre contra la
 * configuracion real, sin escribir nada, y con el caso que mas rompe: una
 * instalacion sin ningun tunel, que es como esta un paquete recien instalado.
 */
printf("\n=== lo que sale del tunel ===\n\n");

$tun_list = awg_get_tun_list();

printf("  tuneles configurados: %d%s\n", count($tun_list),
	count($tun_list) ? ' (' . implode(', ', array_keys($tun_list)) . ')' : '');

$hints = awg_client_tunnel_hints();

check('los hints se arman sin explotar', is_array($hints));
check('y hay uno por tunel', count($hints) === count($tun_list));

// El camino de la instalacion limpia: un tunel que no existe.
$vacios = awg_client_tunnel_defaults('tun-que-no-existe');

check('un tunel inexistente da defaults igual, sin fatal', is_array($vacios));
check('con la ofuscacion vacia', $vacios['obfuscation'] === array());
check('y el puerto por defecto del paquete', $vacios['port'] == $awgg['default_port'],
	(string) $vacios['port']);
check('sin direccion que sugerir', $vacios['address'] === null,
	var_export($vacios['address'], true));
check('las redes de un tunel inexistente son una lista vacia',
	awg_client_tunnel_networks('tun-que-no-existe') === array());
check('y su DNS es nulo', awg_client_tunnel_dns('tun-que-no-existe') === null);
check('su clave publica tambien', awg_client_tunnel_publickey('tun-que-no-existe') === null);
check('y su MTU', awg_client_tunnel_mtu('tun-que-no-existe') === null);
check('y no tiene ultimo cliente', awg_client_last_peer_store('tun-que-no-existe') === array());

// Las redes locales salen de las interfaces de verdad de este firewall.
$locales = awg_client_local_networks();

printf("  redes locales detectadas: %s\n",
	empty($locales) ? '(ninguna)' : implode(', ', $locales));

check('las redes locales son CIDR validos',
	count(array_filter($locales, fn($n) => is_subnet($n))) === count($locales),
	implode(', ', $locales));

// 'unassigned' encabeza la lista pero no es un tunel, es la opcion de dejar el
// peer suelto. Pedirle valores no tiene sentido.
foreach (array_diff_key($tun_list, array('unassigned' => '')) as $tun_name => $tun_descr) {
	$d = awg_client_tunnel_defaults($tun_name);

	printf("  %s: puerto=%s dns=%s mtu=%s routing=%s ofuscacion=%d campos\n",
		$tun_name, $d['port'], (string) $d['dns'], (string) $d['mtu'],
		$d['routing'], count($d['obfuscation']));

	check("{$tun_name}: el puerto es el del tunel",
		$d['port'] == awg_client_tunnel_port($tun_name));
	check("{$tun_name}: la ofuscacion sale del tunel y no esta vacia",
		count($d['obfuscation']) > 0, 'el tunel no tiene ningun campo cargado');
}

/*
 * Deteccion de endpoint y presets, contra la configuracion real. Casi todo esto
 * depende de lo que este firewall tenga configurado, asi que en vez de esperar
 * valores fijos se comprueba la FORMA: que lo que se ofrece en un desplegable
 * sea elegible, y que lo que se adivina sea algo a lo que un cliente pueda
 * discar.
 */
printf("\n=== endpoint y presets ===\n\n");

$candidatos = awg_client_endpoint_candidates();
$adivinado = awg_client_guess_endpoint();

printf("  candidatos detectados: %d\n", count($candidatos));

foreach ($candidatos as $valor => $etiqueta) {
	printf("    %s\n", $etiqueta);
}

printf("  adivinado: %s\n", var_export($adivinado, true));

check('los candidatos son un array', is_array($candidatos));

check('cada candidato es un host o una direccion valida',
	count(array_filter(array_keys($candidatos),
		fn($c) => is_ipaddr($c) || is_hostname($c))) === count($candidatos),
	implode(', ', array_keys($candidatos)));

check('lo adivinado es discable',
	is_null($adivinado) || is_ipaddr($adivinado) || is_hostname($adivinado),
	var_export($adivinado, true));

check('y si hay candidatos, adivina alguno',
	empty($candidatos) || !is_null($adivinado));

// Se cachea por request: la segunda respuesta tiene que ser la misma.
check('adivinar dos veces da lo mismo', awg_client_guess_endpoint() === $adivinado);

$dns = awg_client_dns_presets();

printf("  presets de DNS: %d\n", count($dns));

check('siempre hay presets de DNS', count($dns) > 0);
check('incluido el centinela de "ninguno"', array_key_exists(AWG_DNS_NONE, $dns));

$sin_centinela = array_diff_key($dns, array(AWG_DNS_NONE => ''));

$dns_validos = true;

foreach (array_keys($sin_centinela) as $servidores) {
	foreach (explode(',', $servidores) as $servidor) {
		if (!is_ipaddr(trim($servidor))) {
			$dns_validos = false;
		}
	}
}

check('y todos los demas son direcciones de verdad', $dns_validos,
	implode(' | ', array_keys($sin_centinela)));

$alias = awg_client_alias_presets();

printf("  alias ofrecidos: %d\n", count($alias));

$alias_validos = true;

foreach ($alias as $nombre => $info) {
	$e = array();

	awg_client_parse_addresses($info['networks'], $e);

	if (!empty($e) || empty($info['networks'])) {
		$alias_validos = false;
	}

	printf("    %s -> %s\n", $nombre, $info['networks']);
}

check('lo que sale de un alias son redes, no nombres', $alias_validos);

/*
 * El zip esta escrito a mano, sin ZipArchive, asi que no alcanza con que los
 * primeros bytes tengan pinta de zip: hay que dárselo a unzip(1) y ver que lo
 * abra y devuelva exactamente lo que se metio.
 */
printf("\n=== el zip ===\n\n");

$zip = awg_client_build_zip(array('telefono.conf' => $conf));

check('empieza con la firma de un zip', substr($zip, 0, 4) === "PK\x03\x04",
	bin2hex(substr($zip, 0, 4)));

check('el nombre del archivo pierde el .conf',
	awg_client_zip_filename('telefono.conf') === 'telefono.zip',
	awg_client_zip_filename('telefono.conf'));

$tmpzip = tempnam('/tmp', 'awgzip');
chmod($tmpzip, 0600);
file_put_contents($tmpzip, $zip);

exec('unzip -l ' . escapeshellarg($tmpzip) . ' 2>&1', $listado, $rc_list);

check('unzip(1) lo abre', $rc_list === 0, implode(' ', $listado));

check('y adentro esta el .conf con su nombre',
	count(preg_grep('/telefono\.conf/', $listado)) > 0, implode(' ', $listado));

exec('unzip -p ' . escapeshellarg($tmpzip) . ' telefono.conf 2>/dev/null', $extraido, $rc_cat);

check('el contenido sale byte por byte igual',
	implode("\n", $extraido) === rtrim($conf, "\n"),
	sprintf('%d lineas extraidas', count($extraido)));

unlink($tmpzip);

printf("\n%d pasaron, %d fallaron\n\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
