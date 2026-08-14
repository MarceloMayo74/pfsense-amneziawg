#!/bin/sh
#
# fetch-sources.sh - Arma el tarball de fuente correspondiente del binario GPLv2.
#
#   sh tools/fetch-sources.sh [version-del-port]
#
# El .pkg lleva adentro `awg`, que es GPLv2, asi que distribuirlo obliga a
# ofrecer la fuente que lo produjo. "La fuente que lo produjo" no es el master
# de upstream: el binario sale del port net/amnezia-tools de FreeBSD, que fija
# un tag y le aplica sus propios parches. Los dos pedazos hacen falta, y este
# script junta los dos y verifica que sean los correctos.
#
# Deja dist/awg-src-<version>.tar.gz, que es lo que se adjunta al release.
#
# La cadena que hay que poder demostrar, y que este script cierra:
#
#   binario del .pkg  ==  awg del paquete FreeBSD amnezia-tools-<version>
#                     <-  distfile del port, sha256 en su distinfo
#                     ==  tarball del tag en GitHub
#
# El ultimo `==` no es obvio y por eso se verifica: el distfile de FreeBSD y el
# tarball que sirve GitHub para el mismo tag son el mismo archivo byte a byte,
# lo que permite bajarlo de GitHub cuando distcache.FreeBSD.org no se alcanza
# --que es el caso desde esta maquina de build--.
#
# Corre en cualquier lado con salida a github.com: no necesita pkg(8) ni
# firewall, a diferencia de tools/fetch-binaries.sh.
#

set -eu

VERSION=${1:-1.0.20250903}

ROOT=$(cd "$(dirname "$0")/.." && pwd)
OUT="$ROOT/dist/awg-src-${VERSION}.tar.gz"

PORTDIR=net/amnezia-tools
DISTFILE="amnezia-vpn-amneziawg-tools-v${VERSION}_GH0.tar.gz"

WORK=$(mktemp -d 2>/dev/null || echo "/tmp/awg-src-$$")
mkdir -p "$WORK"
trap 'rm -rf "$WORK"' EXIT INT TERM

# ------------------------------------------------------------------ utiles ---

if command -v curl >/dev/null 2>&1; then
	get() { curl -fsSL -o "$2" "$1"; }
elif command -v fetch >/dev/null 2>&1; then
	get() { fetch -q -o "$2" "$1"; }
else
	echo "Hace falta curl o fetch." >&2
	exit 1
fi

# Cada sistema le puso otro nombre a lo mismo.
if command -v sha256sum >/dev/null 2>&1; then
	sha256of() { sha256sum "$1" | cut -d' ' -f1; }
elif command -v sha256 >/dev/null 2>&1; then
	sha256of() { sha256 -q "$1"; }
elif command -v shasum >/dev/null 2>&1; then
	sha256of() { shasum -a 256 "$1" | cut -d' ' -f1; }
else
	echo "No hay con que calcular un sha256." >&2
	exit 1
fi

raw() { echo "https://raw.githubusercontent.com/freebsd/freebsd-ports/$1/$2"; }

# ------------------------------------------- el commit del ports tree que va ---
# El port avanza de version. Si main ya no es el que corresponde al binario que
# empaquetamos, hay que ir hacia atras en su historia hasta encontrar el que si:
# el distinfo es lo que dice cual es, porque nombra el distfile con su version.

echo "Buscando el port $PORTDIR con distfile $DISTFILE"

find_commit() {
	_try=$1
	get "$(raw "$_try" "$PORTDIR/distinfo")" "$WORK/distinfo.try" 2>/dev/null || return 1
	grep -q "$DISTFILE" "$WORK/distinfo.try" || return 1
	echo "$_try"
}

REF=$(find_commit main || true)

if [ -z "$REF" ]; then
	echo "  main ya no trae esa version; recorriendo la historia del port"
	get "https://api.github.com/repos/freebsd/freebsd-ports/commits?path=$PORTDIR/distinfo&per_page=40" \
		"$WORK/commits.json"
	for c in $(grep -oE '"sha": "[0-9a-f]{40}"' "$WORK/commits.json" | cut -d'"' -f4); do
		REF=$(find_commit "$c" || true)
		[ -n "$REF" ] && break
	done
