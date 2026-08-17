# Licencias

**El paquete es Apache 2.0.** Adentro viaja software de otros bajo GPLv2 y MIT,
que sigue siendo de ellos. Este documento dice por qué se eligió Apache, por
qué el binario GPLv2 no obliga a nada sobre el resto, y qué hay que hacer en
cada release para que eso siga siendo cierto.

Los textos completos están en [`licenses/`](../licenses) y el detalle de qué
cubre a qué, en [`NOTICE`](../NOTICE) — que es el archivo que se distribuye,
no este.

## La decisión

Apache License 2.0, por cuatro razones, en orden de peso:

1. **El árbol ya es Apache 2.0 y no se puede relicenciar.** Salió de
   `pfSense-pkg-WireGuard`, de Netgate, y cada archivo que vino de ahí conserva
   su encabezado con el copyright de Rubicon Communications, R. Christian
   McDonald y Ascrod. Elegir otra licencia no habría cambiado eso: habría dado
   un árbol mezclado donde la mitad dice una cosa y la mitad otra.
2. **Es la que usa el ecosistema.** Los paquetes de pfSense son Apache 2.0. Si
   este algún día se propone para el repositorio oficial, la licencia no es un
   tema. El proyecto hermano [pfsense-wgeasy] ya es Apache 2.0 y hay código que
   va y viene entre los dos.
3. **No le impone nada al que lo instala.** Un paquete de firewall lo va a
   instalar gente que no lee licencias. Apache no la obliga a publicar nada de
   lo suyo ni a cambiar cómo opera su red.
4. **Tiene cláusula de patentes.** Es lo que la separa de MIT o BSD: quien
   contribuye código otorga la licencia de patentes que ese código necesita.
   En un paquete de VPN con ofuscación, que es terreno con patentes dando
   vueltas, es una protección barata.

Lo que **no** se eligió y por qué:

- **GPL** — habría que relicenciar lo heredado, que no es nuestro. Y no
  resolvería nada: el `awg` GPLv2 ya cumple donde tiene que cumplir.
- **MIT o BSD** — más simples, pero sin cláusula de patentes y sin la
  continuidad con Netgate y con wgeasy.

## Qué hay adentro y bajo qué licencia

| Pieza | Licencia | De quién |
|---|---|---|
| El código del paquete (PHP, JS, XML) | Apache 2.0 | Netgate / McDonald / Ascrod, y lo nuevo, Marcelo Mayo |
| `/usr/local/bin/awg` | **GPLv2** | amneziawg-tools (fork de wireguard-tools, Jason A. Donenfeld) |
| `/usr/local/bin/amneziawg-go` | MIT | amneziawg-go (fork de wireguard-go, WireGuard LLC) |
| `awg_qrcode.js` | MIT | qrcode.js, davidshimjs |
| `patches/amneziawg-go/` | MIT | nuestro, sobre código MIT — así tiene que quedar para poder mandarlo upstream |

## Por qué un binario GPLv2 adentro no contamina el resto

Es la pregunta que decide todo, y la respuesta no es "porque es un binario"
sino **cómo se lo usa**:

- `awg` es un **programa aparte** que el paquete ejecuta con `exec`. No se
  linkea nada, no se incluye ningún header suyo, no deriva ningún archivo
  nuestro de los suyos.
- Meterlo en el mismo `.pkg` es *mere aggregation* en los términos del último
  párrafo de la sección 2 de la GPLv2: juntar dos programas independientes en
  un mismo medio de distribución no pone al otro bajo la GPL.

Lo que la GPLv2 **sí** obliga, y está hecho:

| Obligación | Dónde se cumple |
|---|---|
| Avisar que esa parte es GPLv2 y de quién es | `NOTICE`, que va adentro del `.pkg` |
| Que el texto de la licencia viaje con el binario (sección 1) | `/usr/local/share/licenses/pfSense-pkg-AmneziaWG-<versión>/GPLv2` |
| Entregar la fuente correspondiente (sección 3) | el tarball adjunto a cada release, ver abajo |

