# AmneziaWG 3.0 / 3.1 en este paquete

> **Estado al 17-08-2026: fase 1 cerrada.** Los dos binarios 3.1 están
> compilados, verificados y puestos en el firewall, y ahí
> `awg_backend_version()` devuelve **3**. El PHP no se tocó: el paquete sigue
> escribiendo hasta 2.0, así que el selector no ofrece el nivel nuevo todavía.
> Sigue la fase 2.

Todo lo que sigue está leído del árbol 3.1 que hay en `reference/`
(`amneziawg-go` en `08271d0`, tag `v3.1.20260812`; `amneziawg-tools` en
`ee0f0a9`, mismo tag), no de la documentación de Amnezia — que al día de hoy
dice que lo de 3.0 se documenta "después" del soporte self-hosted.

## Decisión: un solo paquete, no uno "3.0-only"

El backend 3.x es un superconjunto estricto del 2.0, y eso está en el código:

- `HeaderProtectionCipher()` devuelve `nil, nil` si la clave está en cero
  (`device/noise-protocol.go`), así que sin `HeaderProtectionKey` no hay
  protección de headers y el paquete sale igual que antes.
- `randomPaddingAddition()` devuelve `-1` con `ContentPaddingAddition` en cero,
  y `randomTrailer()` lo mismo con `RandomTrailers` apagado (`device/send.go`).
- El `config.c` de 3.1 sigue parseando todas las claves de 1.x y 2.0.

O sea: un binario 3.1 con un `.conf` de 2.0 emite exactamente los mismos bytes
que el binario que empaquetamos hoy. Partir el paquete en dos duplicaría la
escalera de niveles y no resolvería nada, porque el problema que la escalera
existe para resolver —qué entiende el extremo más débil— no desaparece por
tener un paquete aparte. Se extiende la escalera y listo.

## Qué agrega 3.x

Las claves nuevas de `[Interface]`, con el tipo que les da el parser de
amneziawg-tools:

| Clave | Tipo en `config.c` | Nivel |
|---|---|---|
| `HeaderProtectionKey` | `parse_key` — 32 bytes en base64 | 3.0 |
| `ContentPaddingAddition` | `u16_range_from_string` — `n` o `n-m` | 3.0 |
| `RekeyAfterTime` | rango u16 | 3.0 |
| `RekeyTimeout` | rango u16 | 3.0 |
| `RejectAfterTime` | rango u16 | 3.0 |
| `KeepaliveTimeout` | rango u16 | 3.0 |
| `MaxHandshakeAttempts` | rango u16 | 3.0 |
| `RandomTrailers` | `parse_bool` — `on`/`off` | 3.1 |
| `DisableCookies` | `parse_bool` — `on`/`off` | 3.1 |

Dos de ellas no son parámetros como los de 2.0:

- **`HeaderProtectionKey` es un secreto compartido**, no un número sorteado: es
  la clave con la que ChaCha20 cifra el header del paquete. Va en los dos
  extremos y por lo tanto también en el `.conf` del cliente. Volver a sortearla
  invalida todos los `.conf` ya entregados de ese túnel.
- **`ContentPaddingAddition` se paga en el camino de datos**, igual que S4:
  `randomPaddingAddition()` recorta contra el MTU pero suma bytes en cada
  paquete. Mismo criterio que S4 — vacío por defecto, prenderlo es una
  decisión, no un default.

`PersistentKeepalive`, del lado del peer, también pasó a aceptar un rango.

## Lo que ya está resuelto

La arquitectura de niveles se escribió contando hasta 3, así que nada de esto
hay que inventarlo:

- `awg_backend_version()` ya sondea `ContentPaddingAddition` para el nivel 3.
- `awg_version_ceiling()` es el `min()` del backend con lo que el paquete sabe
  escribir, y el selector de la página ya explica por separado cuál de las dos
  razones tapa un nivel.
- `awg_obfuscation_pairs()` es el único lugar autoritativo del filtro por
  nivel, y lo usan los dos `.conf` (servidor y cliente).
- `awg_tunnel_version()` topea lo guardado en `config.xml` contra el techo.

## Fase 1 — los binarios

Es el 80% del riesgo y va primero, porque si el build no cierra no tiene
sentido haber gastado nada del resto.

