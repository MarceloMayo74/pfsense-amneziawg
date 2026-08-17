# build-amneziawg-go.ps1 - compila amneziawg-go para FreeBSD con el parche de
# sticky sockets aplicado (docs/plan-sticky-freebsd.md).
#
#   powershell -ExecutionPolicy Bypass -File tools\build-amneziawg-go.ps1
#
# Que hace, y por que asi:
#
#  - Parte de un tag del clon de reference/, extraido con git archive. Hoy es
#    v3.1.20260814, la rama 3.1 del protocolo (docs/plan-amneziawg-3.0.md); el
#    binario que se venia distribuyendo salia de v0.2.16, que es 2.0. El salto
#    es compatible hacia atras: sin las claves de 3.x en el .conf, el backend
#    emite los mismos bytes que antes.
#  - No toca reference/: extrae el tag con git archive a un directorio de
#    trabajo en .tools/ y copia encima los archivos de patches/amneziawg-go/.
#  - Compila sin CGO, asi que no hace falta ninguna toolchain de FreeBSD: el
#    binario es Go puro y la ABI de syscalls de FreeBSD es estable. El mismo
#    binario sirve para FreeBSD 15 y 16.
#  - Marca la version con el tag del que salio mas -sticky1, para distinguir a
#    simple vista un binario parcheado de uno de fabrica. No se puede usar el
#    numero de fabrica: upstream dejo version.go clavado en "0.0.20250522"
#    hasta en el tag 3.1, asi que no dice nada.

#  - Con -Test deja tambien .tools\sticky.test, el binario de tests del paquete
#    conn compilado para FreeBSD. Hay que correrlo EN EL FIREWALL: verifica
#    constantes y layouts contra el kernel de verdad, cosa que una corrida en
#    Windows no probaria.
#
#      scp .tools\sticky.test admin@FIREWALL:/root/
#      ssh admin@FIREWALL 'chmod +x /root/sticky.test; /root/sticky.test -test.v'

param(
    [switch]$Test
)

$ErrorActionPreference = 'Stop'

$repo   = Split-Path -Parent $PSScriptRoot
$goExe  = Join-Path $repo '.tools\go\bin\go.exe'
$srcTag = 'v3.1.20260814'
$work   = Join-Path $repo '.tools\awg-go-build'
$ref    = Join-Path $repo 'reference\amneziawg-go'

if (-not (Test-Path $goExe)) {
    throw "No hay toolchain de Go en .tools\go. Bajar el zip de go.dev/dl y extraerlo ahi."
}
if (-not (Test-Path $ref)) {
    throw "Falta reference\amneziawg-go. Correr: sh tools/fetch-references.sh"
}

# --- extraer el tag limpio, sin tocar reference/ -------------------------
if (Test-Path $work) { Remove-Item -Recurse -Force $work }
New-Item -ItemType Directory -Force -Path $work | Out-Null

# A archivo intermedio: un pipe de PowerShell corrompe los bytes del tar
$tarball = Join-Path $work 'src.tar'

Push-Location $ref
try {
    git archive -o $tarball $srcTag
    if ($LASTEXITCODE -ne 0) { throw "git archive $srcTag fallo (correr git fetch --depth 1 origin tag $srcTag en reference/amneziawg-go)" }
} finally {
    Pop-Location
}

tar -xf $tarball -C $work
if ($LASTEXITCODE -ne 0) { throw 'extraccion del tar fallo' }
Remove-Item $tarball

# --- aplicar el parche ---------------------------------------------------
$patches = Join-Path $repo 'patches\amneziawg-go'

Copy-Item (Join-Path $patches 'sticky_freebsd.go')      (Join-Path $work 'conn\') -Force
Copy-Item (Join-Path $patches 'controlfns_freebsd.go')  (Join-Path $work 'conn\') -Force
Copy-Item (Join-Path $patches 'sticky_default.go')      (Join-Path $work 'conn\') -Force
Copy-Item (Join-Path $patches 'sticky_freebsd_test.go') (Join-Path $work 'conn\') -Force

# La marca de version: un binario parcheado se tiene que poder reconocer, y el
# numero que trae version.go no sirve para eso -- sigue diciendo "0.0.20250522"
# hasta en el tag 3.1. Se estampa el tag del que salio.
$versionFile = Join-Path $work 'version.go'
$stamp       = ($srcTag -replace '^v', '') + '-sticky1'

# Ojo: -match sobre un array devuelve los elementos que matchean, no un
# booleano, asi que la comprobacion va sobre el texto entero.
$stamped = (Get-Content $versionFile) -replace 'const Version = ".*"', "const Version = `"$stamp`""
if (($stamped -join "`n") -notmatch [regex]::Escape($stamp)) { throw "no se pudo estampar la version en version.go" }
$stamped | Set-Content -Encoding ascii $versionFile

# --- compilar ------------------------------------------------------------
$env:GOOS        = 'freebsd'
$env:GOARCH      = 'amd64'
$env:CGO_ENABLED = '0'
$env:GOMODCACHE  = Join-Path $repo '.tools\gomodcache'
$env:GOFLAGS     = '-trimpath'

$out = Join-Path $repo 'bin\FreeBSD-16-amd64\amneziawg-go'

Push-Location $work
try {
    & $goExe build -ldflags '-s -w' -o $out .
    if ($LASTEXITCODE -ne 0) { throw 'go build fallo' }

    # go vet del paquete parcheado, con el mismo target
    & $goExe vet ./conn/
    if ($LASTEXITCODE -ne 0) { throw 'go vet fallo en conn/' }

    if ($Test) {
        & $goExe test -c -o (Join-Path $repo '.tools\sticky.test') ./conn/
        if ($LASTEXITCODE -ne 0) { throw 'no se pudo compilar el binario de tests' }
    }
} finally {
    Pop-Location
    Remove-Item Env:GOOS, Env:GOARCH, Env:CGO_ENABLED, Env:GOMODCACHE, Env:GOFLAGS -ErrorAction SilentlyContinue
}

$size = (Get-Item $out).Length
Write-Host ""
Write-Host "OK  $out"
Write-Host "    $size bytes, $srcTag + sticky sockets para FreeBSD"
Write-Host ""
Write-Host "El mismo binario sirve para bin\FreeBSD-15-amd64\ si ese ABI se compila."

if ($Test) {
    Write-Host ""
    Write-Host "Tests en .tools\sticky.test -- correrlos EN EL FIREWALL:"
    Write-Host "    scp .tools\sticky.test admin@FIREWALL:/root/"
    Write-Host "    ssh admin@FIREWALL 'chmod +x /root/sticky.test; /root/sticky.test -test.v'"
}
