#!/bin/sh
#
# fetch-references.sh - Recupera el codigo de terceros en reference/.
#
# reference/ esta en el .gitignore porque es codigo de otros autores: no se
# redistribuye desde este repo. Este script lo vuelve a traer en un clon nuevo.
#
# Uso:   sh tools/fetch-references.sh
#

set -eu

DEST=reference

# repo_url  directorio_destino  para_que_sirve
REPOS="
amnezia-vpn/amneziawg-go|amneziawg-go|data plane userspace, el que se usa
amnezia-vpn/amneziawg-tools|amneziawg-tools|awg(8), formato de .conf, IPC
amnezia-vpn/amneziawg-linux-kernel-module|amneziawg-linux-kernel-module|referencia del protocolo
vgrebenschikov/wireguard-amnezia-kmod|wireguard-amnezia-kmod|fuente del kmod de FreeBSD (descartado)
qtronixx/pfSense-pkg-amneziawg-client|pfSense-pkg-amneziawg-client|plomeria del proceso, MIT
MrTheory/os-amneziawg|os-amneziawg|spec de validacion y watchdog, OPNsense
"

mkdir -p "$DEST"

echo "$REPOS" | while IFS='|' read -r repo dir purpose; do
	[ -n "$repo" ] || continue

	if [ -d "${DEST}/${dir}/.git" ]; then
		printf '  ya esta   %-32s %s\n' "$dir" "$purpose"
		continue
	fi

	printf '  clonando  %-32s %s\n' "$dir" "$purpose"
	git clone --depth 1 --quiet "https://github.com/${repo}" "${DEST}/${dir}"
done

# El paquete oficial de WireGuard es la base del fork. Se saca del arbol de
# ports de pfSense, no de un .pkg instalado, porque asi vienen tambien los
# archivos de empaquetado (Makefile, pkg-plist, pkg-install.in).
WG="${DEST}/pfSense-pkg-WireGuard-0.2.13"
if [ -d "$WG" ]; then
	printf '  ya esta   %-32s %s\n' "$(basename "$WG")" "base del fork"
else
	printf '  clonando  %-32s %s\n' "$(basename "$WG")" "base del fork"
	rm -rf .tmp-ports
	git clone --depth 1 --filter=blob:none --sparse --branch devel --quiet \
		https://github.com/pfsense/FreeBSD-ports .tmp-ports
	git -C .tmp-ports sparse-checkout set net/pfSense-pkg-WireGuard >/dev/null

	mkdir -p "$WG"
	cp -r .tmp-ports/net/pfSense-pkg-WireGuard/files/. "$WG"/
	cp .tmp-ports/net/pfSense-pkg-WireGuard/Makefile \
	   .tmp-ports/net/pfSense-pkg-WireGuard/pkg-plist \
	   .tmp-ports/net/pfSense-pkg-WireGuard/pkg-descr "$WG"/
	rm -rf .tmp-ports

	# git en Windows convierte a CRLF al hacer checkout; los archivos van a un
	# FreeBSD, y con CRLF los shebang de los scripts fallan.
	find "$WG" -type f -exec sed -i 's/\r$//' {} \;
fi

echo
echo "Listo. Ver docs/arquitectura.md para que aporta cada referencia."