**El parche de sticky sockets sigue haciendo falta.** En el árbol 3.1,
`conn/sticky_default.go` conserva el build tag `!linux || android`,
`getSrcFromControl()` y `setSrcControl()` siguen vacíos,
`StdNetSupportsStickySockets` sigue en `false` y el TODO sigue diciendo
palabra por palabra que FreeBSD "likely does support" la API pero necesita
port y pruebas. [amnezia-vpn/amneziawg-go#180][pr] sigue **abierto** (última
actividad 14-08-2026). Sin el parche, un túnel sobre una WAN sin default
gateway no levanta — que es exactamente el problema que costó resolver en
agosto. Ver [plan-sticky-freebsd.md](plan-sticky-freebsd.md).

[pr]: https://github.com/amnezia-vpn/amneziawg-go/pull/180

La buena noticia es que el parche debería aplicar casi tal cual: los dos
archivos que pisamos tienen en 3.1 los mismos build tags que en la base actual
(`sticky_default.go` = `!linux || android`, `controlfns_unix.go` =
`!windows && !linux && !wasm`), y `conn/` se movió 6 archivos y unas 28 líneas
entre nuestra base y HEAD. El único cambio con nombre sospechoso —
`boundif_android.go` renombrado a `boundif.go`, "make PeekLookAtSocketFd
available across all platforms" — no toca sticky: expone el fd del socket y
usa solo `SyscallConn()`, portable, sin build tag.

### Lo que ya se hizo (17-08-2026)

`amneziawg-go` **3.1 compilado y verificado**. El parche entró sin un solo
ajuste, `go vet ./conn/` limpio, y el binario quedó en `bin/FreeBSD-16-amd64/`
estampado `3.1.20260814-sticky1` — el sello ahora sale del tag, porque el
`version.go` de upstream sigue diciendo `0.0.20250522` hasta en 3.1 y no
distingue nada.

Verificado sobre el firewall, en este orden:

1. **Los tests del paquete `conn`, corridos en FreeBSD 16: 100% pasan**,
   incluido `Test_stickyRoundTrip`, que va contra el kernel de verdad. El
   multi-WAN sigue en pie sobre el árbol 3.1.
2. **`spike/fase1-bringup.sh` con el binario nuevo y el `awg` 2.0 del port**:
   los dos túneles levantan, `setconf` acepta la ofuscación, handshake en 1 s,
   ping por el túnel, y S3/S4/I1 aceptados.
3. **`spike/verify-awg2.php` contra el daemon 3.1: 26 de 26.** Un túnel 2.0
   completo se aplica y `awg show` devuelve S3, S4, I1 e I5 bien.
4. **La detección no se confunde**: con go 3.1 y `awg` 2.0,
   `awg_backend_version()` sigue devolviendo 2, porque la sonda le pregunta a
   `awg`, que es quien parsea el `.conf`.

De ahí sale un resultado que vale la pena tener escrito: **go 3.1 y `awg` 2.0
conviven**. El UAPI de 3.x conserva todas las claves de 2.0 con el mismo nombre
—lo nuevo es aditivo— y el parser de `awg` ignora en silencio las claves que no
conoce. O sea que se puede empaquetar el backend nuevo hoy, con el techo
todavía en 2, sin esperar nada.

### `awg` 3.1: no hay binario, hay que compilarlo

El port `net/amnezia-tools` sigue en **1.0.20250903** (último commit
18-12-2025), los releases de Amnezia publican Alpine, Ubuntu y Windows nada
más, y el firewall no sirve como máquina de build: pfSense no trae `cc`, `ld`
ni `/usr/include`. Se compila en una **VM de FreeBSD 16** con
`tools/build-awg-freebsd.sh`, que baja el tarball del tag, compila con `gmake`
—el Makefile de upstream es de GNU make— y deja el binario junto a la fuente
exacta y los dos sha256.

Eso cambia la historia de licencias y hay que reflejarlo: hoy `NOTICE` dice que
el `awg` que distribuimos es el del port, sin modificar. Pasa a ser un binario
propio compilado de un tag de upstream sin parches, y el tarball de fuente que
se adjunta al release ya no lo arma `tools/fetch-sources.sh` sino este script.

#### La VM, en Proxmox

No hace falta instalar desde ISO: FreeBSD publica imágenes de VM de
16.0-CURRENT, que es exactamente la rama de pfSense 2.9 (el firewall reporta
`16.0-CURRENT`, `kern.osreldate` 1600018, ABI `FreeBSD:16:amd64`). En el host
de Proxmox:

