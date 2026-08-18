# Builds dist/pfSense-pkg-AmneziaWG-<version>-<abi>.pkg from the src/ tree.
#
#   powershell -ExecutionPolicy Bypass -File build\make-pkg.ps1 -Abi FreeBSD:16:amd64
#
# The archive replicates the layout of a working third-party pfSense package:
# a tar with +COMPACT_MANIFEST and +MANIFEST first, then the files under
# absolute paths. FreeBSD pkg(8) installs it with a plain `pkg add`.
#
# Unlike a pure-PHP package, this one ships two FreeBSD binaries, so it cannot
# declare abi '*': there is one .pkg per ABI, and the binaries are pulled from
# the FreeBSD package repo for that ABI. They are cached under bin/<abi>/.

param(
    [ValidateSet('FreeBSD:16:amd64')]
    [string]$Abi = 'FreeBSD:16:amd64'
)

$ErrorActionPreference = 'Stop'

$version = '1.2.0'
$name    = 'pfSense-pkg-AmneziaWG'

$root  = Split-Path $PSScriptRoot -Parent
$src   = Join-Path $root 'src'
$dist  = Join-Path $root 'dist'
$stage = Join-Path $dist 'stage'
$binCache = Join-Path $root "bin\$($Abi -replace ':', '-')"

# pkg(8) writes the ABI as FreeBSD:16:amd64 but the arch field as
# freebsd:16:x86:64. Both appear in the manifest and they are not the same
# string; getting arch wrong makes pkg refuse the package.
$abiParts = $Abi -split ':'
$arch = "freebsd:$($abiParts[1]):x86:64"

Write-Host "Building $name $version for $Abi"

# ------------------------------------------------------------- binaries ---
# Resolve each binary's real path from the repo catalogue. The filename cannot
# be guessed: the repo publishes under All/Hashed/ with a hash suffix, and
# listing /All/ returns Forbidden because there is no autoindex.

