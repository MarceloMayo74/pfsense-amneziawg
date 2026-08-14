# AmneziaWG 2.0: qué agrega y cómo se prende

Todo lo de acá está **medido contra el backend instalado** con
`spike/verify-awg2.php` (26/26 sobre pfSense 2.9.0-BETA), y leído del fuente de
`amneziawg-go` en `device/obf.go`, `device/send.go` y `device/receive.go`. No es
documentación de Amnezia: ellos no publicaron nada de esto.

## Qué agrega 2.0 sobre 1.x

1.x tiene nueve parámetros: `Jc` `Jmin` `Jmax` `S1` `S2` `H1`–`H4`. 2.0 agrega
siete: **`S3` `S4`** y **`I1`–`I5`**.

## La distinción que importa: qué tiene que coincidir en los dos extremos

No es lo mismo para todos, y la diferencia sale de dónde los usa el código:

| | dónde vive en el fuente | ¿tiene que coincidir? |
|---|---|---|
| `H1`–`H4` | `send.go` y `receive.go` | **sí** |
| `S1` `S2` `S3` `S4` | `paddings.*` en `send.go` y `receive.go` | **sí** |
| `Jc` `Jmin` `Jmax` | solo `send.go` | no |
| `I1`–`I5` | solo `send.go` (`ipackets` no aparece en `receive.go`) | no |

Los que se leen al recibir cambian cómo se parsea el paquete: si no coinciden,
no hay handshake y no hay ningún error que lo diga. Los otros son decoración del
que emite — el que recibe los descarta porque no parsean como mensaje válido, sin
necesidad de tenerlos configurados.

Igual el paquete escribe los mismos valores en los dos lados, porque coincidir
siempre es seguro y evita razonar sobre esto en cada alta.

## `S3` y `S4`

Relleno agregado adelante de dos tipos de paquete más, en bytes (0–1280):

- **`S3`** — el *cookie reply*. Es un paquete raro, que solo aparece bajo carga.
- **`S4`** — el paquete de **transporte**, o sea **todos los datos**.

> **`S4` es el único parámetro de ofuscación que cuesta caudal.** En
> `send.go` el relleno entra en `offset = MessageTransportHeaderSize + padding`
> del camino de datos: se paga en cada paquete, no en el handshake. La medición
> de [medicion-throughput.md](medicion-throughput.md) —"ofuscar es gratis"— vale
> para 1.x, donde todo el relleno es de handshake. Con `S4` en 20 bytes y
> paquetes chicos el costo relativo es grande; además come MTU.
>
> Si no hay una razón concreta para prenderlo, `S4 = 0`.

## `I1`–`I5`: la mini-gramática

Son **cinco paquetes basura completos**, enviados una vez cada uno *antes* de la
iniciación del handshake, además de los `Jc`. Lo que los distingue de `Jc` es que
su contenido se describe con una plantilla, así que pueden imitar el arranque de
otro protocolo en vez de ser bytes al azar.

En `send.go` se generan con `ipacket.Obfuscate(buf, nil)` — **origen nulo**. De
ahí sale la consecuencia práctica: las etiquetas que dependen de los datos del
paquete (`<d>`, `<ds>`, `<dz>`) el parser las acepta pero **no tienen sentido
acá**, porque no hay datos de dónde copiar.

La sintaxis es una cadena de etiquetas `<clave valor>`, concatenadas sin
separador. Las ocho, verificadas contra el binario:

| etiqueta | argumento | qué emite | ¿sirve en un `I`? |
|---|---|---|---|
| `<b hex>` | hex, con o sin `0x` | esos bytes literales | sí |
| `<t>` | — | timestamp Unix, 4 bytes big-endian | sí |
| `<r N>` | entero | `N` bytes al azar | sí |
| `<rc N>` | entero | `N` letras al azar (`a-zA-Z`) | sí |
| `<rd N>` | entero | `N` dígitos al azar | sí |
| `<d>` | — | los datos del paquete | no, no hay datos |
| `<ds>` | — | los datos en base64 | no |
| `<dz N>` | entero | el tamaño de los datos en `N` bytes | no |

Rechaza, como corresponde: etiquetas que no existen, hex de largo impar,
`<b>` sin argumento, `<r>` sin largo, y un `<` sin cerrar.

Ejemplos que se aplican bien:

```
I1 = <b 0x16030100><r 32>     bytes fijos y después azar
I2 = <rc 24>                  24 letras
I3 = <t><r 16>                timestamp y azar
I4 = <rd 12>                  12 dígitos
I5 = <b 0xdeadbeef><rc 8>
```

## La regla que no hay que romper: nada de constantes

`H1`–`H4` ya se sortean por túnel, y el motivo vale más fuerte todavía para
`I1`–`I5`: **si el paquete publicara plantillas fijas, toda instalación de
`pfSense-pkg-AmneziaWG` emitiría los mismos bytes**, y eso es una firma —"esto es
el paquete de pfSense"— peor que no ofuscar nada. Los ejemplos de arriba son para
entender la sintaxis, no para copiar y pegar.

Lo mismo aplica a copiar la configuración de un tutorial: si mil personas usan el
mismo `I1`, ese `I1` es la firma.

## El otro extremo

La app de **AmneziaWG para Android** tiene los siete campos: *Cookie reply packet
junk size* (`S3`), *Transport packet junk size* (`S4`) y *Special junk I1* a
*I5*. Confirmado el 14-08-2026 mirando la pantalla de creación de túnel.

Cuidado con lo de siempre: la app **falla al importar** un `.conf` que traiga
campos `I2`–`I5` **vacíos**. El paquete es inmune por construcción, porque
`awg_obfuscation_pairs()` nunca escribe un campo vacío.

## Cómo se prende, en concreto

1. *VPN → AmneziaWG → Tunnels*, editar el túnel, sección **Obfuscation**.
2. Llenar `S3` y, sólo si hace falta, `S4` (leer el aviso de arriba).
3. Llenar los `I1`–`I5` que se quieran, **con valores propios**. Se pueden dejar
   vacíos: 2.0 no obliga a usarlos.
4. Guardar y aplicar.
5. **Re-exportar el cliente.** Los `.conf` ya entregados quedan viejos: el
   archivo del cliente sale de `awg_obfuscation_pairs()`, la misma función que
   escribe el del servidor, así que hay que volver a bajarlo o re-escanear el QR.

El backend detecta solo si acepta 2.0 (`awg_backend_supports_awg2()`, sonda
empírica cacheada en tmpfs). Si dijera que no, los campos 2.0 no se escriben en
ningún lado y el túnel sigue andando en 1.x.
