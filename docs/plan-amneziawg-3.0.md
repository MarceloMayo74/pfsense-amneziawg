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

#### Lo que sí hay que resolver antes de empaquetar

**El SIGTERM de 3.1 no se lleva la interfaz.** En 2.0, mandarle SIGTERM al
proceso bajaba el túnel entero: desaparecían la interfaz y el `.sock` (era el
hallazgo de la fase 4 del bring-up original). Con 3.1, `spike/verify-sticky.sh`
deja `tun9099` levantada y el socket en `run_path` después del SIGTERM. Y una
interfaz que queda dando vueltas es cara: un `ifconfig destroy` que se cruza con
un daemon arrancando la deja **colgada en estado D**, imposible de matar, y de
ahí no se sale sin reiniciar.

Hay que mirarlo antes de armar el `.pkg`: si `awg_proc_stop()` ya no alcanza,
el camino de bajada tiene que destruir la interfaz y borrar el socket él mismo
—y con cuidado del orden, porque el race es real—. Ojo que en el bring-up de
dos túneles la bajada sí funcionó, así que no es que 3.1 nunca limpie: falta
entender la diferencia.

Tareas que quedan:

1. `NOTICE` y `docs/licencias.md`: el `awg` deja de ser el del port, y
   `amneziawg-go` suma dependencias —`goccy/go-yaml`, `go.uber.org/atomic` y el
   **SDK de Outline** (`golang.getoutline.org/sdk`), que es de donde sale el
   TLS/REALITY de 3.0—. Ninguna dio problema al cruzar a `freebsd/amd64` con
   `CGO_ENABLED=0`; el binario creció 20 KB.
2. Decidir qué hace `tools/fetch-sources.sh`, que existía para demostrar la
   cadena "binario del .pkg = binario del port = distfile = tag". Con el
   binario propio esa cadena ya no aplica; o se adapta o se retira. Lo que hay
   que publicar ahora es lo que deja el script nuevo:
   `dist/awg-src-3.1.20260812.tar.gz` con su `.SHA256` y su `.BUILDINFO`.
3. Rearmar el `.pkg` con los dos binarios nuevos, que hasta ahora se pusieron
   a mano en el firewall para probar.

Estado del firewall de prueba (192.168.30.1): paquete 1.0.0 instalado con los
binarios 3.1 puestos a mano; los originales quedaron en `/root/amneziawg-go.20.bak`
y `/root/awg.20.bak`.

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

## Antes de empezar hay que confirmar

1. **Qué entienden los clientes de verdad.** El repo `amneziawg-android` no
   publica releases de GitHub, pero su tag más nuevo es `v3.1.20260814`, con
   `v3.0.1` antes. Falta ver qué sirve hoy Play/F-Droid: de eso depende si
   3.0 sirve para un teléfono o arranca siendo solo firewall-a-firewall.
2. **El corte exacto 3.0 vs 3.1.** El clon de `reference/amneziawg-tools` está
   aplastado en un solo commit, así que no se pudo diffear `v3.0.20260805`
   contra `v3.1.20260812`. La tabla de arriba sigue lo que ya decía el
   comentario de `awg_globals.inc`; se confirma con un `git fetch` del tag 3.0.
