#!/bin/sh
#
# check-globals.sh - Busca funciones que usan $awgg sin declararlo global.
#
#   sh tools/check-globals.sh
#
# En PHP, una funcion que lee $awgg sin 'global $awgg;' no toma la global: toma
# una variable local vacia. No hay error, no hay warning util, y el codigo
# "anda" hasta que el valor importa.
#
# Esto no es teorico. awg_deinstall() llamaba awg_globals() y despues leia
# $awgg['watchdog_cmd'] sin declararlo, asi que pasaba null a
# install_cron_job($command, false). Esa funcion busca la entrada con
# strstr($item['command'], $command), y en PHP un needle vacio matchea, asi que
# en vez de no hacer nada borro el PRIMER cron del sistema -- se llevo
# /usr/sbin/newsyslog de un firewall de verdad.
#
# Llamar awg_globals() no alcanza y es justo lo que engana: puebla la global,
# pero el scope local sigue necesitando la declaracion.
#

set -eu

SRC=src

if [ ! -d "$SRC" ]; then
	echo "Falta $SRC. Correr desde la raiz del repo." >&2
	exit 2
fi

FOUND=0

for f in $(find "$SRC" \( -name '*.inc' -o -name '*.php' \) | sort); do
	# Las funciones del arbol abren y cierran en la columna 0, que es lo que
	# delimita el cuerpo aca.
	OUT=$(awk -v F="$f" '
		/^function[ &]/ {
			infn = 1
			name = $0
			sub(/^function[ &]*/, "", name)
			sub(/\(.*/, "", name)
			hasglobal = 0
			uses = 0
			next
		}
		infn && /^}/ {
			if (uses && !hasglobal) {
				printf "  %s : %s()\n", F, name
			}
			infn = 0
			next
		}
		infn {
			# Un $awgg nombrado en un comentario no lo lee nadie. Sin esto,
			# explicar en un comentario por que una funcion NO mira el globals
			# la hace aparecer como si lo usara mal.
			line = $0
			sub(/\/\/.*/, "", line)
			sub(/^[ \t]*\*.*/, "", line)
			sub(/\/\*.*\*\//, "", line)

			if (line ~ /global[^;]*\$awgg/) { hasglobal = 1 }
			if (line ~ /\$awgg/) { uses = 1 }
		}
	' "$f")

	if [ -n "$OUT" ]; then
		if [ "$FOUND" -eq 0 ]; then
			echo "Funciones que usan \$awgg sin declararlo global:"
			FOUND=1
		fi
		echo "$OUT"
	fi
done

if [ "$FOUND" -eq 1 ]; then
	echo
	echo "Agregar 'global \$awgg;' al principio de cada una. Llamar"
	echo "awg_globals() no alcanza: puebla la global, no el scope local."
	exit 1
fi

echo "Todas las funciones que usan \$awgg lo declaran global."
