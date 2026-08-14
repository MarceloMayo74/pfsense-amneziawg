<?php
/*
 * test-mail.php - Tests del envio por mail del archivo de cliente.
 *
 *   .tools\php\php.exe tools\test-mail.php      (el php de wgeasy)
 *
 * Mismo mecanismo que los otros dos: las funciones se extraen del arbol src/ y
 * se evaluan, para probar el codigo que se publica y no una copia al lado.
 *
 * Lo que se prueba no es SMTP -- eso no se puede probar sin un servidor -- sino
 * tres cosas que si se pueden: como se leen las claves de la configuracion de
 * pfSense, el MIME que se arma a mano, y la decision de a quien se le entrega
 * el archivo y en que forma. Los dos envios reales se reemplazan por stubs que
 * anotan con que los llamaron.
 *
 * La propiedad que da nombre a la mitad de estos tests: el camino de respaldo
 * NO puede mandar el zip. Son bytes binarios pegados en el cuerpo de un mail;
 * lo que tiene que viajar por ahi es el .conf en texto. Confundirlos da un mail
 * que sale, que no falla, y que llega con basura adentro.
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

// Lo que fuera de pfSense no existe.
if (!function_exists('gettext')) {
	function gettext($s) { return $s; }
}

$test_config = array();

if (!function_exists('config_get_path')) {
	function config_get_path($path, $default = null) {
		global $test_config;

		if (array_key_exists($path, $test_config)) {
			return $test_config[$path];
		}

		return $default;
	}
	function config_set_path($path, $value) {
		global $test_config;

		$test_config[$path] = $value;
	}
	function config_path_enabled($path, $key = 'enable') {
		$section = config_get_path($path, array());

		return (is_array($section) && !empty($section[$key]));
	}
}

$test_config['system/hostname']	= 'pfSense';
$test_config['system/domain']	= 'home.arpa';

/*
 * Los dos envios de verdad, reemplazados. $sent_calls guarda con que los
 * llamaron y $send_result decide si "salieron"; asi se recorre cada rama sin
 * un servidor de correo.
 */
$sent_calls = array();
$send_result = array('attachment' => true, 'inline' => true, 'error' => null);

function awg_mail_send_attachment($to, $subject, $body, $filename, $content, $mime, &$error = null) {
	global $sent_calls, $send_result;

	$sent_calls[] = array('via' => 'attachment', 'to' => $to, 'subject' => $subject,
		'body' => $body, 'filename' => $filename, 'content' => $content, 'mime' => $mime);

	$error = $send_result['error'];

	return $send_result['attachment'];
}

function awg_mail_send_inline($to, $subject, $body, $filename, $text, &$error = null) {
	global $sent_calls, $send_result;

	$sent_calls[] = array('via' => 'inline', 'to' => $to, 'subject' => $subject,
		'body' => $body, 'filename' => $filename, 'content' => $text);

	$error = $send_result['error'];

	return $send_result['inline'];
}

eval(extract_function("{$src}/awg_mail.inc", 'awg_mail_smtp_settings'));
eval(extract_function("{$src}/awg_mail.inc", 'awg_mail_encode_header'));
eval(extract_function("{$src}/awg_mail.inc", 'awg_mail_message'));
eval(extract_function("{$src}/awg_mail.inc", 'awg_mail_multipart'));
eval(extract_function("{$src}/awg_mail.inc", 'awg_mail_send_client_conf'));
eval(extract_function("{$src}/awg_client.inc", 'awg_client_build_zip'));
eval(extract_function("{$src}/awg_client.inc", 'awg_client_zip_filename'));

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

// Vuelve a dejar el escenario en "todo anda"
function reset_sender($attachment = true, $inline = true, $error = null) {
	global $sent_calls, $send_result;

	$sent_calls = array();
	$send_result = array('attachment' => $attachment, 'inline' => $inline, 'error' => $error);
}

$conf = "[Interface]\nPrivateKey = " . str_repeat('A', 42) . "=\nAddress = 10.0.0.2/32\n\n[Peer]\nPublicKey = " . str_repeat('B', 42) . "=\n";

printf("\n-- awg_mail_smtp_settings(): las claves de pfSense --\n\n");

/*
 * Los nombres salen de system_advanced_notifications.inc, que es lo que las
 * escribe. Tres de estos tests existen porque wgeasy lee la clave que no es:
 * leyendo mal, el sintoma no es un error sino un mail que no sale, o que sale
 * con menos seguridad de la configurada.
 */
$test_config['notifications/smtp'] = array(
	'ipaddress'			=> '  smtp.example.com  ',
	'port'				=> '587',
	'timeout'			=> '30',
	'ssl'				=> true,
	'sslvalidate'			=> 'enabled',
	'authentication_mechanism'	=> 'LOGIN',
	'username'			=> 'vpn',
	'password'			=> 'secreto',
	'fromaddress'			=> 'vpn@example.com',
	'notifyemailaddress'		=> 'admin@example.com');