El manifiesto del paquete declara las tres licencias (`licenselogic: and`), no
solo Apache. Es lo que lee `pkg info -l` y cualquier mirror que lo redistribuya.

## La fuente correspondiente del `awg`

"La fuente correspondiente" no es el master de upstream: es exactamente lo que
produjo *ese* binario. Hasta la versión 1.0.0 el binario salía del port
`net/amnezia-tools` de FreeBSD, así que la fuente correspondiente eran el
distfile del port **más sus parches** — y demostrarlo era una cadena de tres
eslabones que había que verificar contra el repositorio de FreeBSD.

**Desde AmneziaWG 3.x lo compilamos nosotros**, y no por gusto: el port sigue
clavado en 1.0.20250903, que es 2.0, y no hay binario de FreeBSD de 3.x en
ningún lado — los releases de Amnezia publican Alpine, Ubuntu y Windows.

La cadena quedó de dos eslabones y sin nada que descubrir:

```
binario del .pkg                        sha256 18569c90…4f7cf5a5
  <- tools/build-awg-freebsd.sh, en FreeBSD 16.0-CURRENT amd64
  <- awg-src-3.1.20260812.tar.gz        sha256 b198a7c7…c54af898
  == fuente del tag v3.1.20260812 de amneziawg-tools
```

El script deja los dos hashes en `dist/awg-src-<versión>.SHA256` y lo que usó
para compilar en `dist/awg-src-<versión>.BUILDINFO` — sistema, compilador,
fecha y línea de comandos. Se corre en una VM de FreeBSD, no en el firewall:
pfSense no trae `cc` ni `/usr/include`.

**Hay una modificación, y una sola**: el build define `AWG_NO_KERNEL_IPC`, que
deja afuera `src/ipc-freebsd.h`. Ese archivo **no compila** contra 3.x —está
escrito contra el `containers.h` viejo, donde H1-H4 eran cadenas— y es el
camino que habla con el módulo `if_amn`, que en pfSense no carga. Todo lo que
hace este paquete va por el camino de userspace.

Eso importa para la GPL: la fuente correspondiente ya no es sólo el tarball,
sino **el tarball más el script que lo modifica y lo compila**. El script está
en el repositorio público, que es lo que la licencia pide.

**La trampa que sigue vigente**: `awg --version` no sirve para encontrar la
fuente. El del port decía `v1.0.20250521` estando en 1.0.20250903, porque
upstream se olvida de tocar `version.h` y el port lo parchea. El nuestro
reporta `3.1.20260812` porque en ese tag upstream sí lo actualizó — pero la
versión de referencia es la del tag que dice el `BUILDINFO`, no la que imprime
el binario. `amneziawg-go` tiene la misma trampa al revés: su `version.go` sigue
diciendo `0.0.20250522` hasta en el tag 3.1, y por eso nuestro build le estampa
el tag encima.

## En cada release

1. Compilar en la VM: `sh tools/build-awg-freebsd.sh`, y traerse `out/awg` a
   `bin/<ABI>/` y el tarball, el `.SHA256` y el `.BUILDINFO` a `dist/`.
2. Subir esos tres archivos como assets del release, al lado del `.pkg`. **Un
   release con el `.pkg` y sin la fuente incumple la GPL.**
3. Si cambió el binario, actualizar en `NOTICE` la versión y los dos sha256.

## Lo que queda abierto

- ~~Compilar `awg` nosotros~~. **Hecho el 17-08-2026**, y por el motivo que se
  había anotado: la fuente correspondiente es ahora, por definición, el tag que
  compilamos. Lo que lo apuró no fue la fragilidad sino que 3.x no tiene binario
  de FreeBSD en ningún lado.
- `tools/fetch-sources.sh` quedó sin objeto: existía para demostrar la cadena
  del port, que ya no es de donde sale el binario.
- Los archivos de `spike/` y `tools/` no llevan encabezado de licencia. No se
  distribuyen en el `.pkg`; los cubre el `LICENSE` del repositorio.

[pfsense-wgeasy]: https://github.com/marcelomayo/pfsense-wgeasy
