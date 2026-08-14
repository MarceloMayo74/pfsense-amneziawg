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
produjo *ese* binario. El binario no lo compilamos nosotros — sale del port
`net/amnezia-tools` de FreeBSD, que fija un tag y le aplica sus propios
parches. Sin los parches del port, la fuente está incompleta.

La cadena, verificada el 14-08-2026 contra el repositorio de paquetes de
FreeBSD:

```
binario del .pkg                        sha256 2ce33abd…7918b7f4
  == awg de amnezia-tools-1.0.20250903 (FreeBSD:16:amd64)   [idéntico byte a byte]
  <- distfile amnezia-vpn-amneziawg-tools-v1.0.20250903_GH0.tar.gz
                                        sha256 d729a6f5…d0c907d7
  == tarball del tag v1.0.20250903 en GitHub                [idéntico byte a byte]
```

Ese último `==` es el que hace práctico todo lo demás: el distfile de FreeBSD y
el tarball que sirve GitHub para el mismo tag son el mismo archivo, así que la
fuente se puede bajar de GitHub cuando `distcache.FreeBSD.org` no se alcanza —
que es el caso desde esta máquina de build, igual que `pkg.freebsd.org`.

Lo arma [`tools/fetch-sources.sh`](../tools/fetch-sources.sh), que baja el port
entero (Makefile, distinfo, `pkg-descr` y todos los parches de `files/`) más el
tarball de upstream, verifica el sha256 contra el distinfo del port y deja
`dist/awg-src-<versión>.tar.gz`.

**La trampa**: `awg --version` dice `v1.0.20250521`, que **no** es la versión
del port ni un tag de upstream. Es un valor que el port escribe con
`files/patch-version.h`, porque upstream se olvida de tocar `version.h` — en el
tag v1.0.20250903 ese archivo todavía dice `1.0.20210914`. La versión que sirve
para encontrar la fuente es la del paquete de FreeBSD, que está en su
`+MANIFEST`, no la que imprime el binario.

## En cada release

1. `sh tools/fetch-sources.sh` — con la versión del port si cambió.
2. Subir `dist/awg-src-<versión>.tar.gz` como asset del release, al lado del
   `.pkg`. **Un release con el `.pkg` y sin ese tarball incumple la GPL.**
3. Si se actualizó el binario, actualizar en `NOTICE` la versión y los dos
   sha256, y volver a correr la verificación de la cadena.

Para verificar la cadena hace falta un firewall, porque `pkg.freebsd.org` no se
alcanza desde acá: se baja el paquete `amnezia-tools` del catálogo, se saca el
`awg` de adentro y se compara su sha256 con el de `/usr/local/bin/awg`
instalado.

## Lo que queda abierto

- **Compilar `awg` nosotros**, como ya se hace con `amneziawg-go`. Sacaría toda
  la parte frágil de esto: la fuente correspondiente sería, por definición, el
  tag que compilamos. Necesita toolchain de FreeBSD y no hay apuro.
- Los archivos de `spike/` y `tools/` no llevan encabezado de licencia. No se
  distribuyen en el `.pkg`; los cubre el `LICENSE` del repositorio.

[pfsense-wgeasy]: https://github.com/marcelomayo/pfsense-wgeasy