function Get-RepoBinary([string]$pkgName, [string]$binName) {
    $target = Join-Path $binCache $binName
    if (Test-Path $target) {
        Write-Host "  cached    $binName"
        return $target
    }

    New-Item -ItemType Directory -Force -Path $binCache | Out-Null
    $work = Join-Path $env:TEMP "awgpkg-$([guid]::NewGuid().ToString('N'))"
    New-Item -ItemType Directory -Force -Path $work | Out-Null

    # PowerShell 5.1 negotiates TLS 1.0 by default on some hosts, which
    # pkg.freebsd.org refuses.
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

    try {
        $catalog = Join-Path $binCache 'packagesite.yaml'
        if (-not (Test-Path $catalog)) {
            Write-Host "  fetching  package catalogue for $Abi (~10 MB)"
            $catPkg = Join-Path $work 'packagesite.pkg'
            Invoke-WebRequest -Uri "https://pkg.freebsd.org/$Abi/latest/packagesite.pkg" `
                              -OutFile $catPkg -UseBasicParsing

            Push-Location $work
            try {
                tar -xf $catPkg packagesite.yaml
                if ($LASTEXITCODE -ne 0) {
                    throw "could not unpack the catalogue. Windows tar needs zstd support; check 'tar --version'."
                }
            } finally { Pop-Location }

            Move-Item (Join-Path $work 'packagesite.yaml') $catalog
        }

        $line = Select-String -Path $catalog -Pattern "`"name`":`"$pkgName`"," -SimpleMatch | Select-Object -First 1
        if ($null -eq $line) { throw "$pkgName is not in the $Abi catalogue" }

        if ($line.Line -notmatch '"repopath":"([^"]+)"') { throw "no repopath for $pkgName" }
        $repopath = $Matches[1]

        Write-Host "  fetching  $repopath"
        $pkgFile = Join-Path $work 'pkg.pkg'
        Invoke-WebRequest -Uri "https://pkg.freebsd.org/$Abi/latest/$repopath" `
                          -OutFile $pkgFile -UseBasicParsing

        Push-Location $work
        try {
            tar -xf $pkgFile
            if ($LASTEXITCODE -ne 0) { throw "could not unpack $repopath" }
        } finally { Pop-Location }

        # -Filter on the leaf name would also match the bash-completion script,
        # which is called awg too and is not the binary.
        $found = Get-ChildItem -Path $work -Recurse -File -Filter $binName |
                 Where-Object { $_.DirectoryName -like '*\bin' } |
                 Select-Object -First 1
        if ($null -eq $found) { throw "$binName not found inside $repopath" }

        Copy-Item $found.FullName $target
        return $target
    } catch {
        # Reaching pkg.freebsd.org from the build machine is convenient, not
        # required: a firewall behind a restrictive network, or a build box
        # with no route out, still needs to produce a package. tools/
        # fetch-binaries.sh runs on the firewall itself -- which by definition
        # can reach the repo -- and hands back a tarball to unpack here.
        throw @"
could not fetch $binName for $Abi from pkg.freebsd.org.

  $($_.Exception.Message)

Stage the binaries by hand instead. On a pfSense box running this ABI:

    sh /root/fetch-binaries.sh          (tools/fetch-binaries.sh)
    # writes /root/awg-bin-$Abi.tar.gz

then back here:

    scp root@FIREWALL:/root/awg-bin-$Abi.tar.gz .
    tar -xzf awg-bin-$Abi.tar.gz -C "$binCache"

and run this script again. It only downloads when bin/ is empty.
"@
    } finally {
        if (Test-Path $work) { Remove-Item -Recurse -Force $work }
    }
}

$goBin  = Get-RepoBinary 'amneziawg-go'  'amneziawg-go'
$awgBin = Get-RepoBinary 'amnezia-tools' 'awg'

# -------------------------------------------------------------- staging ---
if (Test-Path $stage) { Remove-Item -Recurse -Force $stage }
New-Item -ItemType Directory -Force -Path $stage | Out-Null

# src/ mirrors the install layout exactly, so the file map is discovered
# rather than maintained by hand.
$files = [ordered]@{}
Get-ChildItem -Path $src -Recurse -File | Sort-Object FullName | ForEach-Object {
    $rel = $_.FullName.Substring($src.Length).TrimStart('\')
    $files['/' + ($rel -replace '\\', '/')] = $_.FullName
}
if ($files.Count -eq 0) { throw "src/ is empty. Run tools/fork-from-wireguard.sh first." }

# The two binaries are not in src/: they come from the FreeBSD repo per ABI.
$files['/usr/local/bin/amneziawg-go'] = $goBin
$files['/usr/local/bin/awg']          = $awgBin

# The licence texts are not in src/ either: their install path carries the
# version, so it can only be built here. They are not decoration -- awg is
# GPLv2, and section 1 of that licence requires its text to travel with the
# binary. NOTICE says which of the four licences covers which part, and where
# the corresponding source of the GPLv2 one is.
$licenseDir = "/usr/local/share/licenses/$name-$version"
$licenseFiles = [ordered]@{
    "$licenseDir/APACHE20" = Join-Path $root 'licenses\APACHE20'
    "$licenseDir/GPLv2"    = Join-Path $root 'licenses\GPLv2'
    "$licenseDir/MIT"      = Join-Path $root 'licenses\MIT'
    "$licenseDir/NOTICE"   = Join-Path $root 'NOTICE'
}
foreach ($entry in $licenseFiles.GetEnumerator()) { $files[$entry.Key] = $entry.Value }

# Only these get executable mode and skip text normalisation.
$binaryPaths = @('/usr/local/bin/amneziawg-go', '/usr/local/bin/awg')

$textExt = @('.php', '.inc', '.xml', '.js')

foreach ($entry in $files.GetEnumerator()) {
    $srcFile = $entry.Value
    if (-not (Test-Path $srcFile)) { throw "missing source file: $srcFile" }

    $dstFile = Join-Path $stage ($entry.Key.TrimStart('/') -replace '/', '\')
    New-Item -ItemType Directory -Force -Path (Split-Path $dstFile -Parent) | Out-Null

    # The licence texts have no extension, so they are named explicitly rather
    # than matched: they are text and have to land with LF like everything else.
    $isText = (($textExt -contains [System.IO.Path]::GetExtension($srcFile)) -or
               ($licenseFiles.Contains($entry.Key))) -and
              ($binaryPaths -notcontains $entry.Key)

    if ($isText) {
        # Normalize to LF, no BOM: what pfSense itself ships
        $text = [System.IO.File]::ReadAllText($srcFile) -replace "`r`n", "`n"

        # src/ keeps the ports template's %%PKGVERSION%%, so the tree stays
        # honest about where it came from and the version has a single source
        # of truth here. Nothing else substitutes it: left alone, Package
        # Manager shows the literal placeholder as the version.
        $text = $text -replace '%%PKGVERSION%%', $version

        [System.IO.File]::WriteAllText($dstFile, $text, (New-Object System.Text.UTF8Encoding($false)))
    } else {
        Copy-Item $srcFile $dstFile
    }
}

# Refuse to package any text file carrying a UTF-8 BOM: it breaks header() in
# PHP. The binaries are exempt -- an ELF never starts with a BOM anyway, but
# checking them would be meaningless.
foreach ($entry in $files.GetEnumerator()) {
    if ($binaryPaths -contains $entry.Key) { continue }
    $staged = Join-Path $stage ($entry.Key.TrimStart('/') -replace '/', '\')
    $b = [System.IO.File]::ReadAllBytes($staged)
    if ($b.Length -ge 3 -and $b[0] -eq 0xEF -and $b[1] -eq 0xBB -and $b[2] -eq 0xBF) {
        throw "BOM found in $($entry.Key)"
    }
}

# ------------------------------------------------------------ manifests ---
$fileMap  = [ordered]@{}
$flatsize = 0

foreach ($pkgPath in $files.Keys) {
    $stagedFile = Join-Path $stage ($pkgPath.TrimStart('/') -replace '/', '\')
    $hash = (Get-FileHash $stagedFile -Algorithm SHA256).Hash.ToLower()
    $fileMap[$pkgPath] = '1$' + $hash
    $flatsize += (Get-Item $stagedFile).Length
}

$dirMap = [ordered]@{
    '/usr/local/pkg/amneziawg'                = 'y'
    '/usr/local/pkg/amneziawg/classes'        = 'y'
    '/usr/local/pkg/amneziawg/includes'       = 'y'
    '/usr/local/www/awg'                      = 'y'
    '/usr/local/www/awg/js'                   = 'y'
    '/usr/local/share/pfSense-pkg-AmneziaWG'  = 'y'
    '/etc/inc/priv'                           = 'y'
    '/etc/inc'                                = 'y'
    $licenseDir                               = 'y'
}

$meta = [ordered]@{
    name         = $name
    origin       = "net-vpn/$name"
    version      = $version
    comment      = 'AmneziaWG: obfuscated WireGuard that gets past DPI'
    maintainer   = 'marcelomayo1974@gmail.com'
    www          = 'https://github.com/MarceloMayo74/pfsense-amneziawg'
    abi          = $Abi
    arch         = $arch
    prefix       = '/'
    flatsize     = $flatsize
    # Three licences, all at once and each over a different part: the package
    # is Apache 2.0, the bundled awg is GPLv2 and amneziawg-go is MIT. Declaring
    # only APACHE20 would have been a plain lie in the metadata, which is what
    # `pkg info -l` and every mirror reads.
    licenselogic = 'and'
    licenses     = @('APACHE20', 'GPLv2', 'MIT')
    desc         = 'Adds VPN > AmneziaWG: tunnels and peers managed from the GUI, with the obfuscation parameters that let a WireGuard tunnel survive deep packet inspection. The data plane is amneziawg-go in userspace, so no kernel module is required.'
}

$compact = $meta | ConvertTo-Json -Compress -Depth 5

$full = [ordered]@{}
foreach ($k in $meta.Keys) { $full[$k] = $meta[$k] }
$full['files']       = $fileMap
$full['directories'] = $dirMap

# These are what make pfSense run awg_install() and awg_deinstall(): without
# them the files land on disk but the package never wires itself into the GUI.
$full['scripts'] = [ordered]@{
    install   = "#!/bin/sh`n`nif [ `"`${2}`" != `"POST-INSTALL`" ]; then`n`texit 0`nfi`n`n`${PKG_ROOTDIR}/usr/local/bin/php -f `${PKG_ROOTDIR}/etc/rc.packages $name `${2}"
    deinstall = "#!/bin/sh`n`n/usr/local/bin/php -f /etc/rc.packages $name `${2}"
}

$fullJson = $full | ConvertTo-Json -Compress -Depth 5

$utf8 = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText((Join-Path $stage '+COMPACT_MANIFEST'), $compact, $utf8)
[System.IO.File]::WriteAllText((Join-Path $stage '+MANIFEST'), $fullJson, $utf8)

# -------------------------------------------------------------- archive ---
# Windows' bundled tar cannot rewrite entry names to absolute paths, so the
# ustar archive is written directly: 512 byte headers, data padded to 512,
# closed by two zero blocks, then gzip. libarchive (what pkg(8) uses) reads
# gzip'ed ustar archives natively.

function New-TarHeader([string]$entryName, [long]$size, [long]$mtime, [int]$mode) {
    $header = New-Object byte[] 512
    $ascii = [System.Text.Encoding]::ASCII

    $put = { param($text, $offset) $b = $ascii.GetBytes($text); [Array]::Copy($b, 0, $header, $offset, $b.Length) }

    & $put $entryName 0                                            # name
    & $put ('{0:D7}' -f $mode) 100                                 # mode
    & $put '0000000' 108                                           # uid 0 (root)
    & $put '0000000' 116                                           # gid 0 (wheel)
    & $put ([Convert]::ToString($size, 8).PadLeft(11, '0')) 124    # size, octal
    & $put ([Convert]::ToString($mtime, 8).PadLeft(11, '0')) 136   # mtime, octal
    $header[156] = [byte][char]'0'                                 # typeflag: regular file
    & $put "ustar" 257                                             # magic
    $header[262] = 0
    & $put '00' 263                                                # version
    & $put 'root' 265                                              # uname
    & $put 'wheel' 297                                             # gname

    # Checksum: computed with the checksum field itself set to spaces
    for ($i = 148; $i -lt 156; $i++) { $header[$i] = 0x20 }
    $sum = 0
    foreach ($b in $header) { $sum += $b }
    & $put ([Convert]::ToString($sum, 8).PadLeft(6, '0')) 148
    $header[154] = 0
    $header[155] = 0x20

    return $header
}

New-Item -ItemType Directory -Force -Path $dist | Out-Null
$out = Join-Path $dist "$name-$version-$($Abi -replace ':', '-').pkg"
if (Test-Path $out) { Remove-Item -Force $out }

$mtime = [long][System.DateTimeOffset]::UtcNow.ToUnixTimeSeconds()

# Manifests first, then the files: the order pkg expects
$entries = [ordered]@{
    '+COMPACT_MANIFEST' = (Join-Path $stage '+COMPACT_MANIFEST')
    '+MANIFEST'         = (Join-Path $stage '+MANIFEST')
}

foreach ($pkgPath in $files.Keys) {
    $entries[$pkgPath] = Join-Path $stage ($pkgPath.TrimStart('/') -replace '/', '\')
}

$tarStream = New-Object System.IO.MemoryStream

foreach ($entry in $entries.GetEnumerator()) {
    $data = [System.IO.File]::ReadAllBytes($entry.Value)

    # The binaries have to land executable; everything else is data.
    if ($binaryPaths -contains $entry.Key) { $mode = 755 } else { $mode = 644 }

    $header = New-TarHeader $entry.Key $data.Length $mtime $mode
    $tarStream.Write($header, 0, 512)
    $tarStream.Write($data, 0, $data.Length)

    $pad = (512 - ($data.Length % 512)) % 512
    if ($pad -gt 0) { $tarStream.Write((New-Object byte[] $pad), 0, $pad) }
}

# End of archive: two zero blocks
$tarStream.Write((New-Object byte[] 1024), 0, 1024)

$fileStream = [System.IO.File]::Create($out)
$gzip = New-Object System.IO.Compression.GZipStream($fileStream, [System.IO.Compression.CompressionLevel]::Optimal)
$tarBytes = $tarStream.ToArray()
$gzip.Write($tarBytes, 0, $tarBytes.Length)
$gzip.Dispose()
$fileStream.Dispose()
$tarStream.Dispose()

# --------------------------------------------------------------- verify ---
$listing = tar -tf $out 2>$null
if (($listing[0] -ne '+COMPACT_MANIFEST') -or ($listing[1] -ne '+MANIFEST')) {
    throw "manifests are not the first entries: $($listing[0..1] -join ', ')"
}

$missing = @($files.Keys | Where-Object { $listing -notcontains $_ })
if ($missing.Count -gt 0) { throw "entries missing from the archive: $($missing -join ', ')" }

$check = Join-Path $dist 'check'
if (Test-Path $check) { Remove-Item -Recurse -Force $check }
New-Item -ItemType Directory -Force -Path $check | Out-Null

Push-Location $check
try {
    tar -xf $out
    if ($LASTEXITCODE -ne 0) { throw 'extraction failed' }
} finally {
    Pop-Location
}

foreach ($pkgPath in $files.Keys) {
    $extracted = Join-Path $check ($pkgPath.TrimStart('/') -replace '/', '\')
    $hash = '1$' + (Get-FileHash $extracted -Algorithm SHA256).Hash.ToLower()
    if ($hash -ne $fileMap[$pkgPath]) { throw "checksum mismatch after extraction: $pkgPath" }
}

Remove-Item -Recurse -Force $check

$magic = ([System.IO.File]::ReadAllBytes($out)[0..3] | ForEach-Object { $_.ToString('X2') }) -join ' '

Write-Host ''
Write-Host "OK  $out"
Write-Host "    $((Get-Item $out).Length) bytes, magic $magic, $($files.Count) files, flatsize $flatsize"
Write-Host "    abi $Abi, arch $arch"
Write-Host ''
Write-Host 'Install on pfSense:'
Write-Host "    scp $out root@FIREWALL:/root/"
Write-Host "    ssh root@FIREWALL pkg add /root/$(Split-Path $out -Leaf)"
