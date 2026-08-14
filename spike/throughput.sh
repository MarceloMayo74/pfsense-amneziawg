#!/bin/sh
#
# throughput.sh - cuanto caudal mueve amneziawg-go en el hardware objetivo.
#
# Es el riesgo que quedo abierto en la fase 1: se midio latencia, nunca caudal.
# La pregunta concreta que contesta es "cuanto cuesta que el data plane este en
# userspace", asi que mide tres backends con el mismo instrumento:
#
#   wg-kernel     if_wg, cifrado adentro del kernel      <- la referencia
#   awg-plano     amneziawg-go, ofuscacion al minimo     <- el costo de userspace
#   awg-ofuscado  amneziawg-go, Jc/Jmin/Jmax/S1/S2       <- el costo de ofuscar
#
# ---------------------------------------------------------------------------
# Por que el montaje es asi y no el obvio
#
# El obvio -- dos tuneles en esta misma caja hablando entre si -- NO MIDE NADA.
# Con una sola tabla de ruteo, la direccion del otro extremo es local, y el
# kernel manda el trafico por lo0 sin que toque el tunel. (Vale la pena releer
# la fase 5 de spike/fase1-bringup.sh con esto en la mano: aquel ping entre
# 10.253.253.1 y .2 probablemente nunca paso por el tunel. No invalida la fase
# 1 -- lo que prueba que la cripto anda es el handshake, no el ping.)
#
# Lo segundo que no se puede: mandar contra un sumidero UDP tonto y llamarlo
# "solo cifrado". WireGuard no cifra un solo byte antes de completar handshake;
# encola y descarta. Hace falta un peer de verdad.
#
# Y con un peer de verdad aparece el bucle: B descifra, inyecta el paquete en su
# interfaz, el kernel lo rutea de nuevo hacia 10.253.254.0/24 -- que sale por el
# tunel de A -- y el paquete da vueltas hasta agotar el TTL, multiplicando la
# carga por 64.
#
# El montaje que sirve rompe el bucle adentro del proceso: a B se le declara el
# peer A con AllowedIPs que NO contienen la direccion origen. Entonces B recibe,
# descifra (paga el costo entero de la cripto) y descarta por cryptokey routing,
# sin escribir nunca en el tun. El bucle es estructuralmente imposible, no
# depende de ninguna regla de pf ni del estado de la interfaz.
#
#   dd|nc --UDP--> 10.253.254.5      (no existe en ningun lado: solo se rutea)
#            |
#            v  ruta -iface
#          tun9000  --cifra-->  UDP 127.0.0.1:51901
#                                      |
#                                      v
#                                   tun9001  --descifra--> descarta (AllowedIPs)
#
# Lo que sale de aca es entonces el costo de CIFRAR + DESCIFRAR en la misma
# caja. Un pfSense sirviendo clientes reales paga una sola de las dos mitades
# por paquete, asi que el numero es conservador; se informa la CPU de cada
# proceso por separado para poder separarlas.
#
# ---------------------------------------------------------------------------
# Cuidados, que esto corre sobre un firewall vivo
#
#   - no toca pf, ni config.xml, ni ninguna interfaz existente
#   - se planta si tun9000/tun9001/wg9000/wg9001 ya existen
#   - el generador va clavado a la CPU 0 con cpuset, para que no le robe
#     ciclos a lo que se esta midiendo
#   - teardown en trap: procesos, interfaces, rutas y sockets
#
# ---------------------------------------------------------------------------
# Por que la CPU se mide como se mide
#
# El tunel NO se clava a ninguna CPU. La tentacion es dejarle las CPU 1-3 con
# cpuset, pero eso solo se puede del lado de amneziawg-go: if_wg cifra en hilos
# del kernel que no se pueden clavar, asi que clavar un lado y el otro no es
# comparar dos cosas distintas. Queda entonces: generador clavado en la CPU 0,
# tunel libre, y la CPU del generador se mide aparte y se resta. Asi la columna
# que importa -- los cores que consume el tunel -- sale igual en los dos.
#
# Uso:   sh throughput.sh [-s segundos] [-r repeticiones]
#

set -u

