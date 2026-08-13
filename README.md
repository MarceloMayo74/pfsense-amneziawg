# pfSense-pkg-AmneziaWG

Un paquete de pfSense que agrega **VPN → AmneziaWG**: túneles y peers
gestionados desde la GUI, al nivel del paquete oficial de WireGuard.

[AmneziaWG](https://docs.amnezia.org/documentation/amnezia-wg) es un fork de
WireGuard para evadir DPI. La criptografía es la misma; lo que cambia es la
forma de los paquetes en el cable, para que un DPI no pueda reconocerlos por
firma.

> **Estado: fase 2 casi terminada.** El paquete ya compila y debería instalar,
> pero todavía no se probó en un firewall.

## Estado por fase

| Fase | | Estado |
|---|---|---|
| 1 | Data plane a mano | ✅ validada en 2.9.0-BETA el 13-08-2026 |
| 2 | Esqueleto del paquete | ⬜ falta instalarlo y verificar el menú |
| 3 | Los 16 campos de ofuscación | — |
| 4 | Supervisión de los procesos | — |
| 5 | Watchdog | — |
| 6 | Integración con wgeasy | — |

### Próximo paso concreto

Traer los binarios y probar el paquete en el firewall:

```sh
# en el pfSense 2.9.0-BETA
sh /root/fetch-binaries.sh
```
```powershell
# acá
scp admin@FIREWALL:/root/awg-bin-FreeBSD:16:amd64.tar.gz .
tar -xzf awg-bin-FreeBSD:16:amd64.tar.gz -C bin\FreeBSD-16-amd64\
powershell -ExecutionPolicy Bypass -File build\make-pkg.ps1 -Abi FreeBSD:16:amd64
```

Lo que se espera: que instale, aparezca en **VPN → AmneziaWG** y desinstale
limpio. Lo que **no** va a andar todavía es levantar túneles desde la GUI — el
daemon no supervisa los procesos (fase 4) y el formulario no tiene los campos
de ofuscación (fase 3).

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
