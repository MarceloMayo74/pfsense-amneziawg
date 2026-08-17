#!/bin/sh
#
# build-awg-freebsd.sh - Compila awg(8) de amneziawg-tools para FreeBSD.
#
#   sh build-awg-freebsd.sh [version]        # por defecto 3.1.20260812
#
# Se corre EN UNA VM (o jail) DE FreeBSD, no en pfSense ni en Windows. Por que
# hace falta, si hasta ahora el binario salia hecho del port net/amnezia-tools:
# el port sigue clavado en 1.0.20250903, que es AmneziaWG 2.0, y los releases de
# Amnezia publican binarios de Alpine, Ubuntu y Windows nada mas. Para 3.x no
# hay binario de FreeBSD en ningun lado. Y el firewall no sirve como maquina de
# build: pfSense no trae cc, ld ni /usr/include.
#
# Que deja, en out/:
#
#   awg                         el binario, ya con strip
#   awg-src-<version>.tar.gz    la fuente EXACTA de la que salio
#   SHA256                      los dos hashes, para poder demostrar el par
#   BUILDINFO                   quien, donde y con que lo compilo
#
# Los cuatro archivos importan: `awg` es GPLv2, asi que distribuirlo dentro del
# .pkg obliga a ofrecer la fuente correspondiente. Mientras el binario venia del
# port, "la fuente correspondiente" era el distfile del port mas sus parches y
# la armaba tools/fetch-sources.sh. Compilandolo nosotros la cadena se acorta y
# se vuelve mas facil de demostrar --tarball del tag -> este script -> binario--
# pero hay que publicar el tarball igual. Ver docs/licencias.md.
#
# Requisitos en la VM: nada. cc y libnv vienen en base, y si no hay gmake el
# script compila igual.
#
# El Makefile de upstream es de GNU make (usa ifeq/wildcard/patsubst), asi que
# bmake no sirve. Con `pkg install -y gmake` se usa el Makefile; si no esta, se
# compila con un cc directo que reproduce exactamente lo que hace el Makefile
# --linkea los .c de src/ sin excluir ninguno, con los mismos flags y -lnv--.
# El camino sin gmake no es un atajo: en una VM recien instalada puede no haber
# forma de instalarlo, que es como se descubrio (el mirror de paquetes de
# FreeBSD no se alcanzaba desde la red donde vive la VM).
#
# Y la VM tiene que ser de la MISMA rama mayor que el firewall (pfSense 2.9 =
# FreeBSD 16). Un binario de C queda atado a la ABI de libc de donde salio.
#

set -eu

VERSION=${1:-3.1.20260812}

WORK=${WORK:-/root/awg-build}
OUT="$WORK/out"
SRCDIR=""
TARBALL="$WORK/awg-src-${VERSION}.tar.gz"
URL="https://codeload.github.com/amnezia-vpn/amneziawg-tools/tar.gz/refs/tags/v${VERSION}"

say() { printf '\n=== %s ===\n' "$*"; }
ok()  { printf '  [OK]    %s\n' "$*"; }
bad() { printf '  [FALLA] %s\n' "$*" >&2; }

# --------------------------------------------------------------- requisitos ---
say "Requisitos"

_rel=$(uname -r)
_major=${_rel%%.*}
printf '  FreeBSD %s (%s)\n' "$_rel" "$(uname -m)"

if [ "$_major" != "16" ]; then
	printf '  OJO: esta VM es FreeBSD %s y el destino es pfSense 2.9 = FreeBSD 16.\n' "$_major"
	printf '       El binario probablemente ande igual, pero hay que probarlo en\n'
	printf '       el firewall antes de empaquetarlo.\n'
fi

command -v cc >/dev/null 2>&1 || { bad "falta cc, y viene en base: la VM esta rota"; exit 1; }

if command -v gmake >/dev/null 2>&1; then
	USE_GMAKE=yes
	ok "cc y gmake presentes"
else
	USE_GMAKE=no
	ok "cc presente; sin gmake, se compila con cc directo"
fi

if command -v fetch >/dev/null 2>&1; then
	get() { fetch -q -o "$2" "$1"; }
elif command -v curl >/dev/null 2>&1; then
	get() { curl -fsSL -o "$2" "$1"; }
else
	bad "hace falta fetch o curl"
	exit 1
fi

# Cada sistema le puso otro nombre a lo mismo.
if command -v sha256 >/dev/null 2>&1; then
	sha256of() { sha256 -q "$1"; }
else
	sha256of() { sha256sum "$1" | cut -d' ' -f1; }
fi

# ------------------------------------------------------------------ fuente ---
say "Fuente: amneziawg-tools v$VERSION"

rm -rf "$WORK"
mkdir -p "$OUT"