SECS=8
REPS=3
GENS=1		# procesos generadores, todos sobre la misma CPU
ONLY=""		# correr un solo escenario
MTU=1420
WORK=/root/awg-throughput

AWG=/usr/local/bin/awg
AWGGO=/usr/local/bin/amneziawg-go
WG=/usr/bin/wg
GEN=$(dirname "$0")/gen-udp.php

# 1392 + 20 (IP) + 8 (UDP) = 1420 = MTU del tunel. Un datagrama, un paquete,
# sin fragmentar: asi el pps y el tamano medio que se informan son exactos.
PAYLOAD=1392

ADDR_TX=10.253.253.1
MASK=30
TARGET_NET=10.253.254.0/24
TARGET=10.253.254.5
TARGET_PORT=9999
PORT_TX=51900
PORT_RX=51901

# AllowedIPs del lado receptor: deliberadamente NO incluye a $ADDR_TX. Es lo
# que hace que B descarte despues de descifrar, y con eso no hay bucle posible.
RX_ALLOWED=10.99.99.0/24

while getopts "s:r:g:o:" _o 2>/dev/null; do
	case "$_o" in
		s) SECS=$OPTARG ;;
		r) REPS=$OPTARG ;;
		g) GENS=$OPTARG ;;
		o) ONLY=$OPTARG ;;
		*) echo "uso: $0 [-s segundos] [-r repeticiones] [-g generadores] [-o escenario]"
		   exit 1 ;;
	esac
done

say()  { printf '\n=== %s ===\n' "$*"; }
ok()   { printf '  [OK]    %s\n' "$*"; }
bad()  { printf '  [FALLA] %s\n' "$*"; }
info() { printf '  .       %s\n' "$*"; }

STATHZ=$(sysctl -n kern.clockrate | sed 's/.*stathz = //; s/[^0-9].*//')
NCPU=$(sysctl -n hw.ncpu)
RESULTS="${WORK}/resultados.txt"

CREADA_TX=""	# interfaces creadas, para el teardown
CREADA_RX=""
ROUTE_ADDED=no

# ---------------------------------------------------------------------------
# Teardown
# ---------------------------------------------------------------------------
stop_generators() {
	pkill -f "gen-udp.php" 2>/dev/null
	sleep 0.3
}

# Arranca $GENS generadores, todos clavados a la CPU 0. Cada uno escribe su
# resumen en <log>.<n>, que despues se suman.
start_generator() {
	_dur=$1; _log=$2
	_n=1
	while [ "$_n" -le "$GENS" ]; do
		cpuset -l 0 php "$GEN" "$TARGET" "$TARGET_PORT" "$PAYLOAD" "$_dur" \
			> "${_log}.${_n}" 2>&1 &
		_n=$((_n + 1))
	done
}

# Segundos de CPU de todos los generadores juntos.
gen_cpu() {
	_pids=$(pgrep -f "gen-udp.php" 2>/dev/null | tr '\n' ',' | sed 's/,$//')
	[ -n "$_pids" ] || { echo 0; return; }
	ps -o time= -p "$_pids" 2>/dev/null \
		| awk -F: '{ if (NF == 2) s += $1 * 60 + $2 } END { printf "%.2f", s + 0 }'
}

drop_route() {
	if [ "$ROUTE_ADDED" = yes ]; then
		route -q delete -net "$TARGET_NET" >/dev/null 2>&1
		ROUTE_ADDED=no
	fi
}

kill_iface() {
	_if=$1
	case "$_if" in
		tun*)	pkill -f "amneziawg-go ${_if}" 2>/dev/null
			sleep 0.4
			rm -f "/var/run/amneziawg/${_if}.sock" ;;
	esac
	ifconfig "$_if" >/dev/null 2>&1 && ifconfig "$_if" destroy 2>/dev/null
}

teardown_scenario() {
	stop_generators
	drop_route
	for _if in $CREADA_TX $CREADA_RX; do kill_iface "$_if"; done
	CREADA_TX=""; CREADA_RX=""
}

