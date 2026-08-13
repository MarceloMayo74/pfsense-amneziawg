#!/bin/sh
#
# fase1-bringup.sh - Fase 1 del plan: levantar un tunel AmneziaWG a mano.
#
# Sin PHP, sin paquete. Valida el supuesto central del diseno antes de escribir
# una linea de la GUI: que amneziawg-go + awg alcanzan para tener un tunel
# funcionando en pfSense.
#
# No hace falta un servidor AmneziaWG del otro lado. Levanta DOS tuneles en
# este mismo firewall y los hace hablar entre si por loopback, asi el test es
# autocontenido.
#
#   tun9000  10.253.253.1/30  <--- UDP 127.0.0.1 --->  tun9001  10.253.253.2/30
#
# Ademas contesta los dos riesgos que quedaron abiertos en docs/arquitectura.md:
#   - que version de backend hay (AWG 1.x o 2.0)
#   - si los parametros S3/S4/I1-I5 son aceptados
#
# NO usa pkg(8): no agrega repos ni instala nada. Todo queda en /root/awg-fase1
# y se revierte al salir.
#
# Uso:   sh fase1-bringup.sh
#

set -u

WORK=/root/awg-fase1
ABI_FALLBACK="FreeBSD:15:amd64"

IF_A=tun9000
IF_B=tun9001
ADDR_A=10.253.253.1
ADDR_B=10.253.253.2
MASK=30
PORT_A=51900
PORT_B=51901

# Parametros de ofuscacion. Tienen que ser identicos en los dos lados.
# H1-H4 deben ser unicos y distintos de 1,2,3,4 (los tipos de mensaje reales).
OBF_JC=4
OBF_JMIN=40
OBF_JMAX=70
OBF_S1=30
OBF_S2=40
OBF_H1=1234567891
OBF_H2=1234567892
OBF_H3=1234567893
OBF_H4=1234567894

# Estado para el resumen y el teardown.
STARTED=""
RESULT_BIN=NO
RESULT_VER="?"
RESULT_UP=NO
RESULT_HANDSHAKE=NO
RESULT_PING=NO
RESULT_AWG2=NO

say()  { printf '\n=== %s ===\n' "$*"; }
ok()   { printf '  [OK]    %s\n' "$*"; }
bad()  { printf '  [FALLA] %s\n' "$*"; }
info() { printf '  .       %s\n' "$*"; }

# ---------------------------------------------------------------------------
# Teardown: pase lo que pase, no dejar procesos ni interfaces colgadas.
# ---------------------------------------------------------------------------
teardown() {
	say "Limpieza"

	for _if in $STARTED; do
		# Especifico por nombre de interfaz: no toca otros procesos.
		pkill -f "amneziawg-go ${_if}" 2>/dev/null \
			&& ok "proceso de ${_if} terminado" \
			|| info "no habia proceso de ${_if}"

		if ifconfig "$_if" >/dev/null 2>&1; then
			ifconfig "$_if" destroy 2>/dev/null \
				&& ok "interfaz ${_if} destruida" \
				|| bad "no se pudo destruir ${_if}"
		fi
		rm -f "/var/run/amneziawg/${_if}.sock"
	done

	info "archivos en $WORK (borrar con: rm -rf $WORK)"
}
trap teardown EXIT INT TERM

# ---------------------------------------------------------------------------
# Fase 0 - Entorno
# ---------------------------------------------------------------------------
say "Fase 0: entorno"

if [ "$(id -u)" != "0" ]; then
	bad "hay que correr como root"
	exit 1
fi

info "pfSense: $(cat /etc/version 2>/dev/null || echo desconocida)"
info "kernel:  $(uname -sr)"

ABI=$(pkg config ABI 2>/dev/null)
[ -n "$ABI" ] || ABI="$ABI_FALLBACK"
info "ABI:     $ABI"

# Chequeo de colision antes de tocar nada.
for _if in "$IF_A" "$IF_B"; do
	if ifconfig "$_if" >/dev/null 2>&1; then
		bad "$_if ya existe; abortando para no pisar nada"
		exit 1
	fi
done

mkdir -p "$WORK" || exit 1
cd "$WORK" || exit 1

