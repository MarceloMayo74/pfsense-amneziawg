# AmneziaWG 3.0 y 3.1: qué agregan y qué cuestan

Hermano de [amneziawg-2.0.md](amneziawg-2.0.md). Todo lo que sigue está leído
del `config.c` de amneziawg-tools y del `device/` de amneziawg-go en el tag
`v3.1.20260812`, y comprobado contra el backend en
[`spike/verify-awg3.php`](../spike/verify-awg3.php) — no de la documentación de
Amnezia, que al día de hoy dice que lo de 3.0 se documenta "después" del soporte
self-hosted.

## Qué agrega 3.x sobre 2.0

Nueve claves más en `[Interface]`, todas del túnel y ninguna por peer:

| Clave | Forma | Nivel |
|---|---|---|
| `HeaderProtectionKey` | clave de 32 bytes en base64 | 3.0 |
| `ContentPaddingAddition` | `n` o `n-m`, hasta 65535 | 3.0 |
| `RekeyAfterTime` | rango, segundos | 3.0 |
| `RekeyTimeout` | rango, segundos | 3.0 |
| `RejectAfterTime` | rango, segundos | 3.0 |
| `KeepaliveTimeout` | rango, segundos | 3.0 |
| `MaxHandshakeAttempts` | rango, cantidad | 3.0 |
| `RandomTrailers` | `on` / `off` | 3.1 |
| `DisableCookies` | `on` / `off` | 3.1 |

De todas, **la que da la ganancia es `HeaderProtectionKey`**. Las demás son
variabilidad: mueven tamaños y tiempos para que el tráfico no tenga una forma
reconocible a lo largo del tiempo.

## Por qué 3.0 y 3.1 son dos escalones y no uno

Aunque un mismo binario entienda los dos, el selector de compatibilidad los
separa. La razón es la de siempre: **un cliente 3.0 rechaza el archivo entero al
ver `RandomTrailers`**, exactamente como uno de 1.x lo rechaza al ver `S3`. El
escalón no describe el backend instalado, describe qué entiende el otro extremo.

## `HeaderProtectionKey`, y la regla que arrastra

Sin ella, el tipo de mensaje viaja en claro: un DPI puede leer si un paquete es
un handshake o transporte, aunque todo lo demás esté ofuscado. Con ella, ChaCha20
cifra el header.

Es un **secreto compartido**, no un parámetro sorteable: tiene que ser el mismo
byte a byte en los dos extremos y viaja en el `.conf` del cliente. Por eso en
este paquete no entra en el botón de sortear, tiene generador y botón propios, y
rehacerla pide confirmación — deja afuera a todos los clientes que ya tienen su
archivo.

Y arrastra una regla que no está escrita en ninguna documentación:

> Con `HeaderProtectionKey` puesta, **`S1`, `S2`, `S3` y `S4` tienen que llegar
> a 12**.

El nonce del ChaCha20 sale de los primeros `HeaderCipherNonceSize` bytes del
relleno de cada tipo de paquete, así que un tipo con menos relleno no tiene
dónde ponerlo. `mergeWithDevice()` rechaza el `setconf` **entero**, y lo que
llega a la pantalla es `Unable to modify interface: Invalid argument`, que no
nombra ningún campo. Se descubrió aplicando los nueve parámetros juntos y
probándolos de a uno.

**La consecuencia cae sobre `S4`**, que este paquete sorteaba en cero a
propósito porque es el único relleno que se paga en **cada paquete de datos**
(ver el 2.0). O sea: de 3.0 en adelante, prender la protección de headers cuesta
MTU sí o sí. No es una decisión nuestra, es del protocolo.

El paquete lo maneja en tres lugares: el sorteo pone `S4` apenas por encima del
mínimo y nada más, la pantalla sube los cuatro mientras haya clave y los
devuelve cuando se saca, y la validación rechaza la combinación antes de que
llegue a un `.conf`.

## `ContentPaddingAddition`: el otro que se paga

