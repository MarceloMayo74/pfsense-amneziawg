# Sticky sockets para FreeBSD en amneziawg-go

> **Estado al 14-08-2026: RESUELTO y verificado de punta a punta.** El teléfono
> conecta por **las dos WAN**, incluida la que no tiene el default gateway.
> Mandado upstream el 14-08-2026: [amnezia-vpn/amneziawg-go#180][pr].

[pr]: https://github.com/amnezia-vpn/amneziawg-go/pull/180

**Objetivo.** Que `amneziawg-go` responda desde la misma dirección por la que
llegó el paquete, como hace `if_wg` en el kernel. Con eso el paquete anda en
multi-WAN sin reglas extra: la respuesta con el origen correcto matchea el
estado de pf de la regla WAN, y el `reply-to` que pfSense pone solo la rutea
por la interfaz correcta — el mismo mecanismo por el que OpenVPN funciona en
una WAN secundaria. Es el TODO abierto de upstream en `conn/sticky_default.go`.

**Por qué es viable.** El transporte genérico de `bind_std.go` ya llama
`getSrcFromControl()` al recibir y `setSrcControl()` al enviar en TODAS las
plataformas (el camino no-Linux usa `ReadMsgUDP`/`WriteMsgUDP` con OOB). Solo
las implementaciones están vacías fuera de Linux. FreeBSD tiene la API
necesaria:

| | IPv4 | IPv6 |
|---|---|---|
| habilitar | `IP_RECVDSTADDR` | `IPV6_RECVPKTINFO` |
| cmsg al recibir | `in_addr` (4 bytes) | `in6_pktinfo` (RFC 3542) |
| cmsg al enviar | `IP_SENDSRCADDR` | `IPV6_PKTINFO` |

Detalle que simplifica todo: en FreeBSD `IP_SENDSRCADDR == IP_RECVDSTADDR`
(mismo valor, 7), así que el cmsg recibido se puede reenviar **tal cual** — el
mismo truco que usa la implementación de Linux guardando el cmsg crudo en
`ep.src`. IPv6 es simétrico de por sí.

## Fases

1. **Sonda de la API, antes de tocar Go. ✅** Un script Python en el firewall
   (`spike/sticky-probe.py`): socket UDP wildcard, `IP_RECVDSTADDR`, recibir un
   paquete dirigido a una dirección concreta, verificar que el cmsg traiga esa
   dirección, y responder con `IP_SENDSRCADDR` forzando el origen. Valida
   constantes, layout y semántica contra el kernel real. Si esto no pasa, el
   port no va a andar y mejor saberlo antes de escribir nada.

   **8/8 sobre el firewall.** El caso que importa salió tal cual: un paquete
   dirigido a `198.51.100.1` se identifica como tal, y la respuesta puede
   forzarse a salir con ese origen aunque el ruteo hubiera elegido el otro.
   Y quedó confirmado que `IP_SENDSRCADDR == IP_RECVDSTADDR`, que es lo que
   permite reenviar el cmsg recibido intacto.

2. **El parche. ✅** Cuatro archivos en `patches/amneziawg-go/`:
   - `sticky_freebsd.go` — nuevo: `SrcIP()`, `SrcIfidx()`, `SrcToString()`,
     `getSrcFromControl()`, `setSrcControl()`, `stickyControlSize`,
     `StdNetSupportsStickySockets = true`. Calcado del de Linux, con el caso
     IPv4 leyendo `in_addr` en vez de `in_pktinfo`.
   - `controlfns_freebsd.go` — nuevo: `init()` que agrega los dos setsockopt.
   - `sticky_default.go` — un cambio de una línea: el build tag pasa de
     `!linux || android` a `(!linux && !freebsd) || android`.
   - `sticky_freebsd_test.go` — los tests del de Linux, adaptados a las formas
     de FreeBSD, más un round trip contra el kernel.
   Los archivos canónicos viven en el repo; `reference/` sigue siendo de
   terceros y se les copian encima al compilar.

3. **Build reproducible. ✅** `tools/build-amneziawg-go.ps1`: toolchain de Go
   portable en `.tools/go` (no versionado), checkout del tag que corresponde al
   binario que veníamos empaquetando, aplicar el parche, y
   `GOOS=freebsd GOARCH=amd64 CGO_ENABLED=0 go build` → `bin/<ABI>/amneziawg-go`.
   Sin CGO no hace falta ninguna toolchain de FreeBSD. El binario se marca como
   `0.0.20250522-sticky1` para reconocerlo a simple vista.

4. **Verificación en el firewall.**
   - **Los tests del paquete `conn`, compilados para FreeBSD y corridos ahí ✅.**
     `-Test` deja `.tools/sticky.test`. Pasan todos, incluido el round trip que
     comprueba las cuatro piezas juntas: que `listenConfig()` habilite
     `IP_RECVDSTADDR`, que se recupere la dirección destino, que se arme el
     cmsg, y que `sendmsg` responda con el origen fijado.

     Ese round trip encontró algo que hay que saber: **`IP_SENDSRCADDR` da
     `EINVAL` sobre un socket atado a una dirección concreta.** Solo sirve con
     bind wildcard — que es lo que hace `StdNetBind.Open()`, así que no afecta
     al paquete, pero invalida cualquier test que ate a `127.0.0.1`.
   - **El binario arranca ✅.** `spike/verify-sticky.sh` levanta un túnel de
     descarte (`tun9099`), comprueba socket UAPI e interfaz, y lo baja con
     SIGTERM. Si algún `setsockopt` fallara, `Open()` abortaría y no habría
     interfaz.
   - **La prueba de verdad ✅:** el teléfono conecta por **las dos WAN**. Con el
     peer de `mayosystems` —la PPPoE, que NO tiene el default gateway— el
     handshake cierra y pasa tráfico real (1,25 MiB en la primera prueba).

     Las tres predicciones se cumplieron:

     | | antes | después |
     |---|---|---|
     | estados de pf | dos: uno entrante y uno saliente por `igb0` con origen `192.168.0.117` | **uno**, `pppoe0 udp 198.51.100.1:51822 <- cliente`, `MULTIPLE:MULTIPLE` |
     | salida en `pppoe0` | cero paquetes en 4 minutos | `198.51.100.1.51822 > cliente` |
     | handshake | `never`, con los contadores moviéndose | cierra en el primer intento |

     El estado único es lo que importa estructuralmente: la respuesta ahora
     matchea el estado de la regla de WAN, así que el `reply-to` que pfSense ya
     pone la rutea sola. Sin reglas agregadas.

5. **Upstream. ✅** [amnezia-vpn/amneziawg-go#180][pr], sobre `master`, cuatro
   archivos y tres líneas tocadas en uno existente. Aplica idéntico a
   `wireguard-go`, que es de donde vienen los archivos. Hasta que lo tomen, el
   `.pkg` empaqueta nuestro build — que ya hace hoy, porque los binarios van
   adentro.

   Dos cosas que salieron al prepararlo, por si hay que rehacerlo: los archivos
   del repo están en CRLF y hay que pasarlos a LF o el diff se ve como un
   archivo reescrito entero; y `GOOS=freebsd go build ./...` sobre el `master`
   de upstream falla hoy en `golang.getoutline.org/sdk/x/smart`
   (`undefined: lookupCNAME`), o sea que el repo no compila para FreeBSD por
   una razón ajena a esto. El paquete `conn`, que es el único que tocamos,
   compila y pasa `vet` en freebsd, linux, windows, darwin y openbsd.

## Riesgos

- **El estado if-bound de pf.** El precedente de OpenVPN en WAN secundaria dice
  que reply-to alcanza; si no alcanzara, el fallback es la regla flotante
  route-to, pero documentada como parte del paquete, no como tarea del usuario.
- **Direcciones que cambian.** Si la WAN cambia de IP, el `ep.src` guardado
  queda viejo y `sendmsg` da `EADDRNOTAVAIL` hasta que el peer vuelva a hablar.
  Mismo comportamiento que Linux; el roaming del protocolo lo repara solo.
- **Versión del binario.** Se compila del tag estable que empaqueta el port de
  FreeBSD, no de master, para que el único delta contra lo ya validado sea el
  parche.
