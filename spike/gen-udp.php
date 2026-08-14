<?php
/*
 * gen-udp.php - generador de carga UDP para spike/throughput.sh
 *
 * Existe porque nc(1) no sirve para esto, por dos razones que solo se ven
 * midiendo:
 *
 *   1. nc se muere al primer error de escritura. Mandar contra un tunel en
 *      userspace llena la cola de la interfaz (net.link.ifqmaxlen = 128) y
 *      sendto devuelve ENOBUFS -- que no es una falla sino exactamente la
 *      senal de que el tunel esta saturado, o sea el punto del experimento.
 *      Con nc el generador moria a los ~150 paquetes y la ventana media cero.
 *
 *   2. nc arma los datagramas con lo que le entra del pipe, sin respetar el
 *      bs de dd, asi que el tamano de paquete quedaba a merced del buffer y
 *      de la fragmentacion IP. Aca el tamano es exacto.
 *
 * Uso:  php gen-udp.php <destino> <puerto> <bytes> <segundos>
 *
 * Con <bytes> = 1392 el paquete IP queda en 1392 + 20 + 8 = 1420, justo la MTU
 * del tunel: un datagrama, un paquete, sin fragmentar.
 *
 * Imprime una linea:  enviados=<n> errores=<n> segundos=<s> pps=<n> mbps=<n>
 */

if ($argc < 5) {
	fwrite(STDERR, "uso: php gen-udp.php <destino> <puerto> <bytes> <segundos>\n");
	exit(1);
}

$dst  = $argv[1];
$port = (int) $argv[2];
$size = (int) $argv[3];
$secs = (float) $argv[4];

$sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
if ($sock === false) {
	fwrite(STDERR, "socket_create: " . socket_strerror(socket_last_error()) . "\n");
	exit(1);
}
socket_set_option($sock, SOL_SOCKET, SO_SNDBUF, 1 << 20);

$buf   = str_repeat("\0", $size);
$start = microtime(true);
$end   = $start + $secs;
$sent  = 0;
$errs  = 0;

/*
 * El reloj se mira cada 256 envios: microtime() cuesta parecido a un sendto y
 * consultarlo en cada vuelta le comeria la mitad del caudal al generador.
 */
while (microtime(true) < $end) {
	for ($i = 0; $i < 256; $i++) {
		if (@socket_sendto($sock, $buf, $size, 0, $dst, $port) === false) {
			$errs++;
		} else {
			$sent++;
		}
	}
}

$el = microtime(true) - $start;
socket_close($sock);

printf("enviados=%d errores=%d segundos=%.2f pps=%.0f mbps=%.1f\n",
	$sent, $errs, $el, $sent / $el, $sent * $size * 8 / $el / 1e6);