teardown() {
	say "Limpieza"
	teardown_scenario
	for _if in tun9000 tun9001 wg9000 wg9001; do
		if ifconfig "$_if" >/dev/null 2>&1; then
			bad "quedo $_if -- borrar a mano"
		fi
	done
	ok "sin interfaces, procesos ni rutas del test"
	info "archivos en $WORK (borrar con: rm -rf $WORK)"
}
trap teardown EXIT INT TERM

# ---------------------------------------------------------------------------
# Instrumentos
# ---------------------------------------------------------------------------
cpu_busy() { sysctl -n kern.cp_time | awk '{print $1+$2+$3+$4}'; }

# opkts obytes de una interfaz (la fila Link, que es la que trae los totales).
# Se cuenta desde la derecha: la columna Address viene vacia en algunas
# interfaces y ahi los indices desde la izquierda se corren solos.
if_out() {
	netstat -ibn -I "$1" 2>/dev/null | awk 'NR==2 {print $(NF-3), $(NF-1); exit}'
}

# bytes cifrados que el backend dice haber mandado / recibido
xfer() {
	_tool=$1; _if=$2
	"$_tool" show "$_if" transfer 2>/dev/null | awk 'NR==1 {print $2, $3; exit}'
}

# segundos de CPU consumidos por un proceso, de "MM:SS.ss" a segundos
proc_cpu() {
	_pid=${1:-}
	[ -n "$_pid" ] || { echo 0; return; }
	ps -o time= -p "$_pid" 2>/dev/null \
		| awk -F: '{ if (NF == 2) print $1 * 60 + $2; else print 0 }' \
		| head -1
}

pid_of() { pgrep -f "amneziawg-go $1" 2>/dev/null | head -1; }

# ---------------------------------------------------------------------------
# Montaje de un escenario
# ---------------------------------------------------------------------------
write_awg_conf() {
	_file=$1; _key=$2; _port=$3; _peerpub=$4; _peerport=$5; _allowed=$6; _obf=$7

	{
		echo "[Interface]"
		echo "PrivateKey = ${_key}"
		echo "ListenPort = ${_port}"
		[ -n "$_obf" ] && echo "$_obf"
		echo "[Peer]"
		echo "PublicKey = ${_peerpub}"
		echo "AllowedIPs = ${_allowed}"
		echo "Endpoint = 127.0.0.1:${_peerport}"
		# Sin esto no hay handshake: WireGuard solo lo inicia cuando tiene algo
		# que mandar, y aca no se manda nada hasta despues de esperarlo.
		echo "PersistentKeepalive = 5"
	} > "$_file"
}

start_awg_iface() {
	_if=$1

	daemon -p "${WORK}/${_if}.pid" "$AWGGO" "$_if" >> "${WORK}/${_if}.log" 2>&1

	_w=0
	while [ ! -e "/var/run/amneziawg/${_if}.sock" ] && [ "$_w" -lt 60 ]; do
		sleep 0.1; _w=$((_w + 1))
	done
	if [ ! -e "/var/run/amneziawg/${_if}.sock" ]; then
		bad "${_if}: no aparecio el socket UAPI"
		tail -5 "${WORK}/${_if}.log" 2>/dev/null | sed 's/^/          /'
		return 1
	fi
	return 0
}

# setup_awg <obfuscacion>  -> levanta tun9000 (emisor) y tun9001 (receptor)
setup_awg() {
	_obf=$1

	KEY_TX=$("$AWG" genkey); PUB_TX=$(echo "$KEY_TX" | "$AWG" pubkey)
	KEY_RX=$("$AWG" genkey); PUB_RX=$(echo "$KEY_RX" | "$AWG" pubkey)

	write_awg_conf "${WORK}/tun9000.conf" "$KEY_TX" "$PORT_TX" \
	               "$PUB_RX" "$PORT_RX" "$TARGET_NET" "$_obf"
	write_awg_conf "${WORK}/tun9001.conf" "$KEY_RX" "$PORT_RX" \
	               "$PUB_TX" "$PORT_TX" "$RX_ALLOWED" "$_obf"

	start_awg_iface tun9000 || return 1
	CREADA_TX="tun9000"
	start_awg_iface tun9001 || return 1
	CREADA_RX="tun9001"

	"$AWG" setconf tun9000 "${WORK}/tun9000.conf" || { bad "setconf tun9000"; return 1; }
	"$AWG" setconf tun9001 "${WORK}/tun9001.conf" || { bad "setconf tun9001"; return 1; }

	ifconfig tun9000 inet "${ADDR_TX}/${MASK}" mtu "$MTU" up || return 1
	# tun9001 va ARRIBA pero sin direccion. Arriba porque con la interfaz abajo
	# el backend cierra su socket UDP y no contesta el handshake -- probado: asi
	# fallaron los tres escenarios, kernel incluido. Sin direccion porque no
	# tiene que participar del ruteo. Lo que evita el bucle no es el estado de
	# la interfaz sino AllowedIPs, que descarta adentro del proceso.
	ifconfig tun9001 mtu "$MTU" up 2>/dev/null

	TOOL="$AWG"
	IF_TX=tun9000; IF_RX=tun9001
	return 0
}

