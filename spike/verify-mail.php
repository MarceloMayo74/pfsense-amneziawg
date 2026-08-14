<?php
/*
 * verify-mail.php - corre EN EL FIREWALL, sobre el paquete instalado.
 *
 *   scp spike/verify-mail.php admin@FIREWALL:/root/
 *   ssh admin@FIREWALL 'php /root/verify-mail.php'
 *
 * Los tests de tools/test-mail.php prueban la decision y el MIME con los dos
 * envios reemplazados por stubs. Lo que no pueden probar es con que mailer
 * cuenta el sistema, que es lo que decide el diseno entero: la primera version
 * de este archivo se escribio sobre PHPMailer -- como wgeasy -- y esta sonda
 * fue la que mostro que PHPMailer NO EXISTE en pfSense. Lo que hay es PEAR
 * Mail. Sin correr esto, el adjunto no habria funcionado nunca y el sintoma
 * habria sido "siempre llega pegado en el cuerpo", sin ningun error.
 *
 * Aca se prueba, contra las librerias de verdad: que PEAR Mail este, que arme
 * el mailer, que el MIME que armamos a mano sobreviva ida y vuelta, y que un
 * servidor que no contesta de un error en vez de una excepcion o un cuelgue.
 *
 * NO MANDA NINGUN MAIL: el unico envio que se intenta va contra 127.0.0.1:1,
 * que rechaza la conexion. No escribe config.xml.
 */

require_once('/usr/local/pkg/amneziawg/includes/awg_guiconfig.inc');
require_once('/usr/local/pkg/amneziawg/includes/awg_client.inc');
require_once('/usr/local/pkg/amneziawg/includes/awg_mail.inc');

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

function info($name, $value) {
	printf("  --   %-44s %s\n", $name, $value);
}

$conf = "[Interface]\nPrivateKey = " . str_repeat('A', 42) . "=\n"
	. "Address = 10.253.0.2/32\nDNS = 10.253.0.1\nMTU = 1420\n"
	. "Jc = 4\nJmin = 40\nJmax = 70\nS1 = 30\nS2 = 40\n"
	. "H1 = 787134324\nH2 = 1234567892\nH3 = 1234567893\nH4 = 1234567894\n\n"
	. "[Peer]\nPublicKey = " . str_repeat('B', 42) . "=\n"
	. "AllowedIPs = 0.0.0.0/0\nEndpoint = vpn.example.com:51820\n";

printf("\n-- el modulo carga en el firewall --\n\n");

foreach (array('awg_mail_smtp_settings', 'awg_mail_encode_header', 'awg_mail_message',
               'awg_mail_multipart', 'awg_mail_send_attachment', 'awg_mail_send_inline',
               'awg_mail_send_client_conf') as $fn) {
	check("{$fn}() existe", function_exists($fn));
}

printf("\n-- con que mailer cuenta este sistema --\n\n");

@require_once('Mail.php');

check('PEAR Mail esta disponible', class_exists('Mail'),
	'sin el no hay forma de adjuntar nada');

check('PEAR esta disponible', class_exists('PEAR'),
	'hace falta para distinguir un error de un exito');

if (!function_exists('send_smtp_message')) {
	@require_once('notices.inc');
}

check('send_smtp_message() esta disponible', function_exists('send_smtp_message'),
	'sin el no hay camino de respaldo');

/*
 * El hallazgo que dio vuelta el diseno, dejado como test para que se note si
 * alguna version futura de pfSense lo agrega: si esto pasa a fallar, PHPMailer
 * aparecio y conviene volver a mirar cual de los dos conviene.
 */
$phpmailer = class_exists('PHPMailer\\PHPMailer\\PHPMailer') || class_exists('PHPMailer');

check('PHPMailer sigue sin estar (por eso se usa PEAR)', !$phpmailer,
	'aparecio PHPMailer: reconsiderar el camino del adjunto');

printf("\n-- la configuracion de SMTP de este firewall --\n\n");

$smtp = awg_mail_smtp_settings();

info('host', ($smtp['host'] === '') ? '(sin configurar)' : $smtp['host']);
info('puerto', $smtp['port']);
info('timeout', $smtp['timeout'] . ' s');
info('ssl', $smtp['ssl'] ? 'si' : 'no');
info('valida el certificado', $smtp['validate'] ? 'si' : 'no');
info('autenticacion', ($smtp['authmech'] === '') ? '(ninguna)' : $smtp['authmech']);
info('from', $smtp['from']);
info('notificaciones a', ($smtp['notify'] === '') ? '(sin configurar)' : $smtp['notify']);
info('notificaciones deshabilitadas', $smtp['disabled'] ? 'si' : 'no');

