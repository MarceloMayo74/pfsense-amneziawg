<?php
/*
 * test-peer-post.php - adonde va el Endpoint al guardar un peer, sin firewall.
 *
 *   php tools/test-peer-post.php
 *
 * "Endpoint" nombra las dos puntas opuestas del mismo tunel, y esta pagina usa
 * un solo campo para las dos:
 *
 *   - Generando un cliente, es la direccion de ESTE firewall, la que el cliente
 *     disca. Va al archivo del cliente y NO al peer: guardarla en el peer hace
 *     que awg_resolve_endpoints() se la clave a la interfaz viva, y desde ahi el
 *     servidor le contesta el handshake a su propia WAN. El telefono se queda en
 *     "connecting" para siempre y no hay una linea de log que lo diga.
 *   - Sin generar, es la direccion del OTRO extremo, y tiene que llegar al peer:
 *     es lo unico que hace que este firewall marque para afuera.
 *
 * Lo que se prueba es a cual de los dos lados va cada uno. El bug que motivo el
 * test mandaba dynamic='yes' fijo, y awg_do_peer_post() hace unset() del
 * endpoint y del puerto cuando ve eso: un peer importado --que nace con el
 * endpoint del servidor remoto puesto-- los perdia al guardarlo desde la pagina
 * aunque no se hubiera tocado el campo. El tunel dejaba de discar, callado.
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

define('AWG_DNS_NONE', '__none__');

$awgg = array('default_port' => 51820);

/*
 * El entorno de pfSense, reducido a lo que toca esta funcion. Las validaciones
 * de forma son las de verdad en espiritu: lo que importa es que acepten lo
 * valido y rechacen lo que no, no como lo hace pfSense por dentro.
 */
function is_numericint($v) { return (is_int($v) || (is_string($v) && ctype_digit($v))); }
function is_port($v) { return (ctype_digit((string) $v) && ((int) $v > 0) && ((int) $v <= 65535)); }
function is_ipaddr($v) { return (bool) filter_var($v, FILTER_VALIDATE_IP); }
function is_ipaddrv6($v) { return (strpos((string) $v, ':') !== false); }
function is_hostname($v) { return (bool) preg_match('/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/i', (string) $v); }
function gen_subnet($addr, $mask) { return $addr; }

function awg_is_valid_key($key) {
	$raw = base64_decode((string) $key, true);

	return (($raw !== false) && (strlen($raw) === 32));
}

// Fuera de pfSense no se puede derivar de verdad; alcanza con que sea estable.
function awg_gen_publickey($privkey) {
	return array('privkey_clamped' => $privkey,
	             'pubkey' => base64_encode(substr(hash('sha256', $privkey, true), 0, 32)));
}

function awg_get_tun_list() { return array('unassigned' => '', 'tun9000' => 'tun9000'); }

// Los dos gateways del firewall de prueba: uno por defecto y uno que no lo es
function awg_gateway_list() {
	return array('' => 'Default', 'WAN_DHCP' => 'WAN_DHCP', 'WAN2_PPPOE' => 'WAN2_PPPOE');
}
function awg_tunnel_get_peers_config($tun) { return array(); }
function awg_peer_get_array_idx($pubkey, $tun) { return 0; }

function awg_client_parse_addresses($line, &$input_errors, $what) {
	$out = array();

	foreach (explode(',', (string) $line) as $item) {
		$item = trim($item);

		if ($item === '') {
			continue;
		}

		[$addr, $mask] = array_pad(explode('/', $item, 2), 2, '32');

		$out[] = array('address' => $addr, 'mask' => $mask);
	}

	return $out;
}

function awg_client_addresses_to_line($addresses) {
	$out = array();

	foreach ((array) $addresses as $a) {
		$out[] = "{$a['address']}/{$a['mask']}";
	}

	return implode(', ', $out);
}

function awg_client_addresses_to_post($addresses, $descr) {
	return array('address0' => $addresses[0]['address'] ?? '');
}

/*
 * Los dos espias. awg_do_peer_post() es exactamente el limite que interesa: el
 * bug no estaba en lo que se guardaba sino en lo que se le pasaba a el.
 */
$GLOBALS['peer_post_visto'] = null;
$GLOBALS['store_visto'] = null;