setup_wg() {
	ifconfig wg9000 create 2>/dev/null || { bad "no se pudo crear wg9000"; return 1; }
	CREADA_TX="wg9000"
	ifconfig wg9001 create 2>/dev/null || { bad "no se pudo crear wg9001"; return 1; }
	CREADA_RX="wg9001"

	KEY_TX=$("$WG" genkey); PUB_TX=$(echo "$KEY_TX" | "$WG" pubkey)
	KEY_RX=$("$WG" genkey); PUB_RX=$(echo "$KEY_RX" | "$WG" pubkey)

	write_awg_conf "${WORK}/wg9000.conf" "$KEY_TX" "$PORT_TX" \
	               "$PUB_RX" "$PORT_RX" "$TARGET_NET" ""
	write_awg_conf "${WORK}/wg9001.conf" "$KEY_RX" "$PORT_RX" \
	               "$PUB_TX" "$PORT_TX" "$RX_ALLOWED" ""

	"$WG" setconf wg9000 "${WORK}/wg9000.conf" || { bad "wg setconf wg9000"; return 1; }
	"$WG" setconf wg9001 "${WORK}/wg9001.conf" || { bad "wg setconf wg9001"; return 1; }

	ifconfig wg9000 inet "${ADDR_TX}/${MASK}" mtu "$MTU" up || return 1
	ifconfig wg9001 mtu "$MTU" up 2>/dev/null

	TOOL="$WG"
	IF_TX=wg9000; IF_RX=wg9001
	return 0
}

add_route() {
	route -q add -net "$TARGET_NET" -iface "$IF_TX" >/dev/null 2>&1 \
		&& ROUTE_ADDED=yes && return 0
	bad "no se pudo rutear $TARGET_NET por $IF_TX"
	return 1
}

wait_handshake() {
	_w=0
	while [ "$_w" -lt 20 ]; do
		_hs=$("$TOOL" show "$IF_TX" latest-handshakes 2>/dev/null | awk '{print $2; exit}')
		if [ -n "${_hs:-}" ] && [ "${_hs:-0}" -gt 0 ] 2>/dev/null; then
			ok "handshake en ${_w}s"
			return 0
		fi
		sleep 1; _w=$((_w + 1))
	done
	bad "sin handshake en 20s"
	return 1
}

