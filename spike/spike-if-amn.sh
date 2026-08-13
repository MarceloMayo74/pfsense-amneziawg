#!/bin/sh
#
# spike-if-amn.sh - Sonda de viabilidad de AmneziaWG en pfSense.
#
# Responde tres preguntas antes de escribir una sola linea del paquete:
#
#   1. El modulo de kernel amnezia-kmod (if_amn.ko) de los ports de FreeBSD,
#      carga en el kernel parcheado de Netgate?
#   2. Si carga, que nombre de cloner expone, y que nombres de interfaz acepta?
#   3. pfSense reconoce esa interfaz como asignable en Interfaces > Assignments?
#
# NO usa pkg(8) en ningun momento: no agrega repos, no instala, no toca la base
# de datos de paquetes. Solo descarga los .pkg a un directorio temporal, los
# desempaqueta ahi y hace kldload desde esa ruta. Todo se revierte al final.
#
# AVISO: cargar un modulo compilado contra FreeBSD stock en un kernel parcheado
# puede provocar un panic. Correr cuando se pueda tolerar un reboot.
#
# Uso:   sh spike-if-amn.sh
#

set -u

WORK=/root/awg-spike
ABI_FALLBACK="FreeBSD:15:amd64"
TESTIF_STD="amn0"          # nombre "natural" del cloner
TESTIF_PFS="tun_awg0"      # nombre estilo pfSense, para probar SIOCSIFNAME

# Estado para el resumen final y para el teardown.
KMOD_LOADED=no
IF_CREATED=""
CLONER=""
RESULT_KMOD=NO
RESULT_CLONER=NO
RESULT_RENAME=NO
RESULT_ASSIGN=NO
RESULT_AWG=NO

say()  { printf '\n=== %s ===\n' "$*"; }
ok()   { printf '  [OK]    %s\n' "$*"; }
bad()  { printf '  [FALLA] %s\n' "$*"; }
info() { printf '  .       %s\n' "$*"; }

# ---------------------------------------------------------------------------
# Teardown: se ejecuta pase lo que pase, para no dejar estado en el firewall.
# ---------------------------------------------------------------------------
teardown() {
	say "Limpieza"

	if [ -n "$IF_CREATED" ]; then
		if ifconfig "$IF_CREATED" >/dev/null 2>&1; then
			ifconfig "$IF_CREATED" destroy 2>/dev/null \
				&& ok "interfaz $IF_CREATED destruida" \
				|| bad "no se pudo destruir $IF_CREATED (revisar a mano)"
		fi
	fi

	if [ "$KMOD_LOADED" = yes ]; then
		kldunload if_amn 2>/dev/null \
			&& ok "if_amn descargado" \
			|| bad "no se pudo descargar if_amn (revisar con kldstat)"
	fi

	# El directorio de trabajo se deja para inspeccion; borrarlo es una linea.
	info "archivos descargados en $WORK (borrar con: rm -rf $WORK)"
}
trap teardown EXIT INT TERM

# ---------------------------------------------------------------------------
# Fase 0 - Entorno
# ---------------------------------------------------------------------------
say "Fase 0: entorno"

if [ "$(id -u)" != "0" ]; then
	bad "hay que correr como root"
	exit 1
fi

info "pfSense:  $(cat /etc/version 2>/dev/null || echo desconocida)"
info "kernel:   $(uname -sr)"
info "arch:     $(uname -m)"
info "__FreeBSD_version: $(sysctl -n kern.osreldate 2>/dev/null || echo '?')"

ABI=$(pkg config ABI 2>/dev/null)
[ -n "$ABI" ] || ABI="$ABI_FALLBACK"
info "ABI:      $ABI"

# Estado previo de WireGuard, para saber que habia antes de tocar nada.
if kldstat -q -m if_wg 2>/dev/null; then
	info "if_wg:    CARGADO (el paquete WireGuard esta en uso)"
else
	info "if_wg:    no cargado"
fi

mkdir -p "$WORK" || exit 1
cd "$WORK" || exit 1

