<?php
/*
 * awg_service.inc
 *
 * part of pfSense (https://www.pfsense.org)
 * Copyright (c) 2021 R. Christian McDonald (https://github.com/rcmcdonald91)
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


// pfSense includes
require_once('config.inc');
require_once('globals.inc');
require_once('gwlb.inc');
require_once('util.inc');
require_once('services.inc');
require_once('service-utils.inc');

// AmneziaWG includes
require_once('amneziawg/includes/awg.inc');

global $awgg;

if (isset($argv[1])) {
	$ret_code = 0;

	ignore_user_abort(true);

	set_time_limit(0);

	pcntl_async_signals(true);

	if (!awg_is_cli()) {
		// Bail out if we aren't in the CLI...
		die("FATAL: This script can only be started through the CLI.\n");
	}

	if (PHP_BINARY != $awgg['php_awg']) {
		// Bail out if we aren't running under under the correct executable...
		die("FATAL: This script can only be executed by {$awgg['php_awg']}.\n");
	}

	// Should we serialize the return output?
	$serialize = (isset($argv[2]) && strtolower($argv[2]) == 'serialize');

	switch (strtolower($argv[1])) {
		case 'start':
			$ret_code = awg_service_cli_start($serialize);
			break;

		case 'stop':
			$ret_code = awg_service_cli_stop($serialize);
			break;

		case 'restart':
			$ret_code = awg_service_cli_restart($serialize);
			break;

		case 'watchdog':
			$ret_code = awg_watchdog();
			break;

		default:
			fwrite(STDOUT, "Usage: {$awgg['awg_daemon']} {START|STOP|RESTART|WATCHDOG} [SERIALIZE]");
			$ret_code = 1;
			break;
	}

	// We are done...
	exit($ret_code);
}

// This is a wrapper for safely calling from web frontend
function awg_service_fpm_restart() {
	global $awgg;

	$exec_output = $unserialize_output = $ret_output = array();

	// Invokes service restart with serialization flag so we can cleanly report back to the web frontend.
	exec("{$awgg['awg_daemon']} restart serialize", $exec_output, $ret_code);

	if ($unserialize_output = unserialize($exec_output[0])) {
		if (is_array($unserialize_output)) {
			$ret_output = $unserialize_output;
		}
	}

	// Consumers expect an array...
	return $ret_output;
}

function awg_service_cli_restart($serialize = true) {
	global $awgg;

	$ret_code = 0;

	if (awg_is_service_running()) {
		$ret_code = awg_service_cli_stop($serialize);

		if ($ret_code <> 0) {
			return $ret_code;
		}
	}

	$ret_code = awg_service_cli_start($serialize);

	return $ret_code;
}

// This is a wrapper for safely calling from web frontend
function awg_service_fpm_stop() {
	global $awgg;

	$exec_output = $unserialize_output = $ret_output = array();

	// Invokes service stop with serialization flag, so we can cleanly report back to the web frontend.
	exec("{$awgg['awg_daemon']} stop serialize", $exec_output, $ret_code);

	if ($unserialize_output = unserialize($exec_output[0])) {
		if (is_array($unserialize_output)) {
			$ret_output = $unserialize_output;
		}
	}

	// Consumers expect an array...
	return $ret_output;
}

// Only for calling service stop from the CLI
function awg_service_cli_stop($serialize = true) {
	global $awgg;

	$ret_code = 0;

	if (!awg_is_cli()) {
		$ret_code |= AWG_ERROR_SVC_STOP;

		awg_service_parse_errors($ret_code, $serialize);

		// Terminate early...
		return $ret_code;
	}

	// Need to wait here for just a second...
	if (killbypid($awgg['pid_path'], 1) <> 0) {
		$ret_code |= AWG_ERROR_SVC_STOP;
		awg_service_parse_errors($ret_code, $serialize);
	}

    // Disable any AmneziaWG gateways configured on the system.
    awg_gateways_set_enable(false);

    /*
     * Y sacar las rutas de host que pusimos para los endpoints. Con el servicio
     * parado no hay tunel que las use, y dejarlas manda a un agujero todo el
     * trafico a esas direcciones -- incluido el intento de levantar el tunel de
     * nuevo si entretanto cambio el gateway.
     */
    awg_routes_flush();

    // Now we restart any additional services
    $ret_code |= awg_restart_extra_services();

	return $ret_code;
}