# Con SRC_TARBALL=/ruta se usa un tarball ya bajado en vez de ir a la red. Sirve
# para una VM sin salida, y tambien para cuando GitHub contesta 429 --que pasa,
# y deja un mensaje de error que no dice que es un limite de tasa--. Tiene que
# vivir FUERA de $WORK, que se borra entero al empezar.
if [ -n "${SRC_TARBALL:-}" ]; then
	cp "$SRC_TARBALL" "$TARBALL" || { bad "no se pudo copiar $SRC_TARBALL"; exit 1; }
	ok "usando el tarball local $SRC_TARBALL"
else
	get "$URL" "$TARBALL" || { bad "no se pudo bajar $URL"; exit 1; }
fi

SRC_SHA=$(sha256of "$TARBALL")
ok "tarball bajado ($(wc -c < "$TARBALL" | tr -d ' ') bytes)"
printf '          sha256 %s\n' "$SRC_SHA"

tar -xzf "$TARBALL" -C "$WORK"

# El directorio de adentro no siempre se llama igual: el tarball de codeload
# trae amneziawg-tools-<version> y el de la API de GitHub trae
# <owner>-<repo>-<sha>. Se busca en vez de suponerlo.
SRCDIR=$(find "$WORK" -maxdepth 1 -type d -name '*amneziawg-tools*' | head -1)
[ -n "$SRCDIR" ] && [ -f "$SRCDIR/src/Makefile" ] \
	|| { bad "el tarball no trajo un arbol de amneziawg-tools"; exit 1; }
ok "fuente en $(basename "$SRCDIR")"

# La version que va a reportar el binario sale de src/version.h, no del tag ni
# de git: el Makefile solo pisa WIREGUARD_TOOLS_VERSION si encuentra un .git al
# lado, y aca no hay ninguno. Se verifica que diga lo que corresponde, porque un
# awg que miente sobre su version es exactamente la clase de cosa que despues
# hace perder una tarde (el del port dice v1.0.20250521 y empaqueta 1.0.20250903).
_hver=$(sed -n 's/.*WIREGUARD_TOOLS_VERSION "\(.*\)".*/\1/p' "$SRCDIR/src/version.h" | head -1)
if [ "$_hver" != "$VERSION" ]; then
	printf '  OJO: version.h dice "%s" y el tag es v%s. El binario va a reportar\n' "$_hver" "$VERSION"
	printf '       lo primero.\n'
else
	ok "version.h dice $_hver"
fi

# ------------------------------------------------------------------ parche ---
say "Parche: sin IPC por kernel"

# El camino de IPC por kernel de FreeBSD --ipc-freebsd.h, el que habla con
# if_amn.ko por nvlist-- NO COMPILA en 3.x. Quedo escrito contra el containers.h
# viejo: usa dev->init_packet_magic_header, que era una cadena, cuando en 3.x
# H1-H4 son u32_range_t init_header, y usa MAX_AWG_STRING_LEN, que ya no existe.
# Upstream no lo nota porque solo publica binarios de Linux, Alpine y Windows.
#
# Aca no se porta: se desactiva. Este paquete usa SIEMPRE el camino userspace
# --el socket UAPI de amneziawg-go-- porque if_amn.ko no carga en pfSense (ver
# docs/arquitectura.md, seccion 2), y en ipc.c las tres llamadas al kernel ya
# estan detras de IPC_SUPPORTS_KERNEL_INTERFACE, que lo define ese header. Sin
# el, el binario queda con el comportamiento que se necesita y una superficie
# menos.
#
# Es la unica modificacion que se le hace a la fuente de upstream, y por eso
# queda anotada en BUILDINFO.
sed -i '' 's/#elif defined(__FreeBSD__)/#elif defined(__FreeBSD__) \&\& !defined(AWG_NO_KERNEL_IPC)/' "$SRCDIR/src/ipc.c"
grep -q 'AWG_NO_KERNEL_IPC' "$SRCDIR/src/ipc.c" \
	|| { bad "no se pudo aplicar el parche a ipc.c"; exit 1; }
ok "ipc.c parcheado"

# ---------------------------------------------------------------- compilar ---
say "Compilando"

if [ "$USE_GMAKE" = yes ]; then
	# PLATFORM lo deduce solo de uname; se pasa explicito para que no haya
	# dudas de que se compilo el camino de FreeBSD (ipc-freebsd.h) y no el
	# generico.
	# CPPFLAGS y no CFLAGS: una variable pasada por linea de comandos pisa las
	# asignaciones del Makefile, y CFLAGS ahi se arma con += en varios pasos.
	gmake -C "$SRCDIR/src" PLATFORM=freebsd CPPFLAGS=-DAWG_NO_KERNEL_IPC \
		-j"$(sysctl -n hw.ncpu)" wg