check('el from nunca queda vacio', $smtp['from'] !== '',
	'varios servidores rechazan el mensaje entero sin remitente');

check('el puerto es valido', $smtp['port'] > 0);

check('el timeout es valido', $smtp['timeout'] > 0);

if ($smtp['host'] === '') {
	/*
	 * Sin servidor, el guard tiene que atajar antes de intentar: el mensaje
	 * que dejarian los envios manda a mirar el lugar equivocado.
	 */
	$res = awg_mail_send_client_conf('nadie@example.invalid', $conf, 'probe.conf', 'Probe');

	check('sin SMTP configurado, el envio se rechaza antes de intentar',
		$res['success'] === false);

	check('y el mensaje apunta a Notifications',
		strpos($res['message'], 'System > Advanced > Notifications') !== false,
		$res['message']);
}

printf("\n-- el MIME que se arma, contra las librerias de verdad --\n\n");

$zip = awg_client_build_zip(array('probe.conf' => $conf));

$msg = awg_mail_message('probe.conf', 'Probe Peer', true);

$part = awg_mail_multipart($msg['body'], 'probe.zip', $zip, 'application/zip');

info('tamano del cuerpo', strlen($part['body']) . ' bytes');

check('el adjunto va con su nombre y su tipo',
	(strpos($part['body'], 'name="probe.zip"') !== false)
	&& (strpos($part['body'], 'application/zip') !== false));

check('el cuerpo del mensaje sigue estando',
	strpos(quoted_printable_decode($part['body']), 'official WireGuard app will not work') !== false);

/*
 * La prueba que importa: el zip tiene que sobrevivir el base64 byte por byte, y
 * seguir siendo un zip que unzip(1) abre. Un adjunto mal codificado no falla en
 * ningun lado hasta que alguien lo intenta abrir del otro lado.
 */
if (preg_match('/filename="probe\.zip"\r\n\r\n(.*?)\r\n--/s', $part['body'], $m)) {
	$decoded = base64_decode(preg_replace('/\s+/', '', $m[1]), true);

	check('el zip vuelve identico despues de decodificarlo',
		($decoded !== false) && ($decoded === $zip),
		sprintf('%d bytes contra %d', strlen((string) $decoded), strlen($zip)));

	$tmp = tempnam('/tmp', 'awgmail');
	chmod($tmp, 0600);
	file_put_contents($tmp, (string) $decoded);

	exec('unzip -t ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);

	check('y unzip(1) lo abre despues del viaje', $rc === 0, implode(' ', $out));

	exec('unzip -p ' . escapeshellarg($tmp) . ' probe.conf 2>/dev/null', $extraido, $rc_cat);

	check('con el .conf adentro, igual al original',
		implode("\n", $extraido) === rtrim($conf, "\n"));

	unlink($tmp);
} else {
	check('el adjunto aparece en el MIME', false, 'no se encontro la parte del zip');
}

printf("\n-- el envio de verdad, contra un puerto que rechaza --\n\n");

/*
 * Se apunta a 127.0.0.1:1, que no escucha: la conexion se rechaza al instante.
 * Recorre awg_mail_send_attachment() entera -- PEAR Mail, el socket, el manejo
 * del error -- sin mandar ningun mail y sin esperar ningun timeout.
 *
 * Solo en memoria: nadie llama write_config() aca.
 */
$original = config_get_path('notifications/smtp', array());

config_set_path('notifications/smtp', array(
	'ipaddress'	=> '127.0.0.1',
	'port'		=> '1',
	'timeout'	=> '5',
	'fromaddress'	=> 'awg-probe@example.invalid'));

$error = null;

$t0 = microtime(true);

$sent = awg_mail_send_attachment('nadie@example.invalid', $msg['subject'], $msg['body'],
	'probe.zip', $zip, 'application/zip', $error);

$elapsed = microtime(true) - $t0;

config_set_path('notifications/smtp', $original);

check('un servidor que no contesta da false, no una excepcion',
	$sent === false);

check('y deja un error para mostrar', !empty($error), var_export($error, true));

if (!empty($error)) {
	info('lo que dijo PEAR', str_replace("\n", ' ', (string) $error));
}

check('sin colgarse esperando', $elapsed < 10,
	sprintf('tardo %.1f s', $elapsed));

check('la configuracion queda como estaba',
	config_get_path('notifications/smtp', array()) === $original);

printf("\n%d pasaron, %d fallaron\n\n", $pass, $fail);

exit($fail > 0 ? 1 : 0);
