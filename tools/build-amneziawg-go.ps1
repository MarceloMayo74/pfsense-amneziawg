# build-amneziawg-go.ps1 - compila amneziawg-go para FreeBSD con el parche de
# sticky sockets aplicado (docs/plan-sticky-freebsd.md).
#
#   powershell -ExecutionPolicy Bypass -File tools\build-amneziawg-go.ps1
#
# Que hace, y por que asi:
#
#  - Parte del tag v0.2.16 del clon de reference/, que es EXACTAMENTE la
#    version que empaqueta el port de FreeBSD (su version.go dice 0.0.20250522,
#    igual que el binario que veniamos distribuyendo). El unico delta contra lo
#    ya validado es el parche.
#  - No toca reference/: extrae el tag con git archive a un directorio de
#    trabajo en .tools/ y copia encima los archivos de patches/amneziawg-go/.
#  - Compila sin CGO, asi que no hace falta ninguna toolchain de FreeBSD: el
#    binario es Go puro y la ABI de syscalls de FreeBSD es estable. El mismo
#    binario sirve para FreeBSD 15 y 16.
#  - Marca la version como 0.0.20250522-sticky1 para poder distinguir a simple
#    vista un binario parcheado de uno de fabrica.

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
$srcTag = 'v0.2.16'
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

# La marca de version: un binario parcheado se tiene que poder reconocer
$versionFile = Join-Path $work 'version.go'
(Get-Content $versionFile) -replace '"(0\.0\.\d+)"', '"$1-sticky1"' | Set-Content -Encoding ascii $versionFile

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