```sh
cd /var/lib/vz/template/iso
wget https://download.freebsd.org/snapshots/VM-IMAGES/16.0-CURRENT/amd64/Latest/FreeBSD-16.0-CURRENT-amd64-ufs.qcow2.xz
xz -d FreeBSD-16.0-CURRENT-amd64-ufs.qcow2.xz

VMID=9016                      # el que esté libre
STORE=local-lvm                # confirmar con: pvesm status

qm create $VMID --name freebsd16-build --memory 4096 --cores 4 \
    --net0 virtio,bridge=vmbr0 --scsihw virtio-scsi-single --ostype other
qm importdisk $VMID FreeBSD-16.0-CURRENT-amd64-ufs.qcow2 $STORE
qm set $VMID --scsi0 $STORE:vm-$VMID-disk-0 --boot order=scsi0
qm resize $VMID scsi0 +8G
qm start $VMID
```

UFS y no ZFS: es una VM descartable para compilar, ZFS solo le pide más RAM.
Dentro, por la consola de Proxmox (root entra sin contraseña):

```sh
passwd
service netif restart          # la imagen ya trae ifconfig_DEFAULT=DHCP
pkg install -y gmake
sysrc sshd_enable=YES && service sshd start
```

La VM sirve para las dos cosas: compilar `awg` y, más adelante, volver a
compilarlo cuando 3.2 salga.

#### Lo que costó, para no volver a descubrirlo

Tres cosas, ninguna prevista:

1. **La imagen CLOUDINIT de FreeBSD 16 se cuelga.** Arranca, toma DHCP y ahí se
   queda: cloud-init no termina, así que `rc` nunca llega a levantar `sshd` ni
   los getty. Con la imagen simple (`FreeBSD-16.0-CURRENT-amd64-ufs.qcow2`)
   arranca en 40 segundos. Esa imagen saca consola por **video**, no por serie,
   así que para el primer login hay que ir por la consola de Proxmox.
2. **Desde esta red no se alcanza `pkg.freebsd.org`.** Las IPs del mirror
   (151.101.x.**241**) no contestan ni en 80 ni en 443, ni desde la VM ni desde
   el propio Proxmox, mientras `download.freebsd.org` (151.101.x.**242**) anda
   bien. O sea que en la VM no hay `pkg install` de nada. Por eso el script
   compila sin `gmake`. GitHub, además, contestó 429 un rato: por eso existe
   `SRC_TARBALL`.
3. **`ipc-freebsd.h` de 3.1 no compila.** Quedó escrito contra el
   `containers.h` viejo —usa `dev->init_packet_magic_header`, que era una
   cadena, cuando en 3.x H1-H4 son `u32_range_t init_header`, y usa
   `MAX_AWG_STRING_LEN`, que ya no existe—. Upstream no lo nota porque solo
   publica binarios de Linux, Alpine y Windows. El script lo desactiva con
   `-DAWG_NO_KERNEL_IPC`: es el camino que habla con `if_amn.ko`, que en
   pfSense no carga, y las tres llamadas ya estaban detrás de
   `IPC_SUPPORTS_KERNEL_INTERFACE`. **Vale la pena reportarlo upstream**, como
   se hizo con sticky sockets.

#### La bajada: medida, no supuesta

Durante las pruebas pareció que 3.1 dejaba de limpiar al recibir SIGTERM. **Es
falso**, y la confusión salió cara, así que queda la medición. En la VM, con el
binario 3.1 y arrancando y bajando **igual que el paquete** —`daemon -p` para
arrancar, y para parar el PID que sale del pidfile o, si no sirve, de
`pgrep -f "<awg_go> <if>$"`—:

| señal | proceso | socket | interfaz |
|---|---|---|---|
| SIGTERM | se va | se va | se va |
| SIGKILL | se va | **queda** | **queda** |

Es exactamente la matriz de 2.0 que ya estaba anotada en `awg_proc_pid()`.
`awg_proc_stop()` no necesita ningún cambio, y `awg_proc_reap()` sigue siendo lo
que cubre el caso del SIGKILL y el de un proceso que se cayó solo.

Lo que sí se vio de verdad, y conviene no repetir: **una interfaz que quedó
levantada es cara**. Un `ifconfig destroy` que se cruza con un daemon arrancando
sobre esa misma interfaz la deja colgada en **estado D**, con procesos
imposibles de matar, y arrastra a cualquier `pkg delete` que intente bajarla
(`Unable to access interface tunNNNN: Protocol error`). Se destraba matando lo
que quedó agarrado, no insistiendo con el destroy. `awg_proc_reap()` ya evita
ese cruce por diseño: solo destruye si el proceso no está corriendo.

