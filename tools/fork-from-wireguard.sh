#!/bin/sh
#
# fork-from-wireguard.sh - Deriva el arbol del paquete desde pfSense-pkg-WireGuard.
#
# Se corre UNA sola vez, al principio del proyecto. El resultado en src/ pasa a
# ser el codigo fuente de verdad y se edita a mano de ahi en adelante: esto NO
# es un paso de build. Queda versionado como registro de como se derivo el
# arbol, para poder repetir el ejercicio contra una version mas nueva del
# paquete oficial si alguna vez hace falta.
#
# Lo que hace es el renombrado mecanico de alto volumen (~1400 ocurrencias).
# Los archivos que necesitan cambios de fondo quedan marcados con un TODO y se
# escriben a mano despues:
#
#   awg_globals.inc   identidad del paquete, sin kmod, prefijo tun9
#   awg_api.inc       crear interfaz = spawn de amneziawg-go, no ifconfig create
#   awg_service.inc   supervisor de N procesos go
#   amneziawg.xml     menu, servicio, hooks
#
# Uso:   sh tools/fork-from-wireguard.sh
#

set -eu

SRC=reference/pfSense-pkg-WireGuard-0.2.13
DEST=src

if [ ! -d "$SRC" ]; then
	echo "Falta $SRC. Correr antes: sh tools/fetch-references.sh" >&2
	exit 1
fi

if [ -d "$DEST" ]; then
	echo "$DEST ya existe. Este script se corre una sola vez; el arbol se edita" >&2
	echo "a mano desde ahi. Para rehacerlo de cero: rm -rf $DEST" >&2
	exit 1
fi

echo "Derivando $DEST desde $SRC"

# Solo el payload del paquete. Makefile, pkg-plist y pkg-descr se escriben
# propios: describen otro paquete, no una variante de este.
mkdir -p "$DEST"
for d in etc usr; do
	[ -d "${SRC}/${d}" ] && cp -r "${SRC}/${d}" "${DEST}/"
done

# ---------------------------------------------------------------------------
# Renombrado de contenido.
#
# El ORDEN importa: cada regla se aplica una sola vez y varias se solapan.
# Las mas especificas van primero para que las genericas no se las coman.
#
# OJO con las reglas redundantes: sed aplica cada -e sobre el resultado de la
# anterior, no sobre el texto original. Habia una regla 's/vpn_wg_/vpn_awg_/g'
# antes de 's/wg_/awg_/g', y la segunda volvia a matchear el 'wg_' que vive
# DENTRO del 'awg_' recien escrito, dejando 'vpn_aawg_' en ~130 lugares. Los
# nombres de archivo salian bien (abajo el bloque de rename usa ^ anclado), asi
# que el arbol parecia correcto y en realidad ningun link resolvia.
# 's/wg_/awg_/g' sola ya convierte 'vpn_wg_' en 'vpn_awg_': no hace falta nada
# mas especifico.
# ---------------------------------------------------------------------------
echo "  reescribiendo contenido..."

find "$DEST" -type f -exec sed -i \
	-e 's/php_wg/php_awg/g' \
	-e 's/\bWG_/AWG_/g' \
	-e 's/wg_/awg_/g' \
	-e 's|/wg/|/awg/|g' \
	-e 's|"wg/|"awg/|g' \
	-e "s|'wg/|'awg/|g" \
	-e 's|/wg\.inc|/awg.inc|g' \
	-e 's/wgRegTrim/awgRegTrim/g' \
	-e 's/\barray_get_value\b/awg_array_get_value/g' \
	-e 's/\barray_set_value\b/awg_array_set_value/g' \
	-e 's/\barray_unset_value\b/awg_array_unset_value/g' \
	-e 's/wgg/awgg/g' \
	-e 's/wgconfig/awgconfig/g' \
	-e "s/'wg'/'awg'/g" \
	-e 's/wg(8)/awg(8)/g' \
	-e 's/`wg /`awg /g' \
	-e 's/WireGuard/AmneziaWG/g' \
	-e 's/WIREGUARD/AMNEZIAWG/g' \
	-e 's/wireguard/amneziawg/g' \
	{} \;

# ---------------------------------------------------------------------------
# Renombrado de archivos y directorios.
#
# De adentro hacia afuera (-depth), si no se renombra un padre y despues no se
# encuentran los hijos.
#
# Se recorre TODO en vez de filtrar con -name '*wg*': la palabra "wireguard" no
# contiene la secuencia "wg" (es w-i-r-e-g-u-a-r-d), asi que ese filtro se comia
# justo los archivos que mas importan.
# ---------------------------------------------------------------------------
echo "  renombrando archivos..."

find "$DEST" -depth -mindepth 1 | while read -r path; do
	dir=$(dirname "$path")
	base=$(basename "$path")

	new=$(echo "$base" \
		| sed -e 's/vpn_wg_/vpn_awg_/g' \
		      -e 's/^wg_/awg_/' \
		      -e 's/^wg\./awg./' \
		      -e 's/^wg$/awg/' \
		      -e 's/wgconfig/awgconfig/' \
		      -e 's/WireGuard/AmneziaWG/g' \
		      -e 's/wireguard/amneziawg/g')

	[ "$base" = "$new" ] || mv "$path" "${dir}/${new}"
done

# ---------------------------------------------------------------------------
# Marcar lo que queda por hacer a mano.
# ---------------------------------------------------------------------------
echo "  marcando pendientes..."

# El comentario va DENTRO del bloque <?php que ya existe. Abrir uno nuevo y
# cerrarlo con ?> antes del original emitiria un salto de linea a la salida, y
# en un include de pfSense eso es output antes de los headers.
mark() {
	_f=$1; _why=$2
	[ -f "$_f" ] || return 0
	sed -i "1a\\
/* TODO FASE 2 - reescribir a mano: ${_why}\\
 * Este archivo salio del renombrado mecanico y todavia asume el modelo de\\
 * WireGuard con modulo de kernel. Ver docs/arquitectura.md\\
 */" "$_f"
	echo "      TODO  $_f"
}

P="${DEST}/usr/local/pkg/amneziawg/includes"
mark "${P}/awg_globals.inc" "identidad del paquete, sin kmod, prefijo tun9"
mark "${P}/awg_api.inc"     "crear interfaz = spawn de amneziawg-go"
mark "${P}/awg_service.inc" "supervisor de N procesos go"

echo
echo "Listo. Verificar que no quedaron restos. Este es el chequeo que importa:"
echo "  grep -rnE '(^|[^aA])wg[a-zA-Z0-9_./]*' $DEST"
echo
echo "Busca cualquier 'wg' que no sea parte de 'awg' ni de 'AmneziaWG'. Lo unico"
echo "que deberia aparecer son los comentarios que contrastan a proposito con el"
echo "paquete de WireGuard. Un solo resto ahi es un link que no resuelve."