function awg_do_peer_post($post) {
	$GLOBALS['peer_post_visto'] = $post;

	/*
	 * La regla que hacia el dano, copiada de awg.inc: con dynamic='yes' el
	 * endpoint y el puerto se descartan antes de guardar.
	 */
	$guardado = $post;

	if (isset($post['dynamic']) && ($post['dynamic'] == 'yes')) {
		unset($guardado['endpoint'], $guardado['port']);
	}

	$GLOBALS['peer_guardado'] = $guardado;

	return array('input_errors' => array(), 'changes' => true,
	             'tuns_to_sync' => array($post['tun']), 'pconfig' => $post);
}

function awg_client_save_store($idx, $data) {
	$GLOBALS['store_visto'] = $data;

	return true;
}

/*
 * Lo guardado de antes. Vacio por defecto: cada corrida es un peer nuevo, que
 * es cuando se sortea la ofuscacion propia del cliente.
 */
$GLOBALS['store_previo'] = array();

function awg_client_store($peer) {
	return $GLOBALS['store_previo'];
}

function config_get_path($path, $default = null) { return $default; }

function awg_tunnel_get_config_by_name($name) {
	return array(0, $GLOBALS['tunnel'], false);
}

function awg_tunnel_version($tunnel, $ceiling = null) {
	return (int) ($tunnel['awgversion'] ?? 2);
}

// El sorteo de verdad vive en awg_api.inc; aca alcanza con que sea distinto
function awg_gen_junk_payload() {
	return sprintf('<b 0x%s><r %d>', bin2hex(random_bytes(3)), random_int(8, 64));
}

// El tunel al que pertenecen los peers de esta corrida
$GLOBALS['tunnel'] = array(
	'name'		=> 'tun9000',
	'awgversion'	=> '2',
	'i1'		=> '<b 0xdeadbeef><r 16>',
	'i2'		=> '<t><rc 8>');

eval(extract_function("{$src}/awg_client.inc", 'awg_client_gen_sender_obfuscation'));
eval(extract_function("{$src}/awg_client.inc", 'awg_client_do_peer_post'));

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

$clave_priv = base64_encode(str_repeat("\x01", 32));
$clave_pub  = base64_encode(str_repeat("\x02", 32));

function post_base($extra = array()) {
	return array_merge(array(
		'index'			=> '0',
		'enabled'		=> 'yes',
		'tun'			=> 'tun9000',
		'descr'			=> 'un peer',
		'address'		=> '10.0.0.2/32',
		'port'			=> '51820',
		'persistentkeepalive'	=> '25'), $extra);
}

function correr($post) {
	$GLOBALS['peer_post_visto'] = null;
	$GLOBALS['peer_guardado'] = null;
	$GLOBALS['store_visto'] = null;

	return awg_client_do_peer_post($post);
}

echo "=== sin generar cliente: el endpoint es del OTRO extremo y va al peer ===\n";

$res = correr(post_base(array(
	'publickey'	=> $clave_pub,
	'endpoint'	=> 'vpn.proveedor.com',
	'port'		=> '51820')));

check('se guarda sin errores', empty($res['input_errors']), implode(' | ', $res['input_errors']));
check('el peer NO va como dynamic',
      ($GLOBALS['peer_post_visto']['dynamic'] ?? null) === 'no',
      var_export($GLOBALS['peer_post_visto']['dynamic'] ?? null, true));
check('el endpoint llega al peer',
      ($GLOBALS['peer_post_visto']['endpoint'] ?? null) === 'vpn.proveedor.com',
      var_export($GLOBALS['peer_post_visto']['endpoint'] ?? null, true));
check('y el puerto tambien',
      ($GLOBALS['peer_post_visto']['port'] ?? null) === '51820');
check('el endpoint SOBREVIVE al unset de awg_do_peer_post -- el bug que motivo el test',
      isset($GLOBALS['peer_guardado']['endpoint']) && ($GLOBALS['peer_guardado']['endpoint'] === 'vpn.proveedor.com'),
      var_export($GLOBALS['peer_guardado']['endpoint'] ?? null, true));
check('el keepalive lo manda este firewall, asi que va al peer',
      ($GLOBALS['peer_post_visto']['persistentkeepalive'] ?? null) === '25',
      var_export($GLOBALS['peer_post_visto']['persistentkeepalive'] ?? null, true));
check('no se guarda nada de cliente', $GLOBALS['store_visto'] === array());

echo "\n=== sin generar y sin endpoint: sigue siendo un roadwarrior ===\n";