$smtp = awg_mail_smtp_settings();

check('el host se lee y se recorta',
	$smtp['host'] === 'smtp.example.com', var_export($smtp['host'], true));

check('el puerto queda entero',
	$smtp['port'] === 587);

check('el timeout tambien',
	$smtp['timeout'] === 30);

check('ssl prendido',
	$smtp['ssl'] === true);

check('la mecanica de autenticacion sale de authentication_mechanism',
	$smtp['authmech'] === 'LOGIN', var_export($smtp['authmech'], true));

check('from sale de la configuracion',
	$smtp['from'] === 'vpn@example.com');

/*
 * sslvalidate es la cadena 'enabled'/'disabled', no un flag. Preguntando por
 * isset() de una clave que nunca existe, la validacion del certificado queda
 * siempre apagada -- mas debil que lo configurado, y en silencio.
 */
check('con sslvalidate en enabled se valida el certificado',
	$smtp['validate'] === true);

$test_config['notifications/smtp']['sslvalidate'] = 'disabled';

check('y en disabled no',
	awg_mail_smtp_settings()['validate'] === false);

unset($test_config['notifications/smtp']['sslvalidate']);

check('sin la clave, se valida (que es lo que muestra la pagina)',
	awg_mail_smtp_settings()['validate'] === true);

$test_config['notifications/smtp']['disable'] = true;

check('el flag de deshabilitado se ve',
	awg_mail_smtp_settings()['disabled'] === true);

/*
 * El From vacio no es cosmetico: varios servidores rechazan el mensaje entero
 * sin remitente, y el sintoma seria "el mail no sale" sin nada mas.
 */
$test_config['notifications/smtp'] = array('ipaddress' => 'smtp.example.com');

$smtp = awg_mail_smtp_settings();

check('sin from se arma uno con el hostname',
	$smtp['from'] === 'pfsense@pfSense.home.arpa', var_export($smtp['from'], true));

check('sin puerto se asume 25',
	$smtp['port'] === 25);

check('sin timeout se asumen 20 s',
	$smtp['timeout'] === 20);

check('sin ssl configurado, no hay ssl',
	$smtp['ssl'] === false);

$test_config['notifications/smtp'] = array('ipaddress' => 'smtp.example.com', 'port' => '0');

check('un puerto en cero tambien cae en 25',
	awg_mail_smtp_settings()['port'] === 25);

unset($test_config['notifications/smtp']);

check('sin nada configurado el host queda vacio',
	awg_mail_smtp_settings()['host'] === '');

printf("\n-- awg_mail_encode_header() --\n\n");

check('el ASCII se deja como esta',
	awg_mail_encode_header('AmneziaWG client configuration: Telefono') === 'AmneziaWG client configuration: Telefono');

/*
 * "Telefono" con acento es exactamente lo que va a escribir cualquiera en
 * castellano. En crudo es una cabecera invalida y el cliente de correo muestra
 * cualquier cosa en el asunto.
 */
$encoded = awg_mail_encode_header("Teléfono de Marcelo");

check('lo que tiene acentos se codifica RFC 2047',
	strpos($encoded, '=?UTF-8?B?') === 0, $encoded);

check('y se puede volver atras',
	base64_decode(substr($encoded, 10, -2)) === "Teléfono de Marcelo");

printf("\n-- awg_mail_multipart() --\n\n");

$part = awg_mail_multipart("Hola\ncomo va", 'telefono.zip', "PK\x03\x04binario\x00\xff", 'application/zip');

check('declara MIME 1.0',
	$part['headers']['MIME-Version'] === '1.0');

check('el tipo es multipart/mixed con boundary',
	preg_match('/^multipart\/mixed; boundary="(=_awg_[0-9a-f]{32})"$/', $part['headers']['Content-Type'], $b) === 1,
	$part['headers']['Content-Type']);

$boundary = $b[1] ?? '';

check('el boundary abre y cierra el cuerpo',
	(strpos($part['body'], "--{$boundary}\r\n") === 0)
	&& (substr(rtrim($part['body'], "\r\n"), -strlen("--{$boundary}--")) === "--{$boundary}--"));

check('hay exactamente dos partes',
	substr_count($part['body'], "--{$boundary}") === 3);

check('el texto va quoted-printable',
	strpos($part['body'], 'Content-Transfer-Encoding: quoted-printable') !== false);

check('el adjunto va en base64, con su nombre y su tipo',
	(strpos($part['body'], 'Content-Type: application/zip; name="telefono.zip"') !== false)
	&& (strpos($part['body'], 'Content-Disposition: attachment; filename="telefono.zip"') !== false)
	&& (strpos($part['body'], 'Content-Transfer-Encoding: base64') !== false));