# ---------------------------------------------------------------------------
# Descarga desde pkg.freebsd.org sin usar pkg(8).
#
# El repo publica bajo All/Hashed/ con sufijo de hash, asi que el nombre de
# archivo no se puede adivinar: hay que leerlo de packagesite.yaml. Listar
# /All/ tampoco sirve, devuelve Forbidden.
# ---------------------------------------------------------------------------
fetch_pkg() {
	_name=$1
	_out=""

	for _branch in latest quarterly; do
		_root="https://pkg.freebsd.org/${ABI}/${_branch}"
		_cat="catalog-${_branch}.yaml"

		if [ ! -s "$_cat" ]; then
			info "leyendo catalogo de $_branch (~10 MB)..." >&2
			fetch -q -o - "${_root}/packagesite.pkg" 2>/dev/null \
				| tar -xOf - packagesite.yaml > "$_cat" 2>/dev/null
		fi
		[ -s "$_cat" ] || continue

		_repopath=$(grep "\"name\":\"${_name}\"," "$_cat" 2>/dev/null \
			| grep -oE '"repopath":"[^"]*"' \
			| sed 's|.*":"||; s|"$||' | head -1)
		[ -n "$_repopath" ] || continue

		_file=$(basename "$_repopath")
		if fetch -q -o "$_file" "${_root}/${_repopath}" 2>/dev/null; then
			info "$_branch: $_repopath" >&2
			_out="$_file"
			break
		fi
		rm -f "$_file"
	done

	[ -n "$_out" ] || return 1
	echo "$_out"
}

# ---------------------------------------------------------------------------
# Fase 1 - Binarios
# ---------------------------------------------------------------------------
say "Fase 1: binarios"

if [ ! -x bin/amneziawg-go ] || [ ! -x bin/awg ]; then
	mkdir -p bin

	GOPKG=$(fetch_pkg amneziawg-go)
	TOOLPKG=$(fetch_pkg amnezia-tools)

	if [ -z "${GOPKG:-}" ] || [ -z "${TOOLPKG:-}" ]; then
		bad "no se pudieron descargar los paquetes para $ABI"
		exit 1
	fi

	rm -rf ex && mkdir ex
	tar -xf "$GOPKG"   -C ex 2>/dev/null
	tar -xf "$TOOLPKG" -C ex 2>/dev/null

	# -path '*/bin/*' evita agarrar el script de bash-completion, que tambien
	# se llama 'awg' y no es el binario.
	_go=$(find ex -type f -name 'amneziawg-go' -path '*/bin/*' | head -1)
	_awg=$(find ex -type f -name 'awg' -path '*/bin/*' | head -1)

	if [ -z "$_go" ] || [ -z "$_awg" ]; then
		bad "no aparecieron los binarios dentro de los paquetes"
		exit 1
	fi
	cp "$_go" bin/amneziawg-go
	cp "$_awg" bin/awg
	chmod +x bin/amneziawg-go bin/awg
fi

GO_BIN="${WORK}/bin/amneziawg-go"
AWG="${WORK}/bin/awg"

_ver=$("$AWG" --version 2>&1 | head -1)
if echo "$_ver" | grep -qiE "not found|syntax error|exec format"; then
	bad "awg no ejecuta: $_ver"
	ldd "$AWG" 2>/dev/null | grep -i "not found" | sed 's/^/          /'
	exit 1
fi
RESULT_BIN=SI
RESULT_VER="$_ver"
ok "awg: $_ver"
ok "amneziawg-go: $("$GO_BIN" --version 2>&1 | head -1)"

# ---------------------------------------------------------------------------
# Fase 2 - Claves y configuraciones
# ---------------------------------------------------------------------------
say "Fase 2: claves y configuraciones"

KEY_A=$("$AWG" genkey)
KEY_B=$("$AWG" genkey)
PUB_A=$(echo "$KEY_A" | "$AWG" pubkey)
PUB_B=$(echo "$KEY_B" | "$AWG" pubkey)
ok "par de claves generado para cada lado"

