# pfSense-pkg-AmneziaWG

Un paquete de pfSense que agrega **VPN → AmneziaWG**: túneles y peers
gestionados desde la GUI, al nivel del paquete oficial de WireGuard.

[AmneziaWG](https://docs.amnezia.org/documentation/amnezia-wg) es un fork de
WireGuard para evadir DPI. La criptografía es la misma; lo que cambia es la
forma de los paquetes en el cable, para que un DPI no pueda reconocerlos por
firma.

> **Estado: fase 2 terminada.** El paquete instala, aparece en el menú y
> desinstala limpio en 2.9.0-BETA. Todavía no se pueden levantar túneles.

## Estado por fase

| Fase | | Estado |
|---|---|---|
| 1 | Data plane a mano | ✅ validada en 2.9.0-BETA el 13-08-2026 |
| 2 | Esqueleto del paquete | ✅ instalado y verificado en 2.9.0-BETA el 13-08-2026 |
| 3 | Los 16 campos de ofuscación | ⬜ el que sigue |
| 4 | Supervisión de los procesos | — |
| 5 | Watchdog | — |
| 6 | Integración con wgeasy | — |

Lo verificado en la fase 2, sobre el firewall: `pkg add` corre `awg_install()`
entero, las dos entradas de menú quedan registradas, las cuatro páginas y el JS
responden 200, los binarios que van adentro del `.pkg` ejecutan en la máquina, y
`pkg delete` borra todo — archivos, menú, grupo de interfaces, servicio— sin
dejar procesos ni interfaces. Lo único que sobrevive a propósito es el bloque de
settings en `config.xml`, porque `keep_conf` viene en `yes`.

Durante esa prueba apareció el bug que tenía el árbol desde el fork: en
`tools/fork-from-wireguard.sh` la regla `s/vpn_wg_/vpn_awg_/g` corría antes que
`s/wg_/awg_/g`, y la segunda volvía a matchear el `wg_` que quedaba adentro del
`awg_` recién escrito. Resultado: ~130 referencias `vpn_aawg_*` y las URLs
todavía apuntando a `/wg/`. Los nombres de archivo estaban bien, así que el
árbol parecía correcto y en realidad ningún link resolvía: el menú habría caído
en 404, o peor, en las páginas del paquete oficial de WireGuard si está
instalado. Está arreglado en el árbol y en el script.

### Próximo paso concreto

**Fase 3: los 16 campos de ofuscación.** El formulario de túnel todavía es el de
WireGuard; hay que agregar `Jc`, `Jmin`, `Jmax`, `S1`, `S2`, `H1`–`H4` y el
resto, con sus validaciones — acordarse de que `H1`–`H4` son texto que acepta
rangos, no enteros. Ver `docs/arquitectura.md`.

Lo que **no** va a andar hasta la fase 4 es levantar túneles desde la GUI: el
daemon todavía no supervisa los procesos `amneziawg-go`.

## Por dónde empezar

Leé **[docs/arquitectura.md](docs/arquitectura.md)**. Tiene las decisiones
tomadas, el porqué de cada una, y el orden de implementación en seis fases.

El historial de git es parte de la documentación: cada commit explica qué
cambió y por qué, no solo qué archivos se tocaron.

Lo que ya está resuelto ahí:

- **Userspace, no kernel.** El módulo `if_amn.ko` de los ports de FreeBSD no
  carga en pfSense y nunca va a cargar de forma confiable — se publica pineado a
  un `__FreeBSD_version` exacto. Se usa `amneziawg-go`.
- **Interfaces `tun9000`–`tun9999`.** Es lo que hace que pfSense las liste en
  Interfaces → Assignments sin parchear la base.
- **Los 16 parámetros de ofuscación** con sus rangos y validaciones, incluida la
  trampa de que `H1`–`H4` son texto con rangos, no enteros.
- **Un `.pkg` por ABI**, porque 2.8.1 es FreeBSD 15 y 2.9.0 es FreeBSD 16.

## Estructura

```
docs/arquitectura.md      el documento de diseño
src/                      el árbol del paquete, tal como se instala
build/make-pkg.ps1        arma el .pkg
spike/                    sondas contra el firewall
tools/                    utilidades de desarrollo
reference/                código de terceros, no versionado
bin/                      binarios por ABI, no versionado
```

`reference/` está en el `.gitignore` porque es código de otros autores. Para
recuperarlo en un clon nuevo:

```sh
sh tools/fetch-references.sh
```

## Compilar el paquete

Hay **un `.pkg` por ABI**: el paquete lleva adentro `amneziawg-go` y `awg`, y
los binarios de FreeBSD 15 y 16 no son intercambiables.

```powershell
powershell -ExecutionPolicy Bypass -File build\make-pkg.ps1 -Abi FreeBSD:16:amd64
```

El script busca los binarios en `bin/<ABI>/` y solo intenta descargarlos si no
están. Si la máquina de build no llega a `pkg.freebsd.org`, se traen desde un
firewall que corra ese ABI — que por definición sí llega:

```sh
# en el pfSense
sh /root/fetch-binaries.sh
```
```powershell
# de vuelta acá
scp root@FIREWALL:/root/awg-bin-FreeBSD:16:amd64.tar.gz .
tar -xzf awg-bin-FreeBSD:16:amd64.tar.gz -C bin\FreeBSD-16-amd64\
```

Instalar:

```sh
pkg add /root/pfSense-pkg-AmneziaWG-0.1.0-FreeBSD-16-amd64.pkg
```

## Objetivo

pfSense CE 2.8.1 (FreeBSD 15) y 2.9.0-BETA (FreeBSD 16), amd64.

## Relación con wgeasy

Este proyecto es hermano de
[pfsense-wgeasy](https://github.com/marcelomayo/pfsense-wgeasy), que hace lo
mismo para WireGuard: generar la configuración del cliente con QR, descarga y
envío por mail. La fase 6 del plan integra esa funcionalidad acá.

## Licencia

Por definir. Las piezas de terceros que se reusan son Apache 2.0
(`pfSense-pkg-WireGuard`), MIT (`amneziawg-go`,
`pfSense-pkg-amneziawg-client`) y GPLv2 (`amnezia-tools`). Si el binario `awg`
se redistribuye dentro del `.pkg`, hay que ofrecer su código fuente.