/*
 * La prueba que importa del multipart: los bytes del adjunto tienen que volver
 * identicos. Un zip que se codifica mal no falla en ningun lado hasta que
 * alguien lo intenta abrir.
 */
if (preg_match('/filename="telefono\.zip"\r\n\r\n(.*?)\r\n--/s', $part['body'], $m)) {
	check('el binario vuelve identico al decodificar',
		base64_decode(preg_replace('/\s+/', '', $m[1]), true) === "PK\x03\x04binario\x00\xff");
} else {
	check('el binario vuelve identico al decodificar', false, 'no se encontro la parte del adjunto');
}

check('dos llamadas no comparten boundary',
	awg_mail_multipart('a', 'b.zip', 'c', 'application/zip')['headers']['Content-Type']
	!== awg_mail_multipart('a', 'b.zip', 'c', 'application/zip')['headers']['Content-Type']);

/*
 * Las lineas de base64 tienen que estar cortadas: hay servidores que rechazan
 * lineas de mas de 1000 caracteres, y un zip en una sola linea las pasa.
 */
$largo = awg_mail_multipart('x', 'g.zip', random_bytes(4096), 'application/zip');

$lineas = explode("\r\n", $largo['body']);

check('ninguna linea pasa de 76 caracteres',
	max(array_map('strlen', $lineas)) <= 76,
	'la mas larga tiene ' . max(array_map('strlen', $lineas)));

printf("\n-- awg_mail_message() --\n\n");

$msg = awg_mail_message('telefono.conf', 'Telefono Marcelo', true);

check('el asunto lleva la descripcion',
	$msg['subject'] === 'AmneziaWG client configuration: Telefono Marcelo',
	var_export($msg['subject'], true));

check('sin descripcion, el asunto lleva el hostname',
	awg_mail_message('telefono.conf', '', true)['subject'] === 'AmneziaWG client configuration (pfSense)');

check('y null cuenta como sin descripcion',
	awg_mail_message('telefono.conf', null, true)['subject'] === 'AmneziaWG client configuration (pfSense)');

/*
 * Un salto de linea en el asunto es inyeccion de cabeceras: lo que sigue al
 * \n lo lee el servidor como una cabecera propia. La descripcion la escribe
 * una persona y llega sin filtrar hasta aca.
 */
$injected = awg_mail_message('x.conf', "Telefono\r\nBcc: otro@example.com", true);

check('un salto de linea en la descripcion no llega al asunto',
	(strpos($injected['subject'], "\n") === false)
	&& (strpos($injected['subject'], "\r") === false),
	var_export($injected['subject'], true));

check('el nombre del archivo esta en el cuerpo',
	strpos($msg['body'], 'telefono.conf') !== false);

/*
 * Decir "adjunto" cuando no hay adjunto manda al que lo recibe a buscar algo
 * que no existe, y al reves deja el archivo sin explicacion.
 */
check('con adjunto, el cuerpo dice que esta adjunto',
	(stripos($msg['body'], 'attached to this message') !== false)
	&& (stripos($msg['body'], 'included at the end') === false));

$inline_msg = awg_mail_message('telefono.conf', 'Telefono Marcelo', false);

check('sin adjunto, el cuerpo dice que esta al final',
	(stripos($inline_msg['body'], 'included at the end') !== false)
	&& (stripos($inline_msg['body'], 'attached to this message') === false));

check('el asunto no cambia entre los dos caminos',
	$inline_msg['subject'] === $msg['subject']);

/*
 * La app de WireGuard rechaza el archivo sin decir por que, y es la que va a
 * probar cualquiera que ya use WireGuard. El aviso tiene que estar en el mail,
 * porque el mail es lo unico que le llega al usuario final.
 */
check('el cuerpo nombra la app de AmneziaWG',
	stripos($msg['body'], 'AmneziaWG app') !== false);

check('y avisa que la de WireGuard no sirve',
	stripos($msg['body'], 'official WireGuard app will not work') !== false);

check('avisa que el archivo tiene la clave privada',
	stripos($msg['body'], 'private key') !== false);

printf("\n-- awg_mail_send_client_conf(): lo que no se manda --\n\n");

$test_config['notifications/smtp'] = array('ipaddress' => 'smtp.example.com');

reset_sender();

$res = awg_mail_send_client_conf('esto no es un mail', $conf, 'telefono.conf', 'Telefono');

check('una direccion invalida no se envia',
	($res['success'] === false) && empty($sent_calls));

reset_sender();

check('una direccion vacia tampoco',
	(awg_mail_send_client_conf('', $conf, 'telefono.conf')['success'] === false)
	&& empty($sent_calls));