# awg setconf NO acepta Address ni DNS: esas son directivas de awg-quick.
# La direccion se pone con ifconfig, igual que hara el paquete.
write_conf() {
	_file=$1; _key=$2; _port=$3; _peerpub=$4; _peerport=$5; _peeraddr=$6; _extra=$7

	cat > "$_file" <<EOF
[Interface]
PrivateKey = ${_key}
ListenPort = ${_port}
Jc = ${OBF_JC}
Jmin = ${OBF_JMIN}
Jmax = ${OBF_JMAX}
S1 = ${OBF_S1}
S2 = ${OBF_S2}
H1 = ${OBF_H1}
H2 = ${OBF_H2}
H3 = ${OBF_H3}
H4 = ${OBF_H4}
${_extra}
[Peer]
PublicKey = ${_peerpub}
AllowedIPs = ${_peeraddr}/32
Endpoint = 127.0.0.1:${_peerport}
PersistentKeepalive = 5
EOF
}

write_conf "${IF_A}.conf" "$KEY_A" "$PORT_A" "$PUB_B" "$PORT_B" "$ADDR_B" ""
write_conf "${IF_B}.conf" "$KEY_B" "$PORT_B" "$PUB_A" "$PORT_A" "$ADDR_A" ""
ok "configuraciones escritas con Jc/Jmin/Jmax/S1/S2/H1-H4"

# ---------------------------------------------------------------------------
# Fase 3 - Levantar los tuneles
# ---------------------------------------------------------------------------
say "Fase 3: levantar los tuneles"

mkdir -p /var/run/amneziawg

start_tunnel() {
	_if=$1; _addr=$2; _peer=$3

	# daemon(8) SIN -f: -f manda stdio del hijo a /dev/null y se pierden todos
	# los logs y crashes de amneziawg-go.
	daemon -p "${WORK}/${_if}.pid" "$GO_BIN" "$_if" >> "${WORK}/${_if}.log" 2>&1
	STARTED="$STARTED $_if"

	# El proceso Go tarda en crear el socket UAPI. Sin esta espera, setconf falla.
	_waited=0
	while [ ! -e "/var/run/amneziawg/${_if}.sock" ] && [ "$_waited" -lt 50 ]; do
		sleep 0.1
		_waited=$((_waited + 1))
	done

	if [ ! -e "/var/run/amneziawg/${_if}.sock" ]; then
		bad "${_if}: no aparecio el socket UAPI"
		sed 's/^/          /' "${WORK}/${_if}.log" 2>/dev/null | tail -5
		return 1
	fi
	ok "${_if}: socket UAPI arriba en $((_waited * 100)) ms"

	if ! "$AWG" setconf "$_if" "${WORK}/${_if}.conf" 2>"${WORK}/${_if}.err"; then
		bad "${_if}: awg setconf fallo"
		sed 's/^/          /' "${WORK}/${_if}.err"
		return 1
	fi
	ok "${_if}: setconf aceptado (parametros de ofuscacion incluidos)"

	ifconfig "$_if" inet "${_addr}/${MASK}" "$_peer" up 2>/dev/null \
		|| ifconfig "$_if" inet "${_addr}/${MASK}" up 2>/dev/null
	return 0
}

if start_tunnel "$IF_A" "$ADDR_A" "$ADDR_B" && start_tunnel "$IF_B" "$ADDR_B" "$ADDR_A"; then
	RESULT_UP=SI
	ok "los dos tuneles arriba"
else
	bad "no se pudieron levantar los dos tuneles"
fi

# ---------------------------------------------------------------------------
# Fase 4 - Handshake
#
# Esto es lo que realmente valida el protocolo: si hay handshake, los binarios
# funcionan, la ofuscacion es coherente entre los dos lados y la cripto anda.
# ---------------------------------------------------------------------------
if [ "$RESULT_UP" = SI ]; then
	say "Fase 4: handshake"

	info "esperando handshake (hasta 15 s)..."
	_waited=0
	while [ "$_waited" -lt 15 ]; do
		_hs=$("$AWG" show "$IF_A" latest-handshakes 2>/dev/null | awk '{print $2}')
		if [ -n "${_hs:-}" ] && [ "${_hs:-0}" -gt 0 ] 2>/dev/null; then
			RESULT_HANDSHAKE=SI
			break
		fi
		sleep 1
		_waited=$((_waited + 1))
	done

	if [ "$RESULT_HANDSHAKE" = SI ]; then
		ok "handshake completado en ${_waited} s"
		"$AWG" show "$IF_A" 2>/dev/null | sed 's/^/          /'
	else
		bad "no hubo handshake en 15 s"
		info "revisar ${WORK}/${IF_A}.log y ${WORK}/${IF_B}.log"
		tail -5 "${WORK}/${IF_A}.log" 2>/dev/null | sed 's/^/          /'
	fi