# ---------------------------------------------------------------------------
# Una medicion
# ---------------------------------------------------------------------------
run_window() {
	_label=$1; _rep=$2
	_genlog="${WORK}/gen-${_label}-${_rep}.txt"

	# El generador corre la ventana + 4s: 2 de calentamiento antes y 2 de cola
	# despues, para que el muestreo caiga entero en regimen permanente.
	start_generator "$((SECS + 4))" "$_genlog"
	sleep 2

	_pid_tx=$(pid_of "$IF_TX"); _pid_rx=$(pid_of "$IF_RX")

	set -- $(if_out "$IF_TX"); _p0=${1:-0}; _b0=${2:-0}
	set -- $(xfer "$TOOL" "$IF_TX"); _wtx0=${2:-0}
	set -- $(xfer "$TOOL" "$IF_RX"); _wrx0=${1:-0}
	_cpu0=$(cpu_busy)
	_cgen0=$(gen_cpu)
	_ctx0=$(proc_cpu "$_pid_tx"); _crx0=$(proc_cpu "$_pid_rx")
	_t0=$(date +%s.%N)

	sleep "$SECS"

	_t1=$(date +%s.%N)
	_ctx1=$(proc_cpu "$_pid_tx"); _crx1=$(proc_cpu "$_pid_rx")
	_cgen1=$(gen_cpu)
	_cpu1=$(cpu_busy)
	set -- $(xfer "$TOOL" "$IF_RX"); _wrx1=${1:-0}
	set -- $(xfer "$TOOL" "$IF_TX"); _wtx1=${2:-0}
	set -- $(if_out "$IF_TX"); _p1=${1:-0}; _b1=${2:-0}

	wait 2>/dev/null				# que el generador imprima su linea
	stop_generators

	# Los errores del generador son ENOBUFS: paquetes que el tunel no acepto.
	# Es la medida directa de que el enlace esta saturado, que es la condicion
	# bajo la cual el numero de arriba significa "capacidad" y no "lo que se
	# ofrecio".
	_gsent=$(awk -F'enviados=' 'NF > 1 { split($2, a, " "); s += a[1] } END { print s + 0 }' \
	         "${_genlog}".* 2>/dev/null)
	_gerr=$(awk -F'errores=' 'NF > 1 { split($2, a, " "); s += a[1] } END { print s + 0 }' \
	        "${_genlog}".* 2>/dev/null)

	awk -v lbl="$_label" -v rep="$_rep" \
	    -v p0="$_p0" -v p1="$_p1" -v b0="$_b0" -v b1="$_b1" \
	    -v wtx0="$_wtx0" -v wtx1="$_wtx1" -v wrx0="$_wrx0" -v wrx1="$_wrx1" \
	    -v c0="$_cpu0" -v c1="$_cpu1" -v hz="$STATHZ" \
	    -v cg0="$_cgen0" -v cg1="$_cgen1" \
	    -v ctx0="$_ctx0" -v ctx1="$_ctx1" -v crx0="$_crx0" -v crx1="$_crx1" \
	    -v gs="${_gsent:-0}" -v ge="${_gerr:-0}" -v pay="$PAYLOAD" \
	    -v t0="$_t0" -v t1="$_t1" '
	BEGIN {
		el = t1 - t0; if (el <= 0) el = 1
		pk = p1 - p0; by = b1 - b0
		wtx = wtx1 - wtx0; wrx = wrx1 - wrx0

		# El caudal sale de CONTAR PAQUETES por el payload exacto, no de los
		# bytes que informa la interfaz: wg(4) cuenta 1452 B por paquete y el
		# tun cuenta 1424 para el mismo trafico, o sea que comparar por bytes
		# le regalaria un 2% al kernel que es contabilidad y no rendimiento.
		mbps  = pk * pay * 8 / el / 1000000
		pps   = pk / el
		avg   = (pk > 0) ? by / pk : 0
		over  = (by > 0) ? wtx / by : 0
		# Los cores del tunel son los de la caja menos los del generador, que
		# es lo unico que hace comparables al kernel y a userspace.
		cores = (c1 - c0) / hz / el - (cg1 - cg0) / el
		if (cores < 0) cores = 0
		ctx   = (ctx1 - ctx0) / el
		crx   = (crx1 - crx0) / el
		perc  = (cores > 0) ? mbps / cores : 0
		dec   = (wtx > 0) ? wrx / wtx * 100 : 0
		loss  = (gs + ge > 0) ? ge / (gs + ge) * 100 : 0
		# El generador esta clavado a una sola CPU: si esto da ~1.00 el cuello
		# de botella es el, y el caudal de al lado es un piso, no un techo.
		gen   = (cg1 - cg0) / el

		printf "  %-14s #%d  %8.1f Mbps  %8.0f pps  %6.0f B/pq  x%.2f cable  %5.2f cores  %7.1f Mbps/core  %5.1f%% desc  %5.1f%% rechazado  gen %4.2f\n", \
		       lbl, rep, mbps, pps, avg, over, cores, perc, dec, loss, gen
		printf "%s %.1f %.0f %.0f %.3f %.2f %.2f %.2f %.1f %.1f %.2f\n", \
		       lbl, mbps, pps, avg, over, cores, ctx, crx, dec, loss, gen >> "'"$RESULTS"'"
	}'
}