$res = correr(post_base(array(
	'publickey'	=> $clave_pub,
	'endpoint'	=> '',
	'port'		=> '')));

check('se guarda sin errores', empty($res['input_errors']), implode(' | ', $res['input_errors']));
check('vuelve a ser dynamic', ($GLOBALS['peer_post_visto']['dynamic'] ?? null) === 'yes');
check('y sin endpoint', ($GLOBALS['peer_post_visto']['endpoint'] ?? null) === '');

echo "\n=== generando cliente: el endpoint es de ESTE firewall y NO va al peer ===\n";

$res = correr(post_base(array(
	'client_enable'		=> 'yes',
	'privatekey'		=> $clave_priv,
	'endpoint'		=> 'mifirewall.dyndns.org',
	'port'			=> '51820',
	'client_allowedips'	=> '0.0.0.0/0',
	'routing'		=> 'full')));

check('se guarda sin errores', empty($res['input_errors']), implode(' | ', $res['input_errors']));
check('el peer va como dynamic', ($GLOBALS['peer_post_visto']['dynamic'] ?? null) === 'yes');
check('el endpoint NO se le guarda al peer',
      ($GLOBALS['peer_post_visto']['endpoint'] ?? null) === '',
      var_export($GLOBALS['peer_post_visto']['endpoint'] ?? null, true));
check('el keepalive tampoco: es cosa del cliente',
      ($GLOBALS['peer_post_visto']['persistentkeepalive'] ?? null) === null);
check('el endpoint va al archivo del cliente',
      ($GLOBALS['store_visto']['endpoint'] ?? null) === 'mifirewall.dyndns.org');
check('y el keepalive tambien',
      ($GLOBALS['store_visto']['persistentkeepalive'] ?? null) === '25');

echo "\n=== cada cliente lleva su propia ofuscacion de emisor ===\n";

/*
 * Jc, Jmin, Jmax y la cadena I1-I5 son de emisor: el otro extremo no los tiene
 * que hacer coincidir. Si diez clientes del mismo tunel emiten el mismo tren de
 * basura, eso ES una plantilla y el DPI la aprende de una.
 *
 * Lo 'shared' --S1-S4, H1-H4, la clave de headers-- no se toca: ahi un valor
 * distinto no es variedad, es un handshake que no cierra.
 */
function cliente_nuevo() {
	$GLOBALS['store_previo'] = array();

	correr(post_base(array(
		'client_enable'		=> 'yes',
		'privatekey'		=> base64_encode(random_bytes(32)),
		'endpoint'		=> 'mifirewall.dyndns.org',
		'port'			=> '51820',
		'client_allowedips'	=> '0.0.0.0/0')));

	return $GLOBALS['store_visto']['obfuscation'] ?? null;
}

$a = cliente_nuevo();

check('se le guarda ofuscacion propia', is_array($a) && !empty($a), json_encode($a));
check('con el tren de basura', isset($a['jc'], $a['jmin'], $a['jmax']), json_encode($a));
check('y jmin no pasa a jmax', (int) $a['jmin'] <= (int) $a['jmax'], json_encode($a));

// Los slots I que el tunel usa, y solo esos
check('sortea I1 e I2, que son los que el tunel usa', isset($a['i1'], $a['i2']), json_encode($a));
check('y no inventa I3, I4 ni I5 que el tunel dejo vacios',
      !isset($a['i3']) && !isset($a['i4']) && !isset($a['i5']), json_encode($a));
check('los suyos no son los del tunel',
      $a['i1'] !== $GLOBALS['tunnel']['i1'], $a['i1']);

// Nada compartido se toca aca
foreach (array('s1', 's2', 's3', 's4', 'h1', 'h2', 'h3', 'h4', 'headerprotectionkey') as $shared) {
	if (isset($a[$shared])) {
		$compartido_filtrado = true;
	}
}

check('no se le guarda nada compartido', !isset($compartido_filtrado), json_encode($a));

// Dos clientes del mismo tunel no comparten molde
$b = cliente_nuevo();
$c = cliente_nuevo();

check('dos clientes distintos reciben trenes distintos',
      json_encode($b) !== json_encode($c), json_encode($b) . ' vs ' . json_encode($c));

/*
 * Y una vez sorteada NO se vuelve a sortear: el archivo ya entregado dejaria de
 * ser el que muestra la pagina.
 */
