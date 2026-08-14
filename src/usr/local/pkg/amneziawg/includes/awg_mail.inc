<?php
/*
 * awg_mail.inc
 *
 * part of pfSense-pkg-AmneziaWG (https://github.com/MarceloMayo74/pfsense-amneziawg)
 * Copyright (c) 2026 Marcelo Mayo
 * All rights reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/*
 * Entrega por mail del archivo de configuracion del cliente.
 *
 * Es el tercer camino de salida del mismo archivo, al lado de la descarga y del
 * QR. No genera nada: recibe el .conf ya armado por awg_client.inc y solo se
 * ocupa de sacarlo del firewall.
 *
 * El SMTP no es propio: se usa el que ya esta configurado en
 * System > Advanced > Notifications. Un paquete de VPN no tiene por que pedir
 * de nuevo un servidor de correo que el firewall ya tiene, ni guardar una
 * segunda copia de esa contrasena.
 *
 * POR QUE PEAR Y NO PHPMailer. El camino obvio -- y el que usa wgeasy -- es
 * PHPMailer, que sabe adjuntar solo. Medido sobre pfSense 2.9.0-BETA:
 * PHPMailer NO ESTA. No esta la clase, no esta el archivo, no esta en ningun
 * lado del sistema. Lo que pfSense trae es PEAR Mail (/usr/local/share/pear),
 * que es lo que usa su propio send_smtp_message(). Un camino que no puede
 * correr no es un respaldo: es codigo muerto que hace creer que hay dos
 * opciones. Asi que se adjunta armando el MIME a mano sobre PEAR Mail, que es
 * el unico mailer que este sistema tiene.
 *
 * Quedan dos caminos igual, y el segundo existe por una razon distinta de la
 * que parece: el respaldo -- send_smtp_message(), que pega el archivo en el
 * cuerpo -- no cubre que el servidor este caido (si esta caido fallan los dos),
 * cubre que el MIME que armamos aca este mal. Es el unico camino que no depende
 * de codigo nuestro.
 */

require_once('config.inc');
require_once('util.inc');

// De aca salen awg_client_build_zip() y awg_client_zip_filename()
require_once('amneziawg/includes/awg_client.inc');

/*
 * Lee la configuracion de SMTP de System > Advanced > Notifications.
 *
 * Los nombres de las claves estan verificados contra
 * /usr/local/pfSense/include/www/system_advanced_notifications.inc, que es lo
 * que las escribe. Tres tienen trampa y las tres estan mal en wgeasy:
 *
 *   - la mecanica de autenticacion es 'authentication_mechanism', no
 *     'authentication_mech'. Leyendo la que no es, $auth queda en false y el
 *     mensaje sale sin autenticar: el servidor lo rechaza y el sintoma es "el
 *     mail no anda" sin ninguna pista de por que.
 *   - 'sslvalidate' es la cadena 'enabled'/'disabled', no un flag que se mira
 *     con isset(). Preguntando por isset() de una clave que nunca existe, la
 *     validacion del certificado queda SIEMPRE apagada -- mas debil que lo que
 *     el usuario configuro, y en silencio.
 *   - no hay ninguna clave 'tls'. La pagina ofrece SSL y nada mas.
 */
function awg_mail_smtp_settings() {
	$smtp = config_get_path('notifications/smtp', array());

	$settings = array(
			'disabled'	=> config_path_enabled('notifications/smtp', 'disable'),
			'host'		=> trim((string) ($smtp['ipaddress'] ?? '')),
			'port'		=> (int) ($smtp['port'] ?? 25),
			'timeout'	=> (int) ($smtp['timeout'] ?? 20),
			'ssl'		=> config_path_enabled('notifications/smtp', 'ssl'),
			'validate'	=> ((string) ($smtp['sslvalidate'] ?? 'enabled') != 'disabled'),
			'authmech'	=> (string) ($smtp['authentication_mechanism'] ?? ''),
			'username'	=> (string) ($smtp['username'] ?? ''),
			'password'	=> (string) ($smtp['password'] ?? ''),
			'from'		=> trim((string) ($smtp['fromaddress'] ?? '')),
			'notify'	=> trim((string) ($smtp['notifyemailaddress'] ?? '')));

	/*
	 * Un From vacio no es un detalle cosmetico: bastantes servidores rechazan
	 * el mensaje entero sin remitente. Se arma el mismo que arma pfSense.
	 */
	if (empty($settings['from'])) {
		$hostname = trim((string) config_get_path('system/hostname'));

		$domain = trim((string) config_get_path('system/domain'));

		$settings['from'] = "pfsense@{$hostname}.{$domain}";
	}

	if ($settings['port'] <= 0) {
		$settings['port'] = 25;
	}

	if ($settings['timeout'] <= 0) {
		$settings['timeout'] = 20;
	}

	return $settings;
}