fi

if [ -z "$REF" ]; then
	echo >&2
	echo "No hay ningun commit reciente del ports tree con $DISTFILE." >&2
	echo "Revisa la version: la del binario sale de" >&2
	echo "  ssh FIREWALL /usr/local/bin/awg --version   (ojo: eso NO es la del port)" >&2
	echo "  o del +MANIFEST del paquete FreeBSD, que si lo es." >&2
	exit 1
fi

echo "  ports tree: $REF"

# ------------------------------------------------------- el port completito ---
# Makefile, distinfo, pkg-descr y todo files/: los parches del port son parte de
# la fuente correspondiente igual que el tarball de upstream.

SRC="$WORK/awg-src-$VERSION"
mkdir -p "$SRC/ports/$PORTDIR"

echo "Bajando el port"
get "https://api.github.com/repos/freebsd/freebsd-ports/contents/$PORTDIR?ref=$REF" "$WORK/dir.json"
get "https://api.github.com/repos/freebsd/freebsd-ports/contents/$PORTDIR/files?ref=$REF" "$WORK/files.json" \
	|| echo '[]' > "$WORK/files.json"

fetch_listing() {
	_json=$1; _dest=$2
	mkdir -p "$_dest"
	# download_url viene nulo para directorios, y esos se saltean solos.
	grep -oE '"download_url": "https://[^"]+"' "$_json" | cut -d'"' -f4 | while read -r u; do
		echo "  $(basename "$u")"
		get "$u" "$_dest/$(basename "$u")"
	done
}

fetch_listing "$WORK/dir.json"   "$SRC/ports/$PORTDIR"
fetch_listing "$WORK/files.json" "$SRC/ports/$PORTDIR/files"

# --------------------------------------------------------------- el distfile ---

echo "Bajando el tag v$VERSION de upstream"
get "https://codeload.github.com/amnezia-vpn/amneziawg-tools/tar.gz/refs/tags/v$VERSION" \
	"$SRC/$DISTFILE"

want=$(grep "SHA256 ($DISTFILE)" "$SRC/ports/$PORTDIR/distinfo" | sed 's/.*= //')
got=$(sha256of "$SRC/$DISTFILE")

if [ "$want" != "$got" ]; then
	echo >&2
	echo "El tarball de GitHub no coincide con el distinfo del port:" >&2
	echo "  distinfo: $want" >&2
	echo "  bajado:   $got" >&2
	echo "Baja el distfile de http://distcache.FreeBSD.org/ports-distfiles/$DISTFILE" >&2
	exit 1
fi

echo "  sha256 OK contra el distinfo del port: $got"

# ------------------------------------------------------------------ armado ---

cat > "$SRC/README.txt" <<EOF
Fuente correspondiente del binario awg que distribuye pfSense-pkg-AmneziaWG,
bajo la GPL version 2. Ver el archivo NOTICE del paquete.

  $DISTFILE
        amneziawg-tools, tag v$VERSION de
        https://github.com/amnezia-vpn/amneziawg-tools
        sha256 $got
        Es el mismo archivo, byte a byte, que el distfile del port de FreeBSD.

  ports/$PORTDIR/
        El port de FreeBSD que compilo el binario: Makefile, distinfo,
        pkg-descr y los parches de files/. Del ports tree en el commit
        $REF
        Sin esto la fuente no esta completa: el port parchea el arbol de
        upstream antes de compilar.

Para reconstruirlo hace falta un FreeBSD del mismo ABI (FreeBSD:16:amd64) con
el ports tree: se copia ports/$PORTDIR adentro de /usr/ports/$PORTDIR, se pone
el distfile en /usr/ports/distfiles/ y se corre make.

Generado por tools/fetch-sources.sh del repositorio del paquete.
EOF

mkdir -p "$ROOT/dist"
rm -f "$OUT"
tar -czf "$OUT" -C "$WORK" "awg-src-$VERSION"

echo
echo "Listo: $OUT"
ls -l "$OUT"
echo
echo "Va adjunto al release de GitHub, al lado del .pkg."
