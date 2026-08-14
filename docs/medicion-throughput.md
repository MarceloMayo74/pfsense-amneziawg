# Throughput de amneziawg-go en el hardware objetivo

Medido el 14-08-2026 sobre el firewall de 2.9.0-BETA con `spike/throughput.sh`.
Cierra el riesgo abierto de la fase 1, que había validado latencia pero nunca
caudal.

**Hardware:** Intel i5-3570 (4 núcleos, 3.4 GHz, Ivy Bridge), 8 GB, pfSense
2.9.0-BETA / FreeBSD 16.0-CURRENT.

## Resultado

Mediana de 3 ventanas de 10 s. El caudal es payload puro: paquetes aceptados por
1392 B, sin ningún encabezado.

| escenario | Mbps | pps | cores | Mbps/core | rechazado |
|---|---|---|---|---|---|
| `wg-kernel` (if_wg) | 1483 | 133 155 | 2,38 | 623 | 0 % |
| `awg-plano` | 828 | 74 310 | 3,40 | 243 | 21 % |
| `awg-ofuscado` | 835 | 74 985 | 3,41 | 245 | 19 % |

En los escenarios `awg`, la CPU se reparte en **1,72 cores cifrando y 1,42
descifrando**.

## Las tres conclusiones

**1. El caudal alcanza de sobra.** ~830 Mbps de payload *pagando las dos mitades
de la criptografía en la misma caja*. Un pfSense contra clientes remotos paga
una sola mitad por paquete: cifrar costó 1,72 cores para esos 830 Mbps, o sea
~480 Mbps por core de cifrado. Para un enlace doméstico o de oficina chica,
`amneziawg-go` no es el cuello de botella.

**2. La ofuscación no cuesta caudal.** 835 contra 828 Mbps y la misma CPU al
decimal: la diferencia es ruido. Tiene explicación en el diseño de AmneziaWG
1.x — `Jc`/`Jmin`/`Jmax` son paquetes basura *previos al handshake* y `S1`/`S2`
es relleno *del handshake*; los paquetes de datos son WireGuard común con otro
valor de tipo (`H4`). La ofuscación se paga en el handshake, no en el tráfico.
Esto es una medición, no una lectura del código.

**3. Userspace cuesta 2,5x.** El kernel mueve 1,78x más tráfico usando menos
CPU: 623 contra 244 Mbps por core. Es el precio de la decisión —ya tomada y con
evidencia— de no usar el módulo de kernel, que no carga en pfSense.

El techo de paquetes por segundo es ~75 000. Con tráfico de paquetes chicos ese
límite pega antes que el de Mbps.

## Cómo está medido, y por qué así

Lo obvio no funciona, y hay tres trampas que solo se ven midiendo:

**Dos túneles en la misma caja no se hablan por el túnel.** Con una sola tabla
de ruteo, la dirección del otro extremo es local y el kernel manda todo por
`lo0`. Conviene releer con esto en la mano la fase 5 de `spike/fase1-bringup.sh`:
aquel ping entre `10.253.253.1` y `.2` probablemente nunca pasó por el túnel. No
invalida la fase 1 — lo que prueba que la criptografía anda es el handshake, no
el ping. VIMAGE está en el kernel pero pfSense no trae `jail`/`jexec`, así que la
salida de dos stacks no estaba disponible.

**No existe "solo cifrar" contra un sumidero.** WireGuard no cifra un byte antes
de completar el handshake: encola y descarta. Hace falta un peer real.

**Y con un peer real aparece un bucle.** B descifra, inyecta el paquete, el
kernel lo rutea otra vez hacia el túnel de A y da vueltas hasta agotar el TTL,
multiplicando la carga. El montaje lo rompe adentro del proceso: a B se le
declara el peer A con `AllowedIPs` que **no** contienen la dirección origen, así
que B descifra —paga el costo entero— y descarta por cryptokey routing sin tocar
el stack. El harness no lo supone: con el generador parado verifica que el
contador de la interfaz esté quieto.

El generador es `spike/gen-udp.php` y no `nc`, por dos razones medidas: `nc` se
muere al primer `ENOBUFS` —que no es una falla sino la señal de saturación— y
arma los datagramas con lo que le entra del pipe, ignorando el `bs` de `dd`. El
generador propio ignora los errores de escritura, los cuenta (es la columna
`rechazado`) y manda paquetes de tamaño exacto.

Dos detalles que evitan conclusiones falsas:

- El caudal se calcula **contando paquetes**, no bytes: para el mismo tráfico
  `wg(4)` informa 1452 B por paquete y el `tun` informa 1424. Comparar por bytes
  le regalaba un 2% al kernel que era contabilidad, no rendimiento.
- El generador va clavado a la CPU 0 y su CPU se **resta**. Clavar el túnel a
  las otras tres sería tentador pero solo se puede del lado de `amneziawg-go`:
  `if_wg` cifra en hilos de kernel que no se pueden clavar, y clavar un lado sí
  y el otro no es comparar dos cosas distintas.

## Sobre el 0 % rechazado del kernel

El escenario de kernel nunca produce `ENOBUFS`: aplica contrapresión durmiendo
al emisor en vez de rechazar. Para descartar que el número fuera un artefacto
del instrumento se repitió con 3 generadores: 1563 contra 1529 Mbps, +2%, con
los generadores al 56% de su core. El límite es el túnel, no el generador.

La variación entre corridas del escenario de kernel es de ~5% (1483-1563 Mbps)
según la carga previa de la caja.

## Reproducir

```sh
scp spike/throughput.sh spike/gen-udp.php admin@FIREWALL:/root/
ssh admin@FIREWALL 'sh /root/throughput.sh -s 10 -r 3'
```

Opciones: `-s` segundos de ventana, `-r` repeticiones, `-g` generadores, `-o` un
solo escenario. No toca pf, ni `config.xml`, ni ninguna interfaz existente; se
planta si `tun9000`/`tun9001`/`wg9000`/`wg9001` ya existen y limpia todo al
salir. Borrar después los dos archivos de `/root/`.