// This is a wrapper for safely calling from the web frontend
function awg_service_fpm_start() {
	global $awgg;

	$exec_output = $unserialize_output = $ret_output = array();

	// Invokes service start with serialization flag so we can cleanly report back to the web frontend
	exec("{$awgg['awg_daemon']} start serialize", $exec_output, $ret_code);

	// Catch unserialization results before returning
	if ($unserialize_output = unserialize($exec_output[0])) {
		if (is_array($unserialize_output)) {
			$ret_output = $unserialize_output;
		}
	}

	// Consumers expect an array...
	return $ret_output;
}

// Only for calling service start from the CLI
function awg_service_cli_start($serialize = true) {
	global $g, $awgg;

	$s = fn($x) => $x;

	// Set the process name. Es tambien como se lo reconoce despues: ver
	// awg_is_service_running()
	cli_set_process_title($awgg['service_title']);

	$ret_code = 0;

	if (!awg_is_service_enabled()) {
		$ret_code |= AWG_ERROR_SVC_DISABLED;

		awg_service_parse_errors($ret_code, $serialize);

		return $ret_code;
	}

	/*
	 * La segunda guarda de plataforma. La primera vive en awg_install() y evita
	 * que el paquete se cablee; esta evita que arranque igual si alguien lo
	 * cableo por otro camino -- una configuracion restaurada de otro firewall,
	 * un earlyshellcmd sobreviviente, un install forzado y despues revertido.
	 *
	 * Va despues del chequeo de habilitado y antes de cualquier otra cosa: un
	 * paquete que no deberia estar aca no levanta tuneles ni toca interfaces.
	 */
	if (!awg_platform_supported() && !awg_platform_forced()) {
		$p = awg_platform();

		log_error("AmneziaWG: refusing to start on {$p['label']} {$p['version']} (FreeBSD {$p['freebsd']}); " .
			  "this package is only tested on the versions listed in supported_versions. " .
			  "Create {$awgg['force_install_flag']} to override.");

		$ret_code |= AWG_ERROR_SVC_START;

		awg_service_parse_errors($ret_code, $serialize);

		return $ret_code;
	}

	if (awg_is_service_running()) {
		$ret_code |= AWG_ERROR_SVC_RUNNING;

		awg_service_parse_errors($ret_code, $serialize);

		return $ret_code;
	}

	if (!awg_is_cli()) {
		$ret_code |= AWG_ERROR_SVC_START;

		awg_service_parse_errors($ret_code, $serialize);

		return $ret_code;
	}

	// Register the service environment and lock early to ensure singletons
	awg_register_service_env(false);

	if (is_platform_booting()) {
		// Output during booting must be to STDERR for some reason
		fwrite(STDERR, gettext('Configuring AmneziaWG tunnels...'));

		// Supresses ifconfig spew
		mute_kernel_msgs();
	}

	// Get the tunnel interfaces to build
	$ifs_to_build = awg_get_configured_ifs();

	// Now build them...
	$sync_status = awg_tunnel_sync($ifs_to_build, false, false);

	if ($sync_status['ret_code'] <> 0 ) {
		$ret_code |= AWG_ERROR_SVC_CREATE;
	}

	if (is_platform_booting()) {
		unmute_kernel_msgs();

		fwrite(STDERR, "{$s(gettext('done.'))}\n");

		/*
		 * En el arranque la cosa termina aca: los tuneles quedan levantados y
		 * el demonio lo arranca rc.start_packages una vez terminado el boot.
		 * Este proceso esta por morirse, asi que se lleva su pidfile en lugar
		 * de dejarlo apuntando a un PID que ya no va a existir.
		 */
		awg_deregister_service_env();

		return $ret_code;
	}

	// Now, the initial fork...
	$newpid = pcntl_fork();

	if ($newpid === -1) {
		$ret_code |= AWG_ERROR_SVC_START;

		awg_destroy_tunnels();

		awg_service_parse_errors($ret_code, $serialize);

		return $ret_code;
	} elseif ($newpid) {
		awg_service_parse_errors($ret_code, $serialize);

		return $ret_code;
	}

	// Now become the session leader
	if (posix_setsid() < 0) {
		awg_destroy_tunnels();

		return 1;
	}

	// The second fork...
	$newpid = pcntl_fork();

	if ($newpid === -1) {
		awg_destroy_tunnels();

		return 1;
	} elseif ($newpid) {
		// Reap the child process below...
		pcntl_waitpid($newpid, $status);

		// Boilerplate if we want to trap service ret codes and halt...
		$ret_code = pcntl_wexitstatus($status);

		if ($ret_code <> 0) {
			return $ret_code;
		}

		// Move on to the actual daemon
		awg_service_daemon();

		// We shouldn't be here...
		return 0;
	} else {
        // Now we can enable any AmneziaWG gateways
        awg_gateways_set_enable(true);

		// Now we restart any additional services
		$ret_code = awg_restart_extra_services();

		return $ret_code;
	}

	// We shouldn't be here...
	return 1;
}