$GLOBALS['store_previo'] = array('obfuscation' => array('jc' => '7', 'jmin' => '50', 'jmax' => '90'));

correr(post_base(array(
	'client_enable'		=> 'yes',
	'privatekey'		=> $clave_priv,
	'endpoint'		=> 'mifirewall.dyndns.org',
	'port'			=> '51820',
	'client_allowedips'	=> '0.0.0.0/0')));

check('editar un cliente existente conserva la suya',
      ($GLOBALS['store_visto']['obfuscation']['jc'] ?? null) === '7',
      json_encode($GLOBALS['store_visto']['obfuscation'] ?? null));

$GLOBALS['store_previo'] = array();

echo "\n=== el gateway, que solo existe discando ===\n";

$res = correr(post_base(array(
	'publickey'	=> $clave_pub,
	'endpoint'	=> 'vpn.proveedor.com',
	'port'		=> '51820',
	'gateway'	=> 'WAN2_PPPOE')));

check('se guarda sin errores', empty($res['input_errors']), implode(' | ', $res['input_errors']));
check('el gateway llega al peer',
      ($GLOBALS['peer_post_visto']['gateway'] ?? null) === 'WAN2_PPPOE',
      var_export($GLOBALS['peer_post_visto']['gateway'] ?? null, true));

// Sin endpoint no hay ruta que armar, asi que el gateway se va con el
$res = correr(post_base(array(
	'publickey'	=> $clave_pub,
	'endpoint'	=> '',
	'port'		=> '',
	'gateway'	=> 'WAN2_PPPOE')));

check('sin endpoint el gateway se descarta', ($GLOBALS['peer_post_visto']['gateway'] ?? null) === '');
check('y no da error', empty($res['input_errors']), implode(' | ', $res['input_errors']));

// Generando cliente el peer es roadwarrior: no disca, no hay gateway
$res = correr(post_base(array(
	'client_enable'		=> 'yes',
	'privatekey'		=> $clave_priv,
	'endpoint'		=> 'mifirewall.dyndns.org',
	'port'			=> '51820',
	'client_allowedips'	=> '0.0.0.0/0',
	'gateway'		=> 'WAN2_PPPOE')));

check('generando cliente tampoco se guarda gateway',
      ($GLOBALS['peer_post_visto']['gateway'] ?? null) === '',
      var_export($GLOBALS['peer_post_visto']['gateway'] ?? null, true));

$res = correr(post_base(array(
	'publickey'	=> $clave_pub,
	'endpoint'	=> 'vpn.proveedor.com',
	'port'		=> '51820',
	'gateway'	=> 'WAN9_QUE_NO_EXISTE')));

check('un gateway que no existe se rechaza', !empty($res['input_errors']));
check('y el error lo nombra', (bool) preg_grep('/WAN9_QUE_NO_EXISTE/', $res['input_errors']),
      implode(' | ', $res['input_errors']));

echo "\n=== lo que se niega a guardar ===\n";

$res = correr(post_base(array(
	'publickey'	=> $clave_pub,
	'endpoint'	=> 'vpn.proveedor.com',
	'port'		=> '')));

check('sin generar, un endpoint sin puerto se rechaza', !empty($res['input_errors']));
check('y el motivo dice que se pueden dejar los dos vacios',
      (bool) preg_grep('/dials in/', $res['input_errors']),
      implode(' | ', $res['input_errors']));

$res = correr(post_base(array(
	'publickey'	=> $clave_pub,
	'endpoint'	=> 'no es un host',
	'port'		=> '51820')));

check('sin generar, un endpoint con forma invalida se rechaza', !empty($res['input_errors']));

$res = correr(post_base(array(
	'client_enable'		=> 'yes',
	'privatekey'		=> $clave_priv,
	'endpoint'		=> '',
	'client_allowedips'	=> '0.0.0.0/0')));

check('generando, el endpoint sigue siendo obligatorio',
      (bool) preg_grep('/An endpoint must be specified/', $res['input_errors']),
      implode(' | ', $res['input_errors']));

$res = correr(post_base(array(
	'publickey'	=> $clave_pub,
	'endpoint'	=> 'vpn.proveedor.com',
	'port'		=> '99999')));

check('un puerto fuera de rango se rechaza en los dos modos', !empty($res['input_errors']));

printf("\n%d pasaron, %d fallaron\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