# ---------------------------------------------------------------------------
# Descarga un paquete desde pkg.freebsd.org sin usar pkg(8), resolviendo su
# ruta real desde el catalogo del repo.
#
# No se puede adivinar el nombre de archivo: el repo publica bajo All/Hashed/
# con un sufijo de hash, y los kmod ademas llevan embebida la version de
# FreeBSD contra la que se compilaron. Ejemplo real:
#
#   All/Hashed/amnezia-kmod-2.0.11.1404000~1cb0a3b404.pkg
#
# packagesite.yaml es la unica fuente confiable. Tampoco sirve listar /All/:
# pkg.freebsd.org responde Forbidden, no hay autoindex.
#
# $1 = nombre del paquete. Devuelve por stdout el archivo local descargado.
fetch_pkg() {
	_name=$1
	_out=""

	for _branch in quarterly latest; do
		_root="https://pkg.freebsd.org/${ABI}/${_branch}"
		_cat="catalog-${_branch}.yaml"

		if [ ! -s "$_cat" ]; then
			info "leyendo catalogo de $_branch (~10 MB)..." >&2
			fetch -q -o - "${_root}/packagesite.pkg" 2>/dev/null \
				| tar -xOf - packagesite.yaml > "$_cat" 2>/dev/null
		fi
		[ -s "$_cat" ] || continue

		# La entrada del paquete es la linea cuyo campo name coincide exacto.
		_repopath=$(grep "\"name\":\"${_name}\"," "$_cat" 2>/dev/null \
			| grep -oE '"repopath":"[^"]*"' \
			| sed 's|.*":"||; s|"$||' | head -1)
		[ -n "$_repopath" ] || continue

		_file=$(basename "$_repopath")
		if fetch -q -o "$_file" "${_root}/${_repopath}" 2>/dev/null; then
			info "encontrado en $_branch: $_repopath" >&2
			_out="$_file"
			break
		fi
		rm -f "$_file"
	done

	[ -n "$_out" ] || return 1
	echo "$_out"
}

# ---------------------------------------------------------------------------
# Fase 1 - Bajar y desempaquetar amnezia-kmod
# ---------------------------------------------------------------------------
say "Fase 1: descargar amnezia-kmod"

KPKG=$(fetch_pkg amnezia-kmod)
if [ -z "${KPKG:-}" ]; then
	bad "no se pudo descargar amnezia-kmod para $ABI"
	info "verificar salida a internet:  fetch -o - https://pkg.freebsd.org/${ABI}/latest/meta.conf"
	exit 1
fi
ok "descargado: $KPKG"

rm -rf extract-kmod && mkdir extract-kmod
if ! tar -xf "$KPKG" -C extract-kmod 2>/dev/null; then
	bad "no se pudo desempaquetar $KPKG"
	exit 1
fi

KO=$(find extract-kmod -name 'if_amn.ko' -type f 2>/dev/null | head -1)
if [ -z "$KO" ]; then
	bad "if_amn.ko no aparecio dentro del paquete"
	find extract-kmod -type f -name '*.ko' 2>/dev/null | head
	exit 1
fi
KO=$(realpath "$KO" 2>/dev/null || echo "$WORK/$KO")
ok "modulo en: $KO"

# ---------------------------------------------------------------------------
# Fase 2 - LA PREGUNTA: carga el modulo?
# ---------------------------------------------------------------------------
say "Fase 2: kldload if_amn  <-- la pregunta que decide todo"

info "si el firewall se reinicia aca, la respuesta es NO y hay que ir a userspace"

if kldload "$KO" 2>/tmp/awg-spike-kld.err; then
	KMOD_LOADED=yes
	RESULT_KMOD=SI
	ok "if_amn CARGO en el kernel de pfSense"
	kldstat -v 2>/dev/null | grep -i amn | head -3
else
	bad "if_amn NO cargo"
	sed 's/^/          /' /tmp/awg-spike-kld.err 2>/dev/null
	dmesg | tail -5 | sed 's/^/          /'
	info "esto NO mata el proyecto: el camino userspace (amneziawg-go) sigue abierto"
fi

# ---------------------------------------------------------------------------
# Fase 3 - Cloner y nombres de interfaz
# ---------------------------------------------------------------------------
if [ "$KMOD_LOADED" = yes ]; then
	say "Fase 3: cloner y nombres de interfaz"

	info "cloners disponibles: $(ifconfig -C 2>/dev/null | tr -s ' ')"

	# Deliberadamente sin 'wg': si if_wg esta cargado, el cloner wg crearia
	# una interfaz WireGuard normal y daria un falso positivo.
	for _c in amn awg amnezia; do
		if ifconfig "$_c" create name "$TESTIF_STD" >/dev/null 2>&1; then
			CLONER="$_c"
			IF_CREATED="$TESTIF_STD"
			break
		fi
	done

	if [ -n "$CLONER" ]; then
		RESULT_CLONER=$CLONER
		ok "cloner '$CLONER' funciona -> creada $IF_CREATED"

		# Se puede renombrar al estilo pfSense? Esto decide si el fork de
		# wireguard-nativo puede conservar su convencion de nombres.
		if ifconfig "$IF_CREATED" name "$TESTIF_PFS" >/dev/null 2>&1; then
			IF_CREATED="$TESTIF_PFS"
			RESULT_RENAME=SI
			ok "renombrada a $TESTIF_PFS (se puede usar la convencion de pfSense)"
		else
			bad "no se pudo renombrar a $TESTIF_PFS"
		fi
	else
		bad "ningun cloner conocido creo una interfaz"
		info "revisar 'ifconfig -C' arriba para ver el nombre real"
	fi
