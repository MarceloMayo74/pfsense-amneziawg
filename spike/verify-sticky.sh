#!/bin/sh
# verify-sticky.sh - corre EN EL FIREWALL. Comprueba que el binario parcheado
# con sticky sockets (docs/plan-sticky-freebsd.md) arranca, crea su interfaz y
# habilita las opciones de socket -- todo en un tunel DE DESCARTE.
#
#   scp spike/verify-sticky.sh admin@FIREWALL:/root/
#   ssh admin@FIREWALL 'sh /root/verify-sticky.sh'
#
# No toca ningun tunel configurado: usa tun9099, que no existe en la config.
# Lo levanta, mide, y lo baja con SIGTERM (que es lo unico que limpia la
# interfaz sola, hallazgo de la fase 4).
#
# Lo que se prueba aca es que el parche no rompio el arranque: si setsockopt
# devolviera error, controlfns aborta el Open() y el proceso no llega a crear
# la interfaz. La prueba de que sirve de verdad es un cliente remoto entrando
# por la WAN que no tiene el default gateway.

IF=tun9099
BIN=/usr/local/bin/amneziawg-go
RUN=/var/run/amneziawg

pass=0
fail=0

check() {
	if [ "$2" = "0" ]; then
		pass=$((pass + 1))
		echo "  ok   $1"
	else
		fail=$((fail + 1))
		echo "  FAIL $1  $3"
	fi
}

echo ""
echo "-- el binario --"
echo ""

VER=$($BIN --version 2>&1 | head -1)
echo "  --   version: $VER"

echo "$VER" | grep -q 'sticky' && check "es el binario parcheado" 0 \
	|| check "es el binario parcheado" 1 "$VER"

echo ""
echo "-- arranque en un tunel de descarte ($IF) --"
echo ""

ifconfig $IF >/dev/null 2>&1 && { echo "  $IF ya existe, abortando"; exit 2; }

WG_PROCESS_FOREGROUND=0 LOG_LEVEL=verbose $BIN $IF >/tmp/sticky-probe.log 2>&1
RC=$?

check "el proceso arranca sin error" $RC "rc=$RC"

# El socket UAPI tarda ~100 ms en aparecer (fase 1)
i=0
while [ $i -lt 30 ]; do
	[ -S "$RUN/$IF.sock" ] && break
	sleep 0.2
	i=$((i + 1))
done

[ -S "$RUN/$IF.sock" ] && check "crea su socket UAPI" 0 || check "crea su socket UAPI" 1 "no aparecio $RUN/$IF.sock"

ifconfig $IF >/dev/null 2>&1 && check "crea su interfaz" 0 || check "crea su interfaz" 1 "$IF no existe"

PID=$(ps auxww | grep -v grep | grep "$BIN $IF" | awk '{print $2}' | head -1)

[ -n "$PID" ] && check "el proceso sigue vivo" 0 || check "el proceso sigue vivo" 1 "no hay pid"

echo ""
echo "-- las opciones de socket, contra el kernel --"
echo ""

# Un setsockopt fallido aborta Open() y no habria socket UDP ligado.
if [ -n "$PID" ]; then
	PORTS=$(sockstat -4 -6 -u -p 1-65535 2>/dev/null | awk -v p="$PID" '$3 == p {print $6}')

	[ -n "$PORTS" ] && check "tiene sus sockets UDP ligados" 0 \
		|| check "tiene sus sockets UDP ligados" 1 "sockstat no lista nada para pid $PID"

	echo "  --   sockets: $(echo $PORTS | tr '\n' ' ')"
fi

# En el log verbose, un fallo de controlfns sale como error de Open()
grep -qi 'error\|panic\|failed' /tmp/sticky-probe.log \
	&& check "el log no tiene errores" 1 "$(grep -i 'error\|panic\|failed' /tmp/sticky-probe.log | head -2)" \
	|| check "el log no tiene errores" 0

echo ""
echo "-- baja limpia --"
echo ""

if [ -n "$PID" ]; then
	kill -TERM "$PID" 2>/dev/null
	sleep 1

	ps -p "$PID" >/dev/null 2>&1 && check "SIGTERM lo termina" 1 "sigue vivo" || check "SIGTERM lo termina" 0
	ifconfig $IF >/dev/null 2>&1 && check "y se lleva la interfaz" 1 "$IF quedo colgada" || check "y se lleva la interfaz" 0
	[ -S "$RUN/$IF.sock" ] && check "y su socket" 1 "quedo el .sock" || check "y su socket" 0
fi

rm -f /tmp/sticky-probe.log

echo ""
echo "$pass pasaron, $fail fallaron"
echo ""

[ $fail -gt 0 ] && exit 1
exit 0