else
	# Los mismos flags que arma el Makefile para PLATFORM=freebsd, leidos de
	# src/Makefile: CFLAGS de la parte comun, -I uapi/freebsd porque ese
	# directorio existe, RUNSTATEDIR con su valor por defecto, y -lnv que es
	# lo unico que agrega la rama de FreeBSD. El objetivo 'wg' linkea TODOS
	# los .c del directorio, sin excluir ninguno.
	( cd "$SRCDIR/src" && cc -O3 -std=gnu99 -D_GNU_SOURCE -Wall -Wextra \
		-DRUNSTATEDIR='"/var/run"' -DAWG_NO_KERNEL_IPC -I uapi/freebsd \
		*.c -o wg -lnv )
fi

[ -x "$SRCDIR/src/wg" ] || { bad "no se genero el binario"; exit 1; }

# El Makefile lo llama 'wg' y lo instala como 'awg'. Aca se renombra al copiar.
cp "$SRCDIR/src/wg" "$OUT/awg"
strip "$OUT/awg" 2>/dev/null || true
chmod 755 "$OUT/awg"
cp "$TARBALL" "$OUT/"

ok "$(wc -c < "$OUT/awg" | tr -d ' ') bytes"

# ------------------------------------------------------------ comprobacion ---
say "Comprobaciones"

_ver=$("$OUT/awg" --version 2>&1 | head -1)
printf '  %s\n' "$_ver"
echo "$_ver" | grep -q "$VERSION" && ok "reporta la version esperada" \
	|| bad "reporta otra version -- revisar antes de empaquetar"

# Que hable el UAPI de FreeBSD y no el generico: si se hubiera compilado el
# camino equivocado, un setconf contra una interfaz inexistente fallaria por
# otra razon. Se prueba que reconozca una clave de 3.x, que es lo que se vino
# a buscar.
_probe=$(mktemp)
{
	echo '[Interface]'
	echo 'PrivateKey = QOfjW+aQKrJvIbYisIoAO2FYUEQlZ5RGxaBhbTKlaEE='
	echo 'ContentPaddingAddition = 12-40'
	echo 'RandomTrailers = off'
} > "$_probe"

_out=$("$OUT/awg" setconf awgprobe$$ "$_probe" 2>&1 || true)
rm -f "$_probe"

case "$_out" in
	*nrecognized*|*"parsing error"*)
		bad "no entiende las claves de 3.x: $_out"
		exit 1 ;;
	*)
		ok "parsea ContentPaddingAddition y RandomTrailers (claves de 3.x)" ;;
esac

ldd "$OUT/awg" 2>/dev/null | sed 's/^/          /' || true

# ---------------------------------------------------------------- resultado ---
{
	printf '%s  %s\n' "$(sha256of "$OUT/awg")" 'awg'
	printf '%s  %s\n' "$SRC_SHA" "awg-src-${VERSION}.tar.gz"
} > "$OUT/SHA256"

{
	printf 'amneziawg-tools v%s\n' "$VERSION"
	printf 'compilado en    : %s %s %s\n' "$(uname -s)" "$(uname -r)" "$(uname -m)"
	printf 'compilador      : %s\n' "$(cc --version 2>/dev/null | head -1)"
	printf 'fecha           : %s\n' "$(date -u '+%Y-%m-%d %H:%M:%S UTC')"
	printf 'parche          : ipc.c sin el camino de kernel (-DAWG_NO_KERNEL_IPC)\n'
	if [ -n "${SRC_TARBALL:-}" ]; then
		printf 'fuente          : %s (tarball provisto, no bajado de la red)\n' "$SRC_TARBALL"
	else
		printf 'fuente          : %s\n' "$URL"
	fi
	if [ "$USE_GMAKE" = yes ]; then
		printf 'comando         : gmake -C src PLATFORM=freebsd wg\n'
	else
		printf 'comando         : cc -O3 -std=gnu99 -D_GNU_SOURCE -Wall -Wextra -DRUNSTATEDIR=\"/var/run\" -DAWG_NO_KERNEL_IPC -I uapi/freebsd *.c -o wg -lnv\n'
	fi
} > "$OUT/BUILDINFO"

say "Listo"
cat "$OUT/BUILDINFO" | sed 's/^/  /'
printf '\n  En la maquina de build:\n'
printf '    scp root@%s:%s/awg bin/FreeBSD-16-amd64/\n' "$(hostname)" "$OUT"
printf '    scp root@%s:%s/awg-src-%s.tar.gz dist/\n' "$(hostname)" "$OUT" "$VERSION"
printf '\n  Y actualizar NOTICE: el awg ya no sale del port.\n\n'