fi

# ---------------------------------------------------------------------------
# Fase 4 - pfSense la ve como asignable?
#
# Se le pregunta a pfSense con sus propias funciones, no por inspeccion visual.
# ---------------------------------------------------------------------------
if [ -n "$IF_CREATED" ]; then
	say "Fase 4: pfSense la reconoce como asignable?"

	/usr/local/bin/php -r '
		require_once("config.inc");
		require_once("interfaces.inc");
		$target = $argv[1];
		$list = function_exists("get_interface_list") ? get_interface_list() : [];
		if (array_key_exists($target, $list)) {
			echo "  [OK]    $target aparece en get_interface_list() -> ASIGNABLE\n";
			exit(0);
		}
		echo "  [FALLA] $target NO aparece en get_interface_list()\n";
		echo "  .       interfaces que si aparecen: " . implode(", ", array_slice(array_keys($list), 0, 12)) . "\n";
		exit(1);
	' "$IF_CREATED" && RESULT_ASSIGN=SI
fi

# ---------------------------------------------------------------------------
# Fase 5 - El binario awg
# ---------------------------------------------------------------------------
say "Fase 5: amnezia-tools (awg)"

TPKG=$(fetch_pkg amnezia-tools)
if [ -n "${TPKG:-}" ]; then
	ok "descargado: $TPKG"
	rm -rf extract-tools && mkdir extract-tools
	tar -xf "$TPKG" -C extract-tools 2>/dev/null

	# Solo el binario: el paquete trae tambien un script de bash-completion
	# llamado 'awg', que no sirve y daba un falso positivo.
	AWG=$(find extract-tools -type f -name 'awg' -path '*/bin/*' 2>/dev/null | head -1)
	if [ -n "$AWG" ]; then
		chmod +x "$AWG"
		_ver=$("$AWG" --version 2>&1 | head -1)
		if [ -n "$_ver" ] && ! echo "$_ver" | grep -qi "syntax error\|not found"; then
			RESULT_AWG=SI
			ok "awg corre: $_ver"
		else
			bad "awg no ejecuta: $_ver"
			ldd "$AWG" 2>/dev/null | grep -i "not found" | sed 's/^/          /'
		fi
	else
		bad "binario awg no encontrado en el paquete"
	fi
else
	bad "no se pudo descargar amnezia-tools"
fi

# ---------------------------------------------------------------------------
# Resumen
# ---------------------------------------------------------------------------
say "RESUMEN"

printf '  modulo if_amn carga .......... %s\n' "$RESULT_KMOD"
printf '  cloner de interfaz ........... %s\n' "$RESULT_CLONER"
printf '  acepta nombre tun_awg0 ....... %s\n' "$RESULT_RENAME"
printf '  pfSense la ve asignable ...... %s\n' "$RESULT_ASSIGN"
printf '  binario awg corre ............ %s\n' "$RESULT_AWG"

printf '\n  VEREDICTO: '
if [ "$RESULT_KMOD" = SI ] && [ "$RESULT_ASSIGN" = SI ]; then
	printf 'CAMINO KERNEL ABIERTO\n'
	printf '  El fork de wireguard-nativo es casi un rename.\n'
	printf '  Cambiar kmod if_wg -> if_amn, cloner wg -> %s, binario wg -> awg.\n' "$RESULT_CLONER"
elif [ "$RESULT_KMOD" = SI ]; then
	printf 'MODULO OK, ASIGNACION DUDOSA\n'
	printf '  El modulo carga pero pfSense no lista la interfaz.\n'
	printf '  Revisar nombres tipo tun9NNN, como hace pfSense-pkg-amneziawg-client.\n'
else
	printf 'CAMINO USERSPACE\n'
	printf '  El modulo no carga. Usar amneziawg-go con nombres tun9NNN,\n'
	printf '  que es la arquitectura ya probada en 2.8.1 por Dmitry.\n'
fi
printf '\n'
