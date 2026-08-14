<?php
/*
 * verify-peer-save.php - corre EN EL FIREWALL, sobre el paquete instalado.
 *
 *   scp spike/verify-peer-save.php admin@FIREWALL:/root/
 *   ssh admin@FIREWALL 'php /root/verify-peer-save.php'
 *
 * Prueba el alta de un peer de punta a punta por el mismo camino que usa la
 * pagina: awg_client_do_peer_post() con un post armado igual al del formulario.
 *
 * Existe por un bug que ningun test de logica podia ver. awg_peer_get_config()
 * calcula el indice de un peer nuevo como count() + 1, asi que para el primer
 * peer devuelve 1; al escribirse y releerse el XML -- que es una lista de
 * <item>, no un mapa -- el peer queda en 0. Guardar los datos del cliente
 * contra el indice 1 no encontraba nada y se perdian en silencio: el peer
 * quedaba bien y el cliente no existia. Solo se ve guardando de verdad.
 *
 * ESCRIBE config.xml. Hace backup antes y restaura al final, pase lo que pase.
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

// Backup antes de tocar nada. Es un firewall de verdad.
$backup = '/root/config-antes-de-verify-peer-save.xml';

if (!copy('/cf/conf/config.xml', $backup)) {
	fwrite(STDERR, "No se pudo respaldar config.xml, abortando\n");
	exit(2);
}

printf("\n  respaldo en %s\n", $backup);

$peers_antes = config_get_path('installedpackages/amneziawg/peers/item', []);

$restaurar = function() use ($backup, $peers_antes) {
	config_set_path('installedpackages/amneziawg/peers/item', $peers_antes);
	awg_write_config('verify-peer-save: restaurado el estado previo', false);
};

register_shutdown_function($restaurar);

$tun_list = array_diff_key(awg_get_tun_list(), array('unassigned' => ''));

if (empty($tun_list)) {
	fwrite(STDERR, "No hay ningun tunel configurado; no se puede probar el alta\n");
	exit(2);
}

$tun = (string) array_key_first($tun_list);

printf("  tunel de prueba: %s\n  peers antes: %d\n\n", $tun, count($peers_antes));

/*
 * El post, armado igual que el del formulario. La direccion se pide con el
 * mismo helper que usa el boton Suggest.
 */
$keypair = awg_gen_keypair();

$post = array(
	'index'			=> '',
	'act'			=> 'save',
	'enabled'		=> 'yes',
	'client_enable'		=> 'yes',
	'tun'			=> $tun,
	'descr'			=> 'verify-peer-save',
	'privatekey'		=> $keypair['privkey_clamped'],
	'presharedkey'		=> awg_gen_psk(),
	'address'		=> (string) awg_client_next_address($tun),
	'dns'			=> '10.9.9.1',
	'mtu'			=> '',
	'routing'		=> 'full',
	'client_allowedips'	=> '0.0.0.0/0, ::/0',
	'endpoint'		=> 'vpn.example.com',
	'port'			=> (string) awg_client_tunnel_port($tun),
	'persistentkeepalive'	=> '25');

printf("=== alta de un peer con cliente ===\n\n");

$res = awg_client_do_peer_post($post);

check('se guarda sin errores', empty($res['input_errors']),
	implode(' | ', (array) $res['input_errors']));

if (!empty($res['input_errors'])) {
	printf("\n%d pasaron, %d fallaron\n\n", $pass, $fail);
	exit(1);
}

$peers = config_get_path('installedpackages/amneziawg/peers/item', []);

check('hay un peer mas', count($peers) === count($peers_antes) + 1,
	count($peers) . ' vs ' . (count($peers_antes) + 1));

$idx = $res['pconfig']['index'];

printf("  indice devuelto: %s\n", var_export($idx, true));

check('el indice devuelto existe de verdad', is_array($peers[$idx] ?? null),
	'el peer no esta en ese indice: es el bug del count() + 1');

$peer = $peers[$idx] ?? array();

check('es el peer que se acaba de crear', ($peer['descr'] ?? '') === 'verify-peer-save');
check('con su clave publica derivada de la privada',
	($peer['publickey'] ?? '') === $keypair['pubkey']);
check('el endpoint quedo en el peer', ($peer['endpoint'] ?? '') === 'vpn.example.com');
check('el keepalive NO quedo en el peer', empty($peer['persistentkeepalive']));
check('la direccion quedo como AllowedIPs',
	count((array) ($peer['allowedips']['row'] ?? array())) === 1);

/*
 * Lo que se perdia en silencio.
 */
check('EL PEER ES EXPORTABLE', awg_client_is_exportable($peer),
	'se perdieron los datos del cliente, que es el bug que este archivo persigue');

$store = awg_client_store($peer);

check('guardo la clave privada del cliente',
	($store['privatekey'] ?? '') === $keypair['privkey_clamped']);
check('guardo el DNS elegido', ($store['dns'] ?? '') === '10.9.9.1');
check('guardo el keepalive del lado del cliente',
	($store['persistentkeepalive'] ?? '') === '25');

printf("\n=== el archivo del cliente ===\n\n");

$conf = awg_client_conf_from_peer($idx, $error);

check('se puede generar', $conf !== false, (string) $error);

if ($conf !== false) {
	check('lleva la clave privada del cliente',
		strpos($conf, $keypair['privkey_clamped']) !== false);
	check('lleva la clave publica del tunel',
		strpos($conf, (string) awg_client_tunnel_publickey($tun)) !== false);
	check('lleva el endpoint con su puerto',
		strpos($conf, 'Endpoint = vpn.example.com:' . awg_client_tunnel_port($tun)) !== false);
	check('y lleva la ofuscacion del tunel',
		strpos($conf, 'H1 = ') !== false);

	printf("%s\n", preg_replace('/^/m', '      ', $conf));
}

printf("\n%d pasaron, %d fallaron\n\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