// This is where we restart any extra services
function awg_restart_extra_services($force = false) {
	if (is_platform_booting() && !$force) {
		return false;
	}

	// dpinger
	setup_gateways_monitor();

	// unbound
	services_unbound_configure();

	// reconfigure static routes
	system_staticroutes_configure();

	// TODO: This is where we will add facilities for users to pick what services to restart

	// 0 means success
	return 0;
}

// Main AmneziaWG service loop
function awg_service_daemon() {
	global $awgg;

	$esa = fn($s) => escapeshellarg($s);

	// Re-register the service environment
	awg_register_service_env(true);

	// Now that we are properly daemonized, register the service signal handlers
	awg_register_daemon_sig_handler();

	/*
	 * El paquete de WireGuard cargaba aca el modulo de kernel, para poder
	 * arrancar el servicio sin ningun tunel configurado. Sin modulo no hay
	 * nada que preparar: cada tunel trae su propio proceso cuando se levanta.
	 */

	// Now we resolve endpoint hostnames here...
	$last_update_time = awg_resolve_endpoints();

	// Main AmneziaWG service loop
	while (true) {
		/*
		 * Condicion de salida. El paquete de WireGuard preguntaba si el modulo
		 * seguia cargado: era su unico recurso global, y si desaparecia el
		 * daemon no tenia nada que hacer. Aca no hay recurso global, asi que
		 * la pregunta equivalente es si el paquete sigue habilitado.
		 *
		 * La salida normal igual es por SIGTERM, que maneja el signal handler
		 * registrado mas arriba; esto es la red de seguridad.
		 */
		if (!awg_is_service_enabled()) {
			break;
		}

		// Check if we should reresolve hostnames
		if (awg_should_reresolve_endpoints($last_update_time)) {
			// Reresolve endpoint hostnames again
			$last_update_time = awg_resolve_endpoints();
		}

		/*
		 * La rotacion de puertos lleva su propio reloj adentro y se la llama
		 * una vez por segundo. No cuelga del intervalo de arriba a proposito:
		 * ese resuelve DNS, y saltar cada 10 segundos no es motivo para
		 * consultar DNS cada 10 segundos.
		 */
		awg_hop_ports();

		// Wait a bit before trying again
		sleep(1);
	}

	// Execute SIGTERM handler to exit gracefully
	awg_daemon_sig_handler(SIGTERM);
}

/*
 * Watchdog: levanta los tuneles que se cayeron solos.
 *
 * Lo corre cron, no el daemon. Un tunel se cae por su cuenta -- el proceso
 * muere, o nunca llego a arrancar despues de un boot con algo todavia a medio
 * configurar -- y hasta ahora nadie se enteraba hasta que alguien abria la GUI
 * y tocaba Apply. Ni el paquete oficial de WireGuard ni el de referencia tienen
 * esto; el plugin de OPNsense si, y de ahi salen las ideas.
 *
 * Lo que NO hace, que es la mitad del diseño: no revive nada que se haya bajado
 * a proposito. En OPNsense eso se resuelve con flag files
 * (amneziawg_stopped.flag); aca no hacen falta, porque "a proposito" ya son dos
 * cosas que viven en la configuracion -- el servicio parado y el tunel en
 * enabled=no -- y las dos se consultan abajo. Un watchdog que pelea contra el
 * administrador es peor que no tenerlo.
 */