reset_sender();

$res = awg_mail_send_client_conf('  admin@example.com  ', $conf, 'telefono.conf');

check('pero los espacios alrededor se recortan',
	($res['success'] === true) && ($sent_calls[0]['to'] === 'admin@example.com'));

reset_sender();

$res = awg_mail_send_client_conf('admin@example.com', '', 'telefono.conf');

check('sin archivo no se envia nada',
	($res['success'] === false) && empty($sent_calls));

/*
 * Sin servidor los dos caminos fallan igual, pero el mensaje que dejarian
 * -- "PEAR Mail no esta" -- manda a mirar el lugar equivocado.
 */
unset($test_config['notifications/smtp']);

reset_sender();

$res = awg_mail_send_client_conf('admin@example.com', $conf, 'telefono.conf');

check('sin SMTP configurado no se intenta enviar',
	($res['success'] === false) && empty($sent_calls));

check('y el mensaje apunta a Notifications',
	strpos($res['message'], 'System > Advanced > Notifications') !== false,
	$res['message']);

printf("\n-- awg_mail_send_client_conf(): el adjunto --\n\n");

$test_config['notifications/smtp'] = array('ipaddress' => 'smtp.example.com');

reset_sender();

$res = awg_mail_send_client_conf('admin@example.com', $conf, 'telefono.conf', 'Telefono Marcelo');

check('con todo en orden se envia una sola vez',
	($res['success'] === true) && (count($sent_calls) === 1));

check('por el camino del adjunto',
	($sent_calls[0]['via'] === 'attachment') && ($res['attached'] === true));

check('el adjunto es un zip',
	substr($sent_calls[0]['content'], 0, 4) === "PK\x03\x04",
	bin2hex(substr($sent_calls[0]['content'], 0, 4)));

check('con nombre .zip',
	$sent_calls[0]['filename'] === 'telefono.zip', $sent_calls[0]['filename']);

check('y el mime que le corresponde',
	$sent_calls[0]['mime'] === 'application/zip');

/*
 * El zip es el mismo que entrega el boton de descarga: adentro va el .conf con
 * su nombre, no el nombre del zip.
 */
check('adentro del zip esta el .conf, con su nombre',
	(strpos($sent_calls[0]['content'], 'telefono.conf') !== false)
	&& (strpos($sent_calls[0]['content'], $conf) !== false));

check('el mensaje dice a donde fue',
	strpos($res['message'], 'admin@example.com') !== false, $res['message']);

printf("\n-- awg_mail_send_client_conf(): el respaldo --\n\n");

reset_sender(false, true);

$res = awg_mail_send_client_conf('admin@example.com', $conf, 'telefono.conf', 'Telefono Marcelo');

check('si el adjunto falla se cae al camino nativo',
	($res['success'] === true) && (count($sent_calls) === 2)
	&& ($sent_calls[1]['via'] === 'inline'));

check('y se avisa que no fue adjunto',
	($res['attached'] === false)
	&& (stripos($res['message'], 'included in the message body') !== false),
	$res['message']);

/*
 * El punto entero de estos tests: por el cuerpo del mail viaja el texto, no el
 * zip. Un zip pegado en un cuerpo de mail no falla -- sale, llega, y adentro
 * hay basura.
 */
check('por el cuerpo viaja el .conf en texto, no el zip',
	$sent_calls[1]['content'] === $conf);

check('y con el nombre del .conf, no el del zip',
	$sent_calls[1]['filename'] === 'telefono.conf');

check('el cuerpo del respaldo no dice que hay un adjunto',
	stripos($sent_calls[1]['body'], 'attached to this message') === false);

check('mientras que el del intento anterior si lo decia',
	stripos($sent_calls[0]['body'], 'attached to this message') !== false);

printf("\n-- awg_mail_send_client_conf(): cuando no sale --\n\n");

reset_sender(false, false, 'SMTP connect() failed');

$res = awg_mail_send_client_conf('admin@example.com', $conf, 'telefono.conf');

check('si fallan los dos, falla el envio',
	($res['success'] === false) && (count($sent_calls) === 2));

/*
 * El error del servidor se muestra tal cual. Es feo, y es lo unico que
 * distingue "el puerto esta cerrado" de "la contrasena esta mal".
 */
check('y el error del servidor llega al mensaje',
	strpos($res['message'], 'SMTP connect() failed') !== false, $res['message']);

reset_sender(false, false, null);

$res = awg_mail_send_client_conf('admin@example.com', $conf, 'telefono.conf');

check('sin error del servidor queda la pista generica',
	strpos($res['message'], 'System > Advanced > Notifications') !== false, $res['message']);

printf("\n%d pasaron, %d fallaron\n\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