scenario() {
	_label=$1; _kind=$2; _obf=$3

	[ -z "$ONLY" ] || [ "$ONLY" = "$_label" ] || return 0

	say "$_label"

	case "$_kind" in
		wg)  setup_wg      || { bad "montaje fallido"; teardown_scenario; return 1; } ;;
		awg) setup_awg "$_obf" || { bad "montaje fallido"; teardown_scenario; return 1; } ;;
	esac

	add_route || { teardown_scenario; return 1; }
	wait_handshake || { teardown_scenario; return 1; }

	# Gate de validez: si el trafico no entra al tunel, no hay numero que dar.
	set -- $(if_out "$IF_TX"); _g0=${1:-0}
	start_generator 1 "${WORK}/gen-gate.txt"
	sleep 2
	stop_generators
	set -- $(if_out "$IF_TX"); _g1=${1:-0}

	if [ "$((_g1 - _g0))" -lt 100 ]; then
		bad "no entro trafico a $IF_TX (${_g0} -> ${_g1} paquetes)"
		info "probable filtrado de pf en una interfaz sin asignar; revisar:"
		info "  tcpdump -n -i pflog0"
		teardown_scenario
		return 1
	fi
	ok "el trafico entra al tunel: $((_g1 - _g0)) paquetes en la prueba de 1s"

	# Guard de bucle. El diseno dice que el receptor descarta por AllowedIPs y
	# que por lo tanto ningun paquete vuelve a entrar al tunel. Eso se mide, no
	# se supone: con el generador ya parado, el contador tiene que estar quieto.
	# Si sigue subiendo hay realimentacion y todo lo que salga de aca es basura.
	sleep 2
	set -- $(if_out "$IF_TX"); _g2=${1:-0}
	if [ "$((_g2 - _g1))" -gt 100 ]; then
		bad "BUCLE: $IF_TX sigue con trafico sin generador ($((_g2 - _g1)) paquetes en 2s)"
		teardown_scenario
		return 1
	fi
	ok "sin realimentacion: $((_g2 - _g1)) paquetes con el generador parado"

	_r=1
	while [ "$_r" -le "$REPS" ]; do
		run_window "$_label" "$_r"
		_r=$((_r + 1))
		sleep 1
	done

	info "$("$TOOL" show "$IF_TX" transfer 2>/dev/null | head -1)"
	teardown_scenario
	sleep 1
}

# ---------------------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------------------
say "Entorno"

[ "$(id -u)" = 0 ] || { bad "hay que correr como root"; exit 1; }

for _if in tun9000 tun9001 wg9000 wg9001; do
	if ifconfig "$_if" >/dev/null 2>&1; then
		bad "$_if ya existe; abortando para no pisar nada"
		exit 1
	fi
done

for _b in "$AWG" "$AWGGO" "$WG"; do
	[ -x "$_b" ] || { bad "falta $_b"; exit 1; }
done
command -v cpuset >/dev/null 2>&1 || { bad "falta cpuset(1)"; exit 1; }
command -v php >/dev/null 2>&1 || { bad "falta php"; exit 1; }
[ -r "$GEN" ] || { bad "falta el generador $GEN"; exit 1; }

# Una ruta preexistente hacia el destino significaria mandar trafico a un
# tercero. Con una /24 de prueba no deberia pasar nunca, pero se chequea.
if netstat -rn | grep -q "^10\.253\.254"; then
	bad "ya hay una ruta hacia 10.253.254.0/24; abortando"
	exit 1
fi

info "pfSense $(cat /etc/version 2>/dev/null)  $(uname -sr)"
info "$(sysctl -n hw.model)  ${NCPU} cores  stathz=${STATHZ}"
info "carga previa: $(uptime | sed 's/.*load averages/load/')"
info "ventana ${SECS}s x ${REPS} repeticiones, ${GENS} generador(es) en la CPU 0"

