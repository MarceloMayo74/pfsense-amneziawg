#!/bin/sh
#
# check-collisions.sh - Verifica que este paquete no declare ningun simbolo
# global con el mismo nombre que pfSense-pkg-WireGuard.
#
#   sh tools/check-collisions.sh
#
# PHP tiene un unico espacio de nombres global para funciones, clases y
# constantes. Los dos paquetes estan pensados para poder estar instalados a la
# vez (docs/arquitectura.md, seccion 8) y hay paginas que cargan los dos en el
# mismo proceso -- el dashboard, sin ir mas lejos, donde conviven los widgets.
# Ahi el segundo en cargarse aborta con "Cannot redeclare function", y no hay
# forma de que el usuario lo evite salvo desinstalar uno.
#
# El renombrado mecanico del fork cubre todo lo que tiene 'wg' en el nombre. Lo
# que se escapa son los helpers de nombre generico: array_get_value() y sus dos
# hermanas venian asi y rompian el dashboard.
#
# Se corre desde la raiz del repo y necesita reference/, que no esta versionado:
#   sh tools/fetch-references.sh
#

set -eu

SRC=src
REF=reference/pfSense-pkg-WireGuard-0.2.13

if [ ! -d "$SRC" ]; then
	echo "Falta $SRC. Correr desde la raiz del repo." >&2
	exit 2
fi

if [ ! -d "$REF" ]; then
	echo "Falta $REF. Traerlo con: sh tools/fetch-references.sh" >&2
	exit 2
fi

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

# Las funciones que devuelven por referencia se declaran 'function &nombre', y
# olvidarse del & fue lo que escondio array_get_value() la primera vez que se
# buscaron colisiones.
symbols() {
	_dir=$1
	{
		grep -rhoE '^function[[:space:]]+&?[a-zA-Z0-9_]+' "$_dir" 2>/dev/null \
			| sed 's/^function[[:space:]]*&*//' | sed 's/^/function /'
		grep -rhoE '^class[[:space:]]+[a-zA-Z0-9_]+' "$_dir" 2>/dev/null \
			| sed 's/^class[[:space:]]*/class /'
		grep -rhoE "define\([\"'][A-Za-z0-9_]+" "$_dir" 2>/dev/null \
			| sed "s/define([\"']/constant /"
	} | sort -u
}

symbols "$SRC" > "$WORK/ours"
symbols "$REF" > "$WORK/theirs"

comm -12 "$WORK/ours" "$WORK/theirs" > "$WORK/both"

echo "AmneziaWG declara $(wc -l < "$WORK/ours" | tr -d ' ') simbolos globales."
echo "WireGuard declara  $(wc -l < "$WORK/theirs" | tr -d ' ')."
echo

if [ -s "$WORK/both" ]; then
	echo "COLISIONES -- los dos paquetes no pueden convivir asi:"
	sed 's/^/  /' "$WORK/both"
	echo
	echo "Renombrarlos con prefijo del paquete (awg_) en src/, y agregar la"
	echo "regla de sed a tools/fork-from-wireguard.sh para que un re-fork no"
	echo "los reintroduzca."
	exit 1
fi

echo "Sin colisiones."