Bytes al azar agregados a **cada paquete de datos**, no sólo al handshake. Es el
mismo trato que hace `S4`: caudal y MTU a cambio de que los tamaños dejen de ser
constantes. `randomPaddingAddition()` lo recorta contra el MTU, así que no
fragmenta, pero sí come.

Vacío significa que el backend no agrega nada, y así queda por defecto: prenderlo
tiene que ser una decisión.

## Los cinco tiempos

Hasta 2.0 eran constantes compiladas de WireGuard. 3.0 los deja elegir para que
el *ritmo* del túnel —cada cuánto rekey, cuánto espera un handshake, cada cuánto
un keepalive— deje de ser una firma.

**Tienen que coincidir en los dos extremos** y por eso este paquete **no los
sortea**. Los valores de fábrica son los que usa todo el mundo, y ahí está la
paradoja que conviene entender antes de tocarlos: un valor raro te hace *más*
distinguible, no menos. Sirven cuando lo que te delata es el ritmo, no el
contenido — no como "más ofuscación por las dudas".

## Los dos booleanos de 3.1

- `RandomTrailers` agrega bytes al azar al final de los paquetes, dentro de la
  ventana UDP. Del lado que recibe, `receive.go` deja de exigir el tamaño exacto
  y acepta cualquiera mayor.
- `DisableCookies` apaga las respuestas de cookie, que son un mensaje
  reconocible de WireGuard.

Van como selector de tres estados en la GUI y no como casilla, porque **vacío no
es lo mismo que `off`**: vacío deja la clave fuera de los dos `.conf` —que es lo
que un cliente 3.0 necesita— mientras que `off` la escribe con ese valor.

## Compatibilidad hacia atrás

Es total, y está en el código, no en una promesa:

- `HeaderProtectionCipher()` devuelve `nil` si la clave está en cero.
- `randomPaddingAddition()` devuelve `-1` si `ContentPaddingAddition` está en
  cero.
- `randomTrailer()` devuelve `-1` si `RandomTrailers` está apagado.

O sea que **un binario 3.1 con un `.conf` de 2.0 emite exactamente los mismos
bytes que un binario 2.0**. Por eso este paquete es uno solo con una escalera de
cuatro niveles, y no un paquete aparte para 3.x. Verificado además entre dos
firewalls: un cliente 2.0 real contra un backend 3.1 sigue conectando.

## Lo que hay que saber de la bajada de nivel

`awg(8)` aplica lo que el `.conf` nombra y **no toca lo que no nombra**. Así que
bajar un túnel de 3.1 a 2.0 no le saca la `HeaderProtectionKey` al proceso que
ya está corriendo: se queda viva, y en cuanto `S4` vuelve a cero el backend
rechaza el archivo entero.

El paquete lo resuelve rehaciendo el proceso cuando el archivo nuevo saca algún
parámetro, antes de configurar la interfaz. Vale la pena tenerlo presente si se
toca `awg` a mano: **un dispositivo recién creado es la única forma de empezar
sin restos.**

Y es un problema más viejo que 3.x — bajar de 2.0 a 1.x deja `S3` e `I1` vivos
igual—, sólo que ahí no hay error: el handshake deja de cerrar y nada lo dice.

## Los clientes, al 17-08-2026

El soporte **está escrito y sin publicar**. El cliente de Windows tiene en su
master `amneziawg-go/v3 v3.1.20260814` y los commits `feat: add awg3 support`
(24-07) y `feat: add awg3.1 support` (13-08), pero su última release es la
**2.0.2, del 21-07** — anterior a esos commits. El repo de Android tiene tags
`v3.0.1` y `v3.1.20260814` y ninguna release de GitHub.

En la práctica: la app que se instala hoy rechaza un `.conf` de 3.0 con un error
de atributo desconocido en `[Interface]` —la única clave de 3.x que ese archivo
lleva es `HeaderProtectionKey`—, así que hasta que salgan esas releases, 3.x es
firewall a firewall o hay que compilar el cliente.