Detalle menor que apareció: el pidfile de `daemon -p` **siempre queda vacío**,
porque amneziawg-go se demoniza solo y el hijo directo de `daemon(8)` se va
enseguida. O sea que en la práctica el PID lo encuentra siempre el `pgrep` de
respaldo. Es así desde 2.0, no lo cambió 3.1.

Tareas que quedan: **ninguna de la fase 1**. Todo cerrado el 17-08-2026:

1. ~~`NOTICE` y `docs/licencias.md`~~. Hechos. De paso quedó comprobado que las
   dependencias nuevas del `go.mod` —el SDK de Outline, gvisor, quic-go— **no
   entran en el binario**: `go version -m` lista solo `x/crypto`, `x/net` y
   `x/sys`, las mismas de siempre. No hubo licencias nuevas que declarar.
2. ~~Decidir qué hace `tools/fetch-sources.sh`~~. **Retirado**: existía para
   demostrar la cadena "binario del .pkg = binario del port = distfile = tag", y
   con el binario propio esa cadena no aplica. Lo que se publica ahora lo deja
   `tools/build-awg-freebsd.sh`: el tarball, su `.SHA256` y su `.BUILDINFO`.
3. ~~Rearmar el `.pkg`~~. Hecho, y es el que está instalado en las dos cajas.

Estado del firewall de prueba (192.168.30.1): **sin el paquete**. Se instaló el
1.0.0 con los binarios 3.1 puestos a mano para correr las pruebas de arriba, y
después se desinstaló, así que la caja quedó como estaba. Los binarios 2.0
originales siguen en `/root/amneziawg-go.20.bak` y `/root/awg.20.bak` por si
hacen falta para comparar.

## Fase 2 — el modelo de datos

1. **`obfuscation_fields` necesita un nombre explícito por campo.**
   `awg_obfuscation_pairs()` arma la clave del `.conf` con `ucfirst($field)`,
   que funciona para `jc`→`Jc` y `i1`→`I1` pero da `Contentpaddingaddition`.
   Agregar `'key' => 'ContentPaddingAddition'` al spec y usar
   `$spec['key'] ?? ucfirst($field)`. Es el único cambio de diseño real que
   pide 3.0.
2. Tipos nuevos: `range` (rango u16, `n` o `n-m`, 0-65535), `key` (32 bytes en
   base64) y `bool` (se escribe `on`/`off`, no `1`/`0`).
3. **Cuatro escalones, no tres.** `3 => AmneziaWG 3.0` y
   `4 => AmneziaWG 3.1`: un cliente 3.0 rechaza el archivo entero al ver
   `RandomTrailers`, exactamente como uno 1.x lo rechaza al ver `S3`. La sonda
   del 4 pregunta por `RandomTrailers = off`; la del 3 ya existe.
4. `awg_version_implemented` a 4 recién al final, cuando los campos estén
   dibujados y probados.

## Fase 3 — generación y GUI

- `awg_gen_obfuscation()`: rama `>= 3`. Sortear `ContentPaddingAddition` **no**
  (cuesta MTU) y los timings **tampoco** (cambian el comportamiento del
  protocolo en los dos extremos y un valor raro es más firma que los valores de
  fábrica). Sí sortear `HeaderProtectionKey`, que es donde está la ganancia
  real de 3.0 y donde una constante sería el peor default posible.
- El botón **Randomise** necesita una advertencia: en un túnel de nivel 3
  rehace la `HeaderProtectionKey` y eso invalida todos los `.conf` de cliente
  ya entregados de ese túnel. Alternativa más segura: dejar la clave fuera del
  sorteo y darle su propio botón, como tienen las claves del túnel.
- La página: el patrón `$awg2 = ($ceiling >= 2)` se extiende a `$awg3`/`$awg4`.
  Los campos existen según el techo; lo elegido solo decide qué se escribe.

## Fase 4 — validación y pruebas

- `awg_validate.inc`: sintaxis de rango (`n` o `n-m`, con `n <= m`), base64 de
  32 bytes, y `on`/`off`.
- `tools/test-obfuscation.php` y `tools/test-client-conf.php`: casos de nivel 3
  y 4. `awg_obfuscation_pairs()` ya acepta `$ceiling` forzado, así que los
  cuatro niveles se prueban sin firewall al lado.
- En el firewall: un túnel por nivel contra el mismo backend, y que los cuatro
  `.conf` los parsee `awg(8)` sin chistar.

## Fase 5 — documentación

