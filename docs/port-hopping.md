# Rotación de puertos

Medido el 18-08-2026 entre los dos firewalls de 2.9.0-BETA, por internet, con
una punta detrás de NAT.

Cubre una capa que la ofuscación no toca: la ofuscación disfraza el **contenido**
de los paquetes, y esto ataca el **metadato de la conexión**.

## El problema, y de dónde salió

Un usuario detrás del GFW reportó dos cosas distintas en la misma frase: *"AWG2.0
is too slow and prone to network interruptions"*. La lentitud es userspace contra
kernel y está medida en [medicion-throughput.md](medicion-throughput.md). Los
cortes son otra cosa, y la documentación de Hysteria le pone nombre:

> Users in China often report that their ISPs block/restrict persistent UDP
> connections to a single port. Port hopping should invalidate this kind of
> mechanism.

WireGuard —y AmneziaWG con él— sostiene una 4-tupla UDP en un puerto fijo para
siempre. Eso es exactamente el patrón descrito, y **ningún parámetro de
ofuscación lo cambia**: `Jc`, `S1` y `H1` disfrazan bytes, no flujos.

## Qué hace

Cada N segundos, al peer que disca se le reescribe el puerto del endpoint:

```
awg set <túnel> peer <clave> endpoint <host>:<puerto>
```

Nada más. La sesión criptográfica no se toca, no hay rehandshake, y el otro
extremo no se entera.

El reloj es propio, separado del de resolución de DNS. Los dos viven en el mismo
loop del demonio —que ya despertaba cada segundo— pero saltar cada 10 segundos no
es motivo para consultar DNS cada 10 segundos, que es lo primero que limita un
resolver.

El puerto se elige **al azar y descartando el actual**. Secuencial sería un
patrón tan reconocible como el que la ofuscación trata de esconder, y repetir el
puerto gasta un intervalo entero sin cambiar el flujo.

La IP se lee **del backend**, no de la configuración. Un nombre con varias `A`
resuelto dos veces devuelve direcciones distintas, y entonces la ruta de host del
gateway apuntaría a un servidor mientras el túnel le habla a otro, sin que nada
lo diga. Leyendo lo que el proceso tiene puesto se rota el puerto y nada más.

## Por qué sólo el puerto de destino

Ésta es la decisión que hace que todo lo demás sea simple.

WireGuard tiene **roaming**: cuando a un peer le llega un paquete válido desde un
origen distinto, actualiza solo a dónde contesta. Si rotáramos nuestro puerto de
**origen**, el otro extremo vería un peer que se mudó y actualizaría su endpoint;
y su respuesta, llegando desde un puerto que ya no es el que pedimos, podría
devolvernos el endpoint al valor anterior. La rotación se pelearía consigo misma.

Rotando sólo el destino, el origen queda fijo, el otro extremo nunca ve un peer
nuevo y **no hay roaming que deshacer**. Por eso la medición da cero pérdida en
vez de dar intermitencias.

Rotar también el origen es posible —`amneziawg-go` acepta `listen-port` en
caliente, `device/uapi.go`— pero rebindea el socket UDP, que es justo el que
lleva el parche de sticky sockets. Queda afuera hasta que alguien detrás de un
censor diga que la mitad barata no alcanzó.

## Lo que necesita el otro extremo

Una regla de NAT que mande el rango a su puerto de escucha. **No necesita este
paquete, ni código nuestro, ni saber que hay rotación.** Es el mismo mecanismo
que Hysteria documenta para su propio servidor, que tampoco lo trae de fábrica.

En pfSense: Firewall → NAT → Port Forward, UDP, rango en el destino, redirigido
al puerto de escucha del túnel.

**Conviene hacerla desde la GUI y no a mano.** La regla generada lleva
`reply-to`:

```
pass in quick on pppoe0 reply-to (pppoe0 200.51.241.1) inet proto udp
     from any to 179.41.138.161 port = 51821 keep state
```

Sin ese `reply-to`, en un firewall multi-WAN la respuesta sale por la interfaz
del default gateway en vez de por la que recibió el paquete, y el túnel nunca
cierra handshake. Eso se descubrió armando la medición: la primera regla escrita
a mano no lo tenía y no había forma de ver por qué no andaba.

## La medición

Dos firewalls con IP pública propia, camino real de internet, la punta que rota
detrás de NAT. Túnel AmneziaWG **3.1** con ofuscación real —`S4 = 19`, o sea
relleno en cada paquete de datos, y headers por rango— y las dos interfaces
**asignadas** en pfSense, con sus direcciones puestas por pfSense y reglas de
firewall reales.

| intervalo | saltos | paquetes | pérdida | rehandshakes |
|---|---|---|---|---|
| un cambio único | 1 | 10 / 10 | 0 % | 0 |
| cada 10 s | 12 | 125 / 125 | 0 % | 1 (el rekey normal) |
| cada 2 s | 30 | 62 / 62 | 0 % | 1 |
| cada 10 s, interfaces asignadas | 8 | 75 / 75 | 0 % | **0** |

Latencia estable en las cuatro: ~4,6 ms de promedio, sin picos en los saltos.

En la última corrida el handshake terminó con el **mismo timestamp** con el que
empezó: 75 segundos y ocho cambios de puerto sin que la sesión se renegociara ni
una vez.

## Los rangos del reloj

| | |
|---|---|
| mínimo | 2 s — medido, no elegido: por debajo no se probó |
| default | 10 s — el mismo que usa Hysteria |
| máximo | 300 s |

## Lo que NO está probado

**Que sirva.** Rotar sin perder paquetes y destrabar un bloqueo son dos
afirmaciones distintas, y sólo la primera está medida. Nadie acá está detrás de
un censor, así que no hay forma de verificar la segunda desde este lado.

También queda sin probar contra un extremo **Linux**: la medición se hizo contra
`pf`, y `nf_conntrack` mantiene un flujo UDP establecido 180 segundos contra los
60 de `pf`. La regla DNAT es equivalente pero no es lo mismo.

Y se midió sobre un camino **limpio**: 4 ms y 0 % de pérdida de base. Un camino
con pérdida y latencia alta —que es como se ve el que motivó todo esto— puede
comportarse distinto.

## Lo que no cubre

El caso **roadwarrior**, con celulares del otro lado. Ahí el que tendría que
rotar es el cliente, y ningún cliente de AmneziaWG lo hace: ni las apps
oficiales, ni el plugin de OPNsense, ni el upstream. La mitad del servidor se
puede armar con la misma regla de NAT, pero sin la mitad del cliente no sirve
para nada.