function awg_watchdog() {
	global $awgg;

	// El watchdog tiene que estar prendido explicitamente
	if (config_get_path('installedpackages/amneziawg/config/0/watchdog') != 'yes') {
		return 0;
	}

	// El paquete deshabilitado, o el servicio parado, son decisiones tomadas
	if (!awg_is_service_enabled() || !awg_is_service_running()) {
		return 0;
	}

	/*
	 * Un solo watchdog a la vez. Levantar un tunel puede tardar hasta cinco
	 * segundos esperando el socket, asi que con varios caidos una corrida se
	 * puede pasar del intervalo del cron y pisarse con la siguiente.
	 *
	 * El lock es el PID de la corrida en curso, no un flock sobre el archivo:
	 * el flock se lo llevaba el primer amneziawg-go que el watchdog levantara
	 * -- hereda el descriptor y no se muere nunca -- y a partir de ahi todas
	 * las corridas siguientes se encontraban el lock tomado y se volvian sin
	 * hacer nada. Ver awg_is_service_running().
	 */
	if (awg_pidfile_process_running($awgg['watchdog_lock'], $awgg['watchdog_pattern'])) {
		return 0;
	}

	safe_mkdir($awgg['run_path'], 0700);

	@file_put_contents($awgg['watchdog_lock'], getmypid());

	$state = awg_watchdog_state_get();

	$now = time();

	foreach (config_get_path('installedpackages/amneziawg/tunnels/item', []) as $tunnel) {
		$if_name = $tunnel['name'];

		// Un tunel deshabilitado no tiene que estar corriendo
		if (!isset($tunnel['enabled']) || ($tunnel['enabled'] != 'yes')) {
			unset($state[$if_name]);

			continue;
		}

		if (awg_proc_is_running($if_name)) {
			// Anda: se olvida cualquier historial de fallas
			unset($state[$if_name]);

			continue;
		}

		// Esta caido, pero puede estar en penitencia por no arrancar
		if (isset($state[$if_name]['retry_after']) && ($now < $state[$if_name]['retry_after'])) {
			continue;
		}

		awg_tunnel_sync_by_name($if_name);

		if (awg_proc_is_running($if_name)) {
			unset($state[$if_name]);

			log_error(sprintf(gettext('AmneziaWG watchdog: brought %s back up.'), $if_name));
		} else {
			$failures = ($state[$if_name]['failures'] ?? 0) + 1;

			$backoff = min($awgg['watchdog_backoff_min'] * (2 ** ($failures - 1)),
				       $awgg['watchdog_backoff_max']);

			$state[$if_name] = array('failures' => $failures, 'retry_after' => $now + $backoff);

			log_error(sprintf(gettext('AmneziaWG watchdog: %1$s is down and did not come back (attempt %2$d); retrying in %3$d s.'),
					  $if_name, $failures, $backoff));
		}
	}

	awg_watchdog_state_put($state);

	unlink_if_exists($awgg['watchdog_lock']);

	return 0;
}

function awg_watchdog_state_get() {
	global $awgg;

	if (!file_exists($awgg['watchdog_state'])) {
		return array();
	}

	$state = json_decode((string) @file_get_contents($awgg['watchdog_state']), true);

	return is_array($state) ? $state : array();
}

function awg_watchdog_state_put($state) {
	global $awgg;

	safe_mkdir($awgg['run_path'], 0700);

	if (empty($state)) {
		unlink_if_exists($awgg['watchdog_state']);

		return;
	}

	file_put_contents($awgg['watchdog_state'], json_encode($state));
}

/*
 * Instala o saca la entrada de cron segun este configurado el watchdog.
 *
 * install_cron_job() busca por substring del comando, asi que reinstalar con
 * otro intervalo actualiza la entrada existente en vez de duplicarla.
 */
function awg_watchdog_cron_sync() {
	global $awgg;

	/*
	 * Sin comando no se toca cron, y esto no es paranoia: install_cron_job()
	 * busca la entrada con strstr($item['command'], $command), y en PHP un
	 * needle vacio matchea cualquier cosa. Con $command vacio y $active false,
	 * borra el PRIMER cron del sistema. Paso de verdad -- se llevo
	 * /usr/sbin/newsyslog -- porque awg_deinstall() no declaraba global $awgg
	 * y pasaba null.
	 */
	if (empty($awgg['watchdog_cmd'])) {
		log_error(gettext('AmneziaWG: refusing to touch cron with an empty command.'));

		return false;
	}

	$enabled = (config_get_path('installedpackages/amneziawg/config/0/watchdog') == 'yes');

	$interval = (int) config_get_path('installedpackages/amneziawg/config/0/watchdog_interval',
					  $awgg['default_watchdog_interval']);

	if (($interval < 1) || ($interval > 60)) {
		$interval = $awgg['default_watchdog_interval'];
	}

	// Cada minuto es '*'; cada N es '*/N'. '*/1' tambien vale, pero '*' es lo
	// que un administrador espera leer en el crontab.
	$minute = ($interval == 1) ? '*' : "*/{$interval}";

	install_cron_job($awgg['watchdog_cmd'], $enabled, $minute, '*', '*', '*', '*');

	return true;
}

/*
 * Saca la entrada de cron, para el deinstall. Lleva el mismo guard que
 * awg_watchdog_cron_sync() y por el mismo motivo.
 */
function awg_watchdog_cron_deinstall() {
	global $awgg;

	if (empty($awgg['watchdog_cmd'])) {
		log_error(gettext('AmneziaWG: refusing to touch cron with an empty command.'));

		return false;
	}

	install_cron_job($awgg['watchdog_cmd'], false);

	return true;
}