- `docs/amneziawg-3.0.md`, hermano del de 2.0: qué agrega cada campo, cuáles
  tienen que coincidir en los dos extremos y cuál cuesta caudal.
- README: la tabla de niveles.
- **Corregir la frase que ya no vale**: `awg_globals.inc` y el README dicen que
  "todo lo 3.x está publicado como prerelease". Las releases 3.x de
  amneziawg-tools tienen `prerelease: false`.

## Lo que NO se rompe

Revisado porque eran los candidatos obvios:

- **El parseo de estado.** El renglón de device de `awg show all dump` pasa de
  20 a 28 columnas en 3.x, pero `awg_get_running_config()` solo lee los índices
  1 a 3 (`private_key`, `public_key`, `listen_port`) y esos no se movieron. El
  renglón de peer tampoco cambió de forma.
- **La migración.** Un túnel viejo sin `awgversion` guardado sube solo al techo
  nuevo, pero `awg_obfuscation_pairs()` saltea los campos vacíos: sin valores
  3.0 cargados no le aparece ninguna clave nueva en el `.conf`. La migración es
  segura por construcción, no por cuidado.

Aparte, sin relación con 3.0: `$tunnel_output_keys` mapea el índice 4 a
`fwmark`, y en un dump de 2.0 ahí está `Jc`. Es inofensivo —nadie más lee
`fwmark` en el árbol— pero viene mal desde el fork de WireGuard.

## Verificado entre dos pfSense (17-08-2026)

La prueba que ni los tests ni el spike pueden dar: **dos instalaciones
independientes hablando 3.1 por internet**, cada una con su proceso, sus claves
y su configuración.

- Cajas: `192.168.30.1` (dos WAN, publica el túnel por la **PPPoE**, que es la
  que **no** tiene el default gateway) y `192.168.10.1` (una sola WAN, disca al
  duckdns de la primera). Las dos 2.9.0-BETA, `FreeBSD:16:amd64`, con el mismo
  `.pkg`.
- Handshake completo y renovándose, con los 25 parámetros iguales de los dos
  lados —`HeaderProtectionKey` incluida, S4 en 16 por la regla del nonce—. Si
  uno solo no coincidiera, no cerraría: eso es lo que valida la ofuscación.
- Un **único** estado de pf, en `pppoe0`: sticky sockets sirviendo a un peer
  remoto real, no a un cliente de la misma LAN.
- Tráfico de usuario: ping por dentro del túnel, 4/4, ~4,5 ms.

Dos cosas que costaron un rato y no son del paquete: un túnel recién creado no
levanta si el **servicio está deshabilitado** (`enable => off` es el default de
una instalación nueva, y `awg_tunnel_sync()` devuelve 0 sin hacer nada), y las
reglas que crea uno por costumbre en la interfaz del túnel apuntan **a la LAN**,
así que un ping a la IP del propio túnel cae en el block por defecto de los dos
lados.

Y un detalle del propio paquete que conviene recordar: cualquier script de CLI
que incluya `awg_guiconfig.inc` **no puede recibir argumentos**. `awg_service.inc`
arranca su dispatcher con `isset($argv[1])` y muere con "This script can only be
executed by php_awg". Se pasa la entrada por archivo o por variable de entorno.

## Antes de empezar hay que confirmar

1. ~~**Qué entienden los clientes de verdad.**~~ **Contestado el 17-08-2026, y
   la respuesta tiene dos mitades.** La app de Android instalada rechaza el
   `.conf` de un túnel 3.0 con un error de atributo desconocido en
   `[Interface]`: la única clave de 3.x que lleva ese archivo es
   `HeaderProtectionKey`, así que es esa. Pero **el código de los clientes sí
   tiene 3.1**: el `go.mod` del cliente de Windows en master pide
   `amneziawg-go/v3 v3.1.20260814` —la misma versión que compilamos— con los
   commits `feat: add awg3 support` (24-07) y `feat: add awg3.1 support`
   (13-08). Lo que pasa es que la última release publicada es la **2.0.2, del
   21-07**, anterior a esos commits. O sea: el soporte está escrito y sin
   publicar, no ausente. Hasta que salgan esas releases, 3.x es
   firewall-a-firewall o hay que compilar el cliente.
2. ~~**El corte exacto 3.0 vs 3.1.**~~ **Confirmado el 17-08-2026** con un
   `git fetch` del tag `v3.0.20260805`: diffeando los `key_match()` de los dos
   `config.c`, 3.1 agrega exactamente `RandomTrailers` y `DisableCookies` sobre
   3.0, que es como está la tabla.
