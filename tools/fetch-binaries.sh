#!/bin/sh
#
# fetch-binaries.sh - Junta amneziawg-go y awg para el ABI de ESTA maquina.
#
# Se corre EN UN pfSense, no en la maquina de desarrollo. Dos razones: el
# firewall llega a pkg.freebsd.org por definicion (es como se actualiza), y el
# ABI se detecta solo en lugar de elegirlo a mano y equivocarse.
#
# Deja un tarball listo para llevarse a la maquina de build:
#
#   sh fetch-binaries.sh
#   # en la maquina de build:
#   scp root@FIREWALL:/root/awg-bin-<ABI>.tar.gz .
#   tar -xzf awg-bin-<ABI>.tar.gz -C bin/<ABI-con-guiones>/
#
# build/make-pkg.ps1 intenta bajarlos solo si bin/ esta vacio, asi que una vez
# puestos ahi el build no vuelve a necesitar red.
#
# NO usa pkg(8): no agrega repos ni instala nada.
#

set -eu

WORK=/root/awg-bin-work

ABI=$(pkg config ABI 2>/dev/null || true)
if [ -z "$ABI" ]; then
	echo "No se pudo determinar el ABI con 'pkg config ABI'." >&2
	exit 1
fi

OUT="/root/awg-bin-${ABI}.tar.gz"

echo "ABI de esta maquina: $ABI"

rm -rf "$WORK"
mkdir -p "$WORK/out"
cd "$WORK"

# El nombre de archivo no se puede adivinar: el repo publica bajo All/Hashed/
# con sufijo de hash. packagesite.yaml es la lista autoritativa, y listar /All/
# no sirve porque devuelve Forbidden.
echo "Leyendo catalogo de $ABI (~10 MB)..."
fetch -q -o - "https://pkg.freebsd.org/${ABI}/latest/packagesite.pkg" \
	| tar -xOf - packagesite.yaml > catalog.yaml

if [ ! -s catalog.yaml ]; then
	echo "No se pudo leer el catalogo. Hay salida a internet?" >&2
	echo "  fetch -qo - https://pkg.freebsd.org/${ABI}/latest/meta.conf" >&2
	exit 1
fi

grab() {
	_pkg=$1; _bin=$2

	_repopath=$(grep "\"name\":\"${_pkg}\"," catalog.yaml \
		| grep -oE '"repopath":"[^"]*"' \
		| sed 's|.*":"||; s|"$||' | head -1)

	if [ -z "$_repopath" ]; then
		echo "  ${_pkg} no esta en el catalogo de ${ABI}" >&2
		return 1
	fi

	echo "  bajando ${_repopath}"
	fetch -q -o "pkg.tmp" "https://pkg.freebsd.org/${ABI}/latest/${_repopath}"

	rm -rf ex && mkdir ex
	tar -xf pkg.tmp -C ex

	# -path '*/bin/*' evita el script de bash-completion, que tambien se llama
	# awg y no es el binario.
	_found=$(find ex -type f -name "$_bin" -path '*/bin/*' | head -1)
	if [ -z "$_found" ]; then
		echo "  ${_bin} no aparecio dentro de ${_repopath}" >&2
		return 1
	fi

	cp "$_found" "out/${_bin}"
	chmod 755 "out/${_bin}"
	rm -f pkg.tmp
}

grab amneziawg-go  amneziawg-go
grab amnezia-tools awg

tar -czf "$OUT" -C out .
cd /
rm -rf "$WORK"

echo
echo "Listo: $OUT"
ls -la "$OUT"
echo
echo "En la maquina de build:"
echo "  scp root@$(hostname):${OUT} ."
echo "  tar -xzf $(basename "$OUT") -C bin/$(echo "$ABI" | tr ':' '-')/"