function awg_deregister_service_env() {
	global $awgg;

	// Attempt to delete PID file
	unlink_if_exists($awgg['pid_path']);
}

/*
 * El pidfile se escribe y se cierra en el acto. Ningun descriptor queda abierto
 * a proposito: todo lo que este abierto cuando se levante un tunel se lo lleva
 * amneziawg-go, que vive mas que el servicio. Ver awg_is_service_running().
 */
function awg_register_service_env($close_fdio = false) {
	global $awgg;

	awg_deregister_service_env();

	@file_put_contents($awgg['pid_path'], getmypid());

	if ($close_fdio) {
		fclose(STDIN);
		fclose(STDOUT);
		fclose(STDERR);
	}
}

function awg_register_daemon_sig_handler() {
	pcntl_signal(SIGTERM, 'awg_daemon_sig_handler');
}

function awg_daemon_sig_handler($signo) {
	switch ($signo) {
		case SIGTERM:
			// Teardown any tunnels and unload the module
			awg_destroy_tunnels();

			// Cleanup the service environment
			awg_deregister_service_env();

			// We are done...
			exit(0);
			break;

		default:
			break;
	}
}

function awg_service_parse_errors($ret_code, $serialize_output = true, $extras = null) {
	global $awgg;

	$errors = array();

	// Collect any errors
	foreach ($awgg['error_flags']['service'] as $error_mask => $error_text) {
		if (($ret_code & $error_mask) > 0) {
			$errors[$error_mask] = $error_text;

			if (!$serialize_output) {
				// Send each error to STDERR as it is found
				fwrite(STDERR, "{$error_text}\n");
			}
		}
	}

	if ($serialize_output) {
		$res = array('ret_code' => $ret_code, 'errors' => $errors);

		$res = is_array($extras) ? array_merge($res, $extras) : $res;

		$res_serialized = serialize($res);

		fwrite(STDOUT, "{$res_serialized}\n");
	}

	return;
}

// Check if we are in CLI or not
function awg_is_cli() {
	return (PHP_SAPI === 'cli');
}

/*
 * Si el proceso anotado en un pidfile sigue vivo, y sigue siendo el que lo
 * escribio.
 *
 * El PID solo no alcanza: FreeBSD los recicla, y un pidfile que quedo colgado
 * puede terminar apuntando a un proceso ajeno. El patron -- un pedazo de la
 * linea de comandos con la que el proceso se registro -- es lo que separa
 * "todavia corre" de "ese numero ahora es de otro".
 */
function awg_pidfile_process_running($pid_path, $pattern) {
	$pid = trim((string) @file_get_contents($pid_path));

	if (!ctype_digit($pid)) {
		return false;
	}

	/*
	 * ps y no pgrep: pgrep se saltea a si mismo y a todos sus ancestros, y aca
	 * el que pregunta suele ser justamente el proceso por el que pregunta -- el
	 * servicio se busca a si mismo apenas arranca, antes de construir los
	 * tuneles. Con pgrep esa pregunta se contesta que no, siempre.
	 *
	 * -ww para que la linea no salga cortada: el patron del watchdog esta al
	 * final de una linea de comandos larga.
	 */
	exec('/bin/ps -ww -p ' . escapeshellarg($pid) . ' -o command= 2>/dev/null', $out, $ret_code);

	return (($ret_code == 0) && (strpos(implode('', $out), $pattern) !== false));
}

/*
 * Si el servicio esta corriendo. La respuesta sale del PID, no de un flock.
 *
 * El paquete de WireGuard contesta esto dejando el pidfile abierto con un flock
 * exclusivo puesto mientras el servicio vive: si el lock esta tomado, el
 * servicio corre. Eso vale mientras el servicio no arranque nada que le
 * sobreviva, y aca arranca uno por tunel. amneziawg-go hereda los descriptores
 * del proceso que lo lanza, y con el descriptor se lleva el flock; el proceso
 * del tunel queda vivo indefinidamente sosteniendo el lock del servicio que lo
 * creo. Despues de un arranque el pidfile contestaba "corriendo" para siempre,
 * el demonio no volvia a levantar ni a mano, y el que apagaba la GUI era el
 * chequeo de pfSense, que mira el proceso y veia la verdad: no habia ninguno.
 *
 * El PID no se hereda.
 */
function awg_is_service_running() {
	global $awgg;

	return awg_pidfile_process_running($awgg['pid_path'], $awgg['service_title']);
}

?>