mkdir -p "$WORK" /var/run/amneziawg || exit 1
: > "$RESULTS"

# ---------------------------------------------------------------------------
# Escenarios
# ---------------------------------------------------------------------------
OBF_TIPICA="Jc = 4
Jmin = 40
Jmax = 70
S1 = 30
S2 = 40
H1 = 1234567891
H2 = 1234567892
H3 = 1234567893
H4 = 1234567894"

# El minimo que el backend acepta: Jc=1 es el piso del rango, S1/S2 en 0, y los
# headers en los valores nativos de WireGuard. Es amneziawg-go haciendo lo menos
# posible, para separar el costo de userspace del costo de ofuscar.
OBF_MINIMA="Jc = 1
Jmin = 1
Jmax = 2
S1 = 0
S2 = 0
H1 = 1
H2 = 2
H3 = 3
H4 = 4"

scenario "wg-kernel"    wg  ""
scenario "awg-plano"    awg "$OBF_MINIMA"
scenario "awg-ofuscado" awg "$OBF_TIPICA"

# ---------------------------------------------------------------------------
# Resumen
# ---------------------------------------------------------------------------
say "RESUMEN  (mediana de $REPS)"

printf '\n  %-14s %10s %10s %8s %8s %8s %10s %10s %7s\n' \
       escenario Mbps pps cores CPUcifra CPUdesc Mbps/core rechazado gen
printf '  %s\n' "------------------------------------------------------------------------------------------------"

awk '
{
	n[$1]++
	mbps[$1, n[$1]] = $2; pps[$1, n[$1]] = $3
	cores[$1, n[$1]] = $6; ctx[$1, n[$1]] = $7; crx[$1, n[$1]] = $8
	loss[$1, n[$1]] = $10; gen[$1, n[$1]] = $11
	if (!($1 in seen)) { seen[$1] = 1; order[++k] = $1 }
}
function med(name, arr,   i, v, c, j, t) {
	c = 0
	for (i = 1; i <= n[name]; i++) v[++c] = arr[name, i]
	for (i = 1; i < c; i++) for (j = i + 1; j <= c; j++)
		if (v[j] < v[i]) { t = v[i]; v[i] = v[j]; v[j] = t }
	return (c % 2) ? v[int(c / 2) + 1] : (v[c / 2] + v[c / 2 + 1]) / 2
}
END {
	for (i = 1; i <= k; i++) {
		nm = order[i]
		m = med(nm, mbps); c = med(nm, cores)
		printf "  %-14s %10.1f %10.0f %8.2f %8.2f %8.2f %10.1f %9.1f%% %7.2f\n", \
		       nm, m, med(nm, pps), c, med(nm, ctx), med(nm, crx), \
		       (c > 0 ? m / c : 0), med(nm, loss), med(nm, gen)
	}
}' "$RESULTS"

cat <<'EOF'

  Mbps        = paquetes aceptados x 1392 B de payload, sin ningun encabezado
  B/pq        = lo que informa el contador de la interfaz, que NO es lo mismo
                en wg (1452) que en tun (1424) para el mismo paquete; por eso
                el caudal se calcula contando paquetes y no bytes
  cores       = CPU de la caja MENOS la del generador, o sea la del tunel
  CPUcifra    = cores del proceso que cifra   (solo escenarios awg)
  CPUdesc     = cores del proceso que descifra
  rechazado   = % de paquetes que el tunel no acepto (ENOBUFS en el generador)
  gen         = cores del generador, que corre clavado a UNA sola CPU; en ~1.00
                el cuello de botella es el generador y el Mbps es un piso
  x cable     = bytes cifrados / bytes utiles

  Con "rechazado" bien arriba de cero el enlace esta saturado, y entonces el
  Mbps es capacidad y no simplemente lo que se ofrecio. Si diera cero, el
  numero seria un piso: el generador no llego a llenar el tunel.

  El numero mide CIFRAR + DESCIFRAR en la misma caja. Un pfSense contra
  clientes remotos paga una sola mitad por paquete, asi que la capacidad real
  en un sentido esta por encima de esto: mirar CPUcifra y CPUdesc para
  repartir el costo.
EOF