/*
 * Codifica una cabecera que puede traer acentos, al estilo RFC 2047.
 *
 * Hace falta para el asunto, que lleva la descripcion del peer tal como la
 * escribio una persona: "Telefono de Marcelo" pasa derecho, pero "Teléfono" en
 * crudo es una cabecera invalida y el cliente de correo muestra cualquier cosa.
 * El ASCII se deja como esta para que el mensaje siga siendo legible en crudo.
 */
function awg_mail_encode_header($text) {
	$text = (string) $text;

	if (preg_match('/^[\x20-\x7E]*$/', $text) === 1) {
		return $text;
	}

	return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

/*
 * Arma el asunto y el cuerpo del mensaje.
 *
 * $attached cambia el texto porque cambia el mensaje: en un camino el archivo
 * viene adjunto y en el otro esta pegado abajo, y decir "adjunto" cuando no hay
 * adjunto manda al que lo recibe a buscar algo que no existe.
 *
 * Las instrucciones nombran la app de AmneziaWG y desmienten a la de WireGuard
 * a proposito: es el error que va a cometer cualquiera que ya use WireGuard, y
 * el sintoma -- la app rechaza el archivo sin decir por que -- no lleva a la
 * causa.
 */
function awg_mail_message($conf_filename, $descr = null, $attached = true) {
	$hostname = trim((string) config_get_path('system/hostname'));

	// Un salto de linea en el asunto es inyeccion de cabeceras, no un descuido
	$descr = trim(str_replace(array("\r", "\n"), ' ', (string) $descr));

	$subject = ($descr === '')
		? "AmneziaWG client configuration ({$hostname})"
		: "AmneziaWG client configuration: {$descr}";

	$where = $attached
		? 'is attached to this message, zipped.'
		: 'is included at the end of this message.';

	$lines = array(
			'Hello,',
			null,
			"The AmneziaWG client configuration \"{$conf_filename}\" generated on",
			"{$hostname} {$where}",
			null,
			'How to use it:',
			'  Android / iOS   : AmneziaWG app > + > Import from file or archive,',
			'                    or scan the QR code shown on the firewall',
			'  Windows / macOS : AmneziaWG > Add Tunnel > Import tunnel(s) from file',
			"  Linux           : save it as /etc/amnezia/amneziawg/{$conf_filename}",
			'                    and run awg-quick up',
			null,
			'The official WireGuard app will not work with this file. It does not know',
			'the obfuscation parameters, and making the traffic stop looking like',
			'WireGuard is the whole point of AmneziaWG.',
			null,
			'This file contains the private key of the client. Treat it as a secret and',
			'delete this message once the tunnel has been imported.',
			null);

	return array('subject' => $subject, 'body' => implode("\n", $lines));
}

/*
 * Arma un mensaje multipart con el texto y un archivo adjunto.
 *
 * Devuelve array('headers' => array(...), 'body' => '...') para pasarle a PEAR
 * Mail, que manda el cuerpo tal cual y arma las cabeceras a partir del array.
 * Aca no se manda nada: es todo texto, y por eso se puede probar entero.
 *
 * El texto va quoted-printable y no 8bit a proposito. 8bit necesita que el
 * servidor anuncie 8BITMIME, y si no lo hace el mensaje puede volver rebotado
 * o llegar con los acentos rotos; quoted-printable es ASCII puro y lo entiende
 * cualquiera.
 */
function awg_mail_multipart($body, $filename, $content, $mime) {
	$boundary = '=_awg_' . bin2hex(random_bytes(16));

	$headers = array(
		'MIME-Version'	=> '1.0',
		'Content-Type'	=> "multipart/mixed; boundary=\"{$boundary}\"");

	$parts = array(
		"--{$boundary}",
		'Content-Type: text/plain; charset=UTF-8',
		'Content-Transfer-Encoding: quoted-printable',
		'',
		quoted_printable_encode($body),
		"--{$boundary}",
		"Content-Type: {$mime}; name=\"{$filename}\"",
		'Content-Transfer-Encoding: base64',
		"Content-Disposition: attachment; filename=\"{$filename}\"",
		'',
		rtrim(chunk_split(base64_encode($content), 76, "\r\n")),
		"--{$boundary}--",
		'');

	return array('headers' => $headers, 'body' => implode("\r\n", $parts));
}

/*
 * Envia con PEAR Mail, adjuntando el archivo de verdad.
 *
 * Los parametros de conexion se arman igual que en send_smtp_message() de
 * /etc/inc/notices.inc, a proposito: si el servidor le funciona a las
 * notificaciones del firewall, tiene que funcionarle a esto.
 *
 * Devuelve false y carga $error si falla, para que el que llama pueda caer al
 * camino de respaldo.
 */
function awg_mail_send_attachment($to, $subject, $body, $filename, $content, $mime, &$error = null) {
	@require_once('Mail.php');

	if (!class_exists('Mail')) {
		$error = gettext('PEAR Mail is not available on this system.');

		return false;
	}

	$smtp = awg_mail_smtp_settings();

	if (empty($smtp['host'])) {
		$error = gettext('No SMTP server is configured.');

		return false;
	}

	/*
	 * Sin usuario o sin contrasena se manda sin autenticar. Mandar 'PLAIN' con
	 * un usuario vacio no es lo mismo: eso es un intento de login que falla.
	 */
	$auth = (empty($smtp['username']) || empty($smtp['password']))
		? false
		: (empty($smtp['authmech']) ? 'PLAIN' : $smtp['authmech']);

	$params = array(
		'host'		=> ($smtp['ssl'] ? 'ssl://' : '') . $smtp['host'],
		'port'		=> $smtp['port'],
		'auth'		=> $auth,
		'username'	=> $auth ? $smtp['username'] : '',
		'password'	=> $auth ? $smtp['password'] : '',
		'localhost'	=> trim((string) config_get_path('system/hostname')) . '.'
		                   . trim((string) config_get_path('system/domain')),
		'timeout'	=> $smtp['timeout'],
		'debug'		=> false,
		'persist'	=> false);

	if (!$smtp['validate']) {
		$params['socket_options'] = array('ssl' => array(
					'verify_peer'		=> false,
					'verify_peer_name'	=> false));
	}

	$message = awg_mail_multipart($body, $filename, $content, $mime);

	$headers = array_merge(array(
		'From'		=> $smtp['from'],
		'To'		=> $to,
		'Subject'	=> awg_mail_encode_header($subject),
		'Date'		=> date('r')), $message['headers']);

	try {
		$mailer =& Mail::factory('smtp', $params);

		if (!is_object($mailer) || (class_exists('PEAR') && PEAR::isError($mailer))) {
			$error = is_object($mailer) ? $mailer->getMessage() : gettext('Could not build the mailer.');

			return false;
		}

		$res = @$mailer->send($to, $headers, $message['body']);

		if (class_exists('PEAR') && PEAR::isError($res)) {
			$error = $res->getMessage();

			return false;
		}
	} catch (Throwable $e) {
		$error = $e->getMessage();

		return false;
	}

	return true;
}

/*
 * Envia con el send_smtp_message() nativo, que no adjunta: el archivo va en el
 * cuerpo, entre marcas, para que se pueda recortar y guardar.
 *
 * Este camino solo sirve para texto. El zip no puede ir por aca -- son bytes
 * binarios adentro de un cuerpo de mail -- asi que lo que viaja es el .conf
 * pelado, que es exactamente lo mismo que hay adentro del zip.
 *
 * Dos cosas de send_smtp_message() que no se ven leyendola por arriba:
 *
 *   - devuelve NULL cuando salio bien y la CADENA del error cuando fallo. Un
 *     "!== false" da verdadero para las dos, y entonces un envio que fallo se
 *     informa como exito.
 *   - se planta sin hacer nada si las notificaciones estan deshabilitadas,
 *     salvo que se la fuerce. Aca se la fuerza: esto no es una notificacion
 *     automatica, es alguien que apreto Send.
 *
 * Y siempre le escribe a la direccion configurada en Notifications, asi que el
 * destinatario se intercambia en la configuracion EN MEMORIA mientras dura la
 * llamada y se restaura despues. No se escribe config.xml.
 */
function awg_mail_send_inline($to, $subject, $body, $filename, $text, &$error = null) {
	if (!function_exists('send_smtp_message')) {
		@require_once('notices.inc');
	}

	if (!function_exists('send_smtp_message')) {
		$error = gettext('send_smtp_message() is not available on this system.');

		return false;
	}

	$lines = array(
			$body,
			null,
			"----- BEGIN {$filename} -----",
			$text,
			"----- END {$filename} -----",
			null);

	$message = implode("\n", $lines);

	$original = config_get_path('notifications/smtp/notifyemailaddress', '');

	config_set_path('notifications/smtp/notifyemailaddress', $to);

	try {
		$res = send_smtp_message($message, awg_mail_encode_header($subject), true);
	} catch (Throwable $e) {
		$error = $e->getMessage();

		$res = $e->getMessage();
	} finally {
		// Restaurar siempre, aunque haya salido por excepcion
		config_set_path('notifications/smtp/notifyemailaddress', $original);
	}

	if (!empty($res)) {
		$error = (string) $res;

		return false;
	}

	return true;
}

/*
 * Manda por mail la configuracion de un cliente.
 *
 * Se adjunta el mismo .zip que entrega el boton de descarga, no el .conf
 * pelado: son el mismo archivo por los dos caminos, y un comprimido sobrevive
 * a los filtros y renombrados de los que un .conf suelto no siempre vuelve.
 * Si no se puede adjuntar, el .conf va en el cuerpo del mensaje.
 *
 * Devuelve array('success', 'attached', 'message'); 'message' esta listo para
 * mostrar en la pagina.
 */
function awg_mail_send_client_conf($to, $client_conf, $conf_filename, $descr = null) {
	$to = trim((string) $to);

	if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
		return array('success' => false, 'attached' => false,
			'message' => gettext('A valid email address must be specified.'));
	}

	if (empty($client_conf)) {
		return array('success' => false, 'attached' => false,
			'message' => gettext('There is no client configuration to send.'));
	}

	/*
	 * Se chequea antes de intentar: sin servidor los dos caminos fallan, y el
	 * mensaje que dejarian -- "PEAR Mail no esta" -- manda a mirar el lugar
	 * equivocado.
	 */
	$smtp = awg_mail_smtp_settings();

	if (empty($smtp['host'])) {
		return array('success' => false, 'attached' => false,
			'message' => gettext('SMTP is not configured. Configure it under System > Advanced > Notifications.'));
	}

	$error = null;

	// El adjunto es lo preferido...
	$message = awg_mail_message($conf_filename, $descr, true);

	$zip = awg_client_build_zip(array($conf_filename => $client_conf));

	if (awg_mail_send_attachment($to, $message['subject'], $message['body'],
	                             awg_client_zip_filename($conf_filename), $zip,
	                             'application/zip', $error)) {
		return array('success' => true, 'attached' => true,
			'message' => sprintf(gettext('Configuration sent to %s as a zipped attachment.'), $to));
	}

	/*
	 * ...y el respaldo cubre que el MIME de arriba este mal, que es lo unico
	 * que este camino no comparte con el otro. Si el servidor no contesta van a
	 * fallar los dos, y la espera se paga dos veces.
	 */
	$attach_error = $error;

	$message = awg_mail_message($conf_filename, $descr, false);

	if (awg_mail_send_inline($to, $message['subject'], $message['body'],
	                         $conf_filename, $client_conf, $error)) {
		return array('success' => true, 'attached' => false,
			'message' => sprintf(gettext('Configuration sent to %s. The file was included in the message body because the attachment could not be sent.'), $to));
	}

	$hint = empty($error) ? $attach_error : $error;

	if (empty($hint)) {
		$hint = gettext('Verify the SMTP settings under System > Advanced > Notifications.');
	}

	return array('success' => false, 'attached' => false,
		'message' => sprintf(gettext('Unable to send the configuration to %1$s. %2$s'), $to, $hint));
}

?>
