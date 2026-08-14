#!/usr/local/bin/python3.11
# sticky-probe.py - corre EN EL FIREWALL. Valida la API de sticky sockets de
# FreeBSD antes de portarla a amneziawg-go (docs/plan-sticky-freebsd.md).
#
#   scp spike/sticky-probe.py admin@FIREWALL:/root/
#   ssh admin@FIREWALL 'python3.11 /root/sticky-probe.py'
#
# Prueba las dos mitades del mecanismo, contra el kernel real y sin tocar red
# externa (todo es entrega local entre dos sockets de la misma maquina):
#
#   1. IP_RECVDSTADDR: un socket ligado a *:puerto recibe un paquete y el cmsg
#      dice A CUAL de las direcciones de la maquina vino dirigido.
#   2. IP_SENDSRCADDR: la respuesta puede forzar el origen a esa direccion,
#      aunque el ruteo hubiera elegido otra.
#
# Si esto no pasa, el port a amneziawg-go no puede andar y mejor saberlo aca,
# donde son 100 lineas de python y no un binario de Go.
#
# No escribe nada, no toca configuracion, usa puertos efimeros.

import socket
import struct
import subprocess
import sys

IP_RECVDSTADDR = getattr(socket, 'IP_RECVDSTADDR', 7)
IP_SENDSRCADDR = getattr(socket, 'IP_SENDSRCADDR', IP_RECVDSTADDR)  # mismo valor en FreeBSD

passed = failed = 0

def check(name, cond, detail=''):
    global passed, failed
    if cond:
        passed += 1
        print(f'  ok   {name}')
    else:
        failed += 1
        print(f'  FAIL {name}  {detail}')

def local_ipv4_addresses():
    out = subprocess.run(['ifconfig', '-a', 'inet'], capture_output=True, text=True).stdout
    addrs = []
    for line in out.splitlines():
        line = line.strip()
        if line.startswith('inet '):
            ip = line.split()[1]
            if not ip.startswith('127.'):
                addrs.append(ip)
    return addrs

addrs = local_ipv4_addresses()
print(f'\n  direcciones IPv4 de la maquina: {", ".join(addrs)}\n')

if len(addrs) < 2:
    print('  hacen falta dos direcciones para probar la parte interesante')
    sys.exit(2)

# El "servidor": ligado a *:efimero, como amneziawg-go
srv = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
srv.setsockopt(socket.IPPROTO_IP, IP_RECVDSTADDR, 1)
srv.bind(('0.0.0.0', 0))
srv.settimeout(5)
port = srv.getsockname()[1]

# El "cliente"
cli = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
cli.bind(('0.0.0.0', 0))
cli.settimeout(5)

print('-- IP_RECVDSTADDR: saber a que direccion vino el paquete --\n')

seen = {}
for dst in addrs[:2]:
    cli.sendto(b'probe-' + dst.encode(), (dst, port))
    data, ancdata, flags, src = srv.recvmsg(512, 512)

    got = None
    for level, ctype, cdata in ancdata:
        if level == socket.IPPROTO_IP and ctype == IP_RECVDSTADDR:
            got = socket.inet_ntoa(cdata[:4])

    seen[dst] = (got, src)
    check(f'el cmsg dice {dst} cuando se le hablo a {dst}',
          got == dst, f'cmsg={got} ancdata={ancdata}')

check('y distingue una direccion de la otra',
      len({v[0] for v in seen.values()}) == len(seen))

print('\n-- IP_SENDSRCADDR: responder con un origen forzado --\n')

# La respuesta fuerza como origen la OTRA direccion, la que el ruteo local no
# elegiria para este destino. Es exactamente lo que amneziawg-go necesita.
reply_src = addrs[1]
_, cli_src = seen[addrs[0]]

try:
    n = srv.sendmsg([b'reply-forzado'],
                    [(socket.IPPROTO_IP, IP_SENDSRCADDR, socket.inet_aton(reply_src))],
                    0, cli_src)
    check('sendmsg con IP_SENDSRCADDR no falla', n > 0)
except OSError as e:
    check('sendmsg con IP_SENDSRCADDR no falla', False, str(e))
    print(f'\n{passed} pasaron, {failed} fallaron\n')
    sys.exit(1)

data, src = cli.recvfrom(512)

check('la respuesta llega', data == b'reply-forzado')
check(f'y llega con el origen forzado ({reply_src}), no el del ruteo',
      src[0] == reply_src, f'vino de {src[0]}')

print('\n-- el cmsg recibido sirve tal cual para enviar --\n')

# En FreeBSD IP_SENDSRCADDR es IP_RECVDSTADDR: el port guarda el cmsg crudo y
# lo reenvia sin transformarlo. Verificado aca por si un dia dejan de ser el
# mismo numero.
check('IP_SENDSRCADDR == IP_RECVDSTADDR', IP_SENDSRCADDR == IP_RECVDSTADDR,
      f'{IP_SENDSRCADDR} != {IP_RECVDSTADDR}')

cli.sendto(b'roundtrip', (addrs[1], port))
data, ancdata, flags, src2 = srv.recvmsg(512, 512)

try:
    n = srv.sendmsg([b'reply-crudo'], ancdata, 0, src2)
    data, src = cli.recvfrom(512)
    check('reenviar el ancdata recibido, intacto, funciona',
          data == b'reply-crudo' and src[0] == addrs[1],
          f'vino de {src[0]}')
except OSError as e:
    check('reenviar el ancdata recibido, intacto, funciona', False, str(e))

srv.close()
cli.close()

print(f'\n{passed} pasaron, {failed} fallaron\n')
sys.exit(1 if failed else 0)
