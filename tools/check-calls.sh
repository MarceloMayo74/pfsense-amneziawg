#!/bin/sh
#
# check-calls.sh - Busca llamadas a funciones awg_* que no existen.
#
#   sh tools/check-calls.sh
#
# Existe porque php -l NO detecta esto: una llamada a una funcion inexistente
# es sintacticamente valida y solo revienta cuando la linea llega a ejecutarse.
# Paso de verdad -- awg_apply_list_apply(), inventada al escribir la pagina de
# peers-- y el sintoma fue un fatal en produccion DESPUES de guardar el peer,
# con el peer ya escrito en config.xml y la pagina muerta antes de redirigir.
#
# El prefijo awg_ es lo que hace esto posible: todas esas funciones son de este
# paquete, asi que cualquiera que se llame y no se declare en src/ es un error.
# Las funciones de pfSense y de PHP no llevan el prefijo y quedan afuera solas.

set -u

SRC="$(dirname "$0")/../src"

if [ ! -d "$SRC" ]; then
	echo "No se encontro src/ al lado de tools/" >&2
	exit 2
fi

# Lo que el arbol declara. El '&' opcional es por las que devuelven por
# referencia -- function &awg_array_get_value() --, que sin el quedaban como
# inexistentes y hacian ruido en cada corrida.
DECLARADAS=$(grep -rhoE '^[[:space:]]*function[[:space:]]+&?awg_[a-zA-Z0-9_]*' "$SRC" \
	| sed 's/.*function[[:space:]]*&\{0,1\}//' | sort -u)

# Lo que el arbol llama. Se descartan las declaraciones para no contarlas como
# llamadas, y se ignora "function awg_x(" en la misma pasada.
LLAMADAS=$(grep -rhoE '(^|[^a-zA-Z0-9_$>])awg_[a-zA-Z0-9_]*[[:space:]]*\(' "$SRC" \
	| grep -oE 'awg_[a-zA-Z0-9_]*' | sort -u)

FALTAN=""

for _f in $LLAMADAS; do
	if ! echo "$DECLARADAS" | grep -qx "$_f"; then
		FALTAN="$FALTAN $_f"
	fi
done

# Un puñado de nombres son de la clase awgconfig, no funciones sueltas.
FALTAN=$(echo "$FALTAN" | tr ' ' '\n' | grep -v '^$' | grep -vx 'awgconfig' || true)

if [ -z "$FALTAN" ]; then
	printf 'Todas las funciones awg_* que se llaman estan declaradas.\n'
	exit 0
fi

printf 'Se llaman funciones awg_* que no existen:\n\n'

for _f in $FALTAN; do
	printf '  %s()\n' "$_f"

	grep -rn "$_f" "$SRC" | grep -v "function[[:space:]]*$_f" | sed 's|^|      |' | head -3
done

printf '\nphp -l no ve esto: revienta recien cuando la linea se ejecuta.\n'

exit 1