fi

# ---------------------------------------------------------------------------
# Fase 5 - Trafico
#
# Puede fallar aunque el handshake ande: pf podria estar filtrando en
# interfaces no asignadas. Por eso se reporta aparte del handshake.
# ---------------------------------------------------------------------------
if [ "$RESULT_HANDSHAKE" = SI ]; then
	say "Fase 5: trafico por el tunel"

	if ping -c 3 -t 5 -S "$ADDR_A" "$ADDR_B" 2>&1 | tail -3 | sed 's/^/          /' \
		&& ping -c 1 -t 5 -S "$ADDR_A" "$ADDR_B" >/dev/null 2>&1; then
		RESULT_PING=SI
		ok "ping ${ADDR_A} -> ${ADDR_B} responde"
	else
		bad "el ping no pasa (el handshake si: probable filtrado de pf)"
		info "no invalida el diseno; en el paquete la interfaz va asignada en pfSense"
	fi
fi

# ---------------------------------------------------------------------------
# Fase 6 - Soporte de AWG 2.0
#
# Contesta el riesgo abierto de como detectar la version del backend: se
# intenta un setconf con S3/S4/I1 y se ve si lo acepta.
# ---------------------------------------------------------------------------
if [ "$RESULT_UP" = SI ]; then
	say "Fase 6: soporte de parametros AWG 2.0"

	write_conf "awg2.conf" "$KEY_A" "$PORT_A" "$PUB_B" "$PORT_B" "$ADDR_B" \
"S3 = 15
S4 = 20
I1 = <b 0xf0f0f0f0>"

	if "$AWG" setconf "$IF_A" awg2.conf 2>awg2.err; then
		RESULT_AWG2=SI
		ok "S3, S4 e I1 aceptados -> backend AWG 2.0"
	else
		bad "S3/S4/I1 rechazados -> backend AWG 1.x"
		sed 's/^/          /' awg2.err
		info "la GUI no debe escribir esos campos contra este backend"
	fi

	# Volver a la config 1.x para no dejar el tunel en un estado raro.
	"$AWG" setconf "$IF_A" "${IF_A}.conf" 2>/dev/null
fi

# ---------------------------------------------------------------------------
# Resumen
# ---------------------------------------------------------------------------
say "RESUMEN"

printf '  binarios corren .............. %s\n' "$RESULT_BIN"
printf '  version de awg ............... %s\n' "$RESULT_VER"
printf '  tuneles levantan ............. %s\n' "$RESULT_UP"
printf '  handshake con ofuscacion ..... %s\n' "$RESULT_HANDSHAKE"
printf '  trafico por el tunel ......... %s\n' "$RESULT_PING"
printf '  backend soporta AWG 2.0 ...... %s\n' "$RESULT_AWG2"

printf '\n  VEREDICTO: '
if [ "$RESULT_HANDSHAKE" = SI ]; then
	printf 'DATA PLANE VALIDADO\n'
	printf '  El supuesto central del diseno se sostiene. Se puede pasar a la\n'
	printf '  fase 2 (esqueleto del paquete) con confianza.\n'
elif [ "$RESULT_UP" = SI ]; then
	printf 'LEVANTA PERO NO HAY HANDSHAKE\n'
	printf '  Los binarios y el socket UAPI andan. Revisar los .log antes de\n'
	printf '  seguir: puede ser la ofuscacion o el endpoint por loopback.\n'
else
	printf 'NO LEVANTA\n'
	printf '  Revisar los .log en %s antes de tocar nada mas.\n' "$WORK"
fi
printf '\n'
