<#
Export-NetVibe.ps1

Crea uno o più file “bundle” con il contenuto del progetto, escludendo cartelle vendor/inutili e (se presente)
rispettando le regole di .gitignore. Ogni file viene preceduto dal path COMPLETO e il contenuto inizia sempre
dalla riga successiva.

Compatibile con Windows PowerShell 5.1 e PowerShell 7+.
#>

[CmdletBinding()]
param(
    # Root del progetto
    [Parameter(Mandatory = $false)]
    [string]$Root = (Get-Location).Path,

    # File di output (se -MaxOutputFileSizeMB > 0 verranno creati chunk _001, _002, ...)
    [Parameter(Mandatory = $false)]
    [string]$OutPath = (Join-Path (Get-Location).Path "_EXPORT\netvibe-bundle.txt"),

    # Se > 0, spezza l'output in più file da ~questa dimensione (MB)
    [Parameter(Mandatory = $false)]
    [int]$MaxOutputFileSizeMB = 0,

    # Se > 0, salta i file più grandi di questa dimensione (MB)
    [Parameter(Mandatory = $false)]
    [int]$MaxInputFileSizeMB = 0,

    # Prova a usare Git per rispettare al 100% .gitignore (se disponibile)
    [switch]$PreferGit = $true,

    # Considera anche i .gitignore (fallback quando Git non è disponibile). Default: true
    [switch]$UseGitIgnore = $true,

    # Stampa meno output
    [switch]$Quiet
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Write-Info([string]$msg) {
    if (-not $Quiet) { Write-Host $msg }
}

function Normalize-Root([string]$p) {
    $rp = (Resolve-Path -LiteralPath $p).Path
    return $rp.TrimEnd([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar)
}

function Get-RelUnixPath([string]$root, [string]$fullPath) {
    $rootUri = New-Object System.Uri(($root + [IO.Path]::DirectorySeparatorChar))
    $fileUri = New-Object System.Uri($fullPath)
    $rel = $rootUri.MakeRelativeUri($fileUri).ToString()
    return [System.Uri]::UnescapeDataString($rel) # già con /
}

function Convert-GitIgnorePatternToRegex([string]$pattern) {
    # Ritorna una regex che matcha su path con separatore '/'
    $p = $pattern.Trim()
    $anchored = $false

    if ($p.StartsWith('/')) { $anchored = $true; $p = $p.Substring(1) }
    if ($p.EndsWith('/'))   { $p = $p.Substring(0, $p.Length - 1) }

    # Normalizza
    $p = $p -replace '\\','/'

    # Gestione glob: **, *, ?
    $p2 = $p -replace '\*\*','<DS>'
    $p2 = [regex]::Escape($p2)
    $p2 = $p2 -replace '<DS>','.*'
    $p2 = $p2 -replace '\\\*','[^/]*'
    $p2 = $p2 -replace '\\\?','[^/]'

    # Prefisso: ancorato o ovunque a boundary di path
    $prefix = if ($anchored) { '^' } else { '(^|.*/)' }

    # Suffisso: match su file o directory (se matcha una directory, i file dentro verranno matchati come prefisso)
    $suffix = '($|/)'

    return ($prefix + $p2 + $suffix)
}

function Get-GitIgnoreRules([string]$root) {
    $rules = New-Object System.Collections.Generic.List[object]
    if (-not $UseGitIgnore) { return $rules }

    $gitignoreFiles = Get-ChildItem -LiteralPath $root -Recurse -File -Force -Filter ".gitignore" -ErrorAction SilentlyContinue
    foreach ($gi in $gitignoreFiles) {
        $baseDir = $gi.Directory.FullName
        $baseRel = Get-RelUnixPath $root $baseDir
        if ([string]::IsNullOrWhiteSpace($baseRel)) { $baseRel = '' } # root

        $lines = Get-Content -LiteralPath $gi.FullName -ErrorAction SilentlyContinue
        $lineNo = 0
        foreach ($line in $lines) {
            $lineNo++
            $raw = $line.Trim()
            if ($raw.Length -eq 0) { continue }
            if ($raw.StartsWith('#')) { continue }

            $neg = $false
            if ($raw.StartsWith('!')) { $neg = $true; $raw = $raw.Substring(1) }
            $raw = $raw.Trim()
            if ($raw.Length -eq 0) { continue }

            $rules.Add([pscustomobject]@{
                BaseRel   = $baseRel
                BaseDepth = if ($baseRel -eq '') { 0 } else { ($baseRel -split '/').Count }
                Pattern   = $raw
                Negate    = $neg
                Regex     = (Convert-GitIgnorePatternToRegex $raw)
                Source    = $gi.FullName
                Line      = $lineNo
            })
        }
    }

    # Ordine: prima i .gitignore più “alti”, poi quelli più “profondi”; a parità, ordine di riga
    return $rules | Sort-Object BaseDepth, Source, Line
}

function Test-IsIgnoredByRules([string]$relPath, $rules) {
    # relPath: path relativo al root, con '/'
    $ignored = $false

    foreach ($r in $rules) {
        $base = $r.BaseRel
        # Applica la regola solo se il file è sotto quel base (directory del .gitignore)
        if ($base -ne '') {
            if (-not ($relPath -like ($base + "/*")) -and ($relPath -ne $base)) { continue }
            $pathFromBase = $relPath.Substring($base.Length).TrimStart('/')
        } else {
            $pathFromBase = $relPath
        }

        if ($pathFromBase -match $r.Regex) {
            $ignored = -not $r.Negate
        }
    }

    return $ignored
}

function Get-FilesViaGit([string]$root) {
    $git = Get-Command git -ErrorAction SilentlyContinue
    if (-not $git) { return $null }

    $inside = $false
    try {
        $out = & git -C $root rev-parse --is-inside-work-tree 2>$null
        if ($out -match 'true') { $inside = $true }
    } catch { $inside = $false }

    if (-not $inside) { return $null }

    # Tracked + untracked, escludendo ignorati (rispetta .gitignore)
    $rel = & git -C $root ls-files -co --exclude-standard
    return $rel | Where-Object { $_ -and $_.Trim().Length -gt 0 }
}

# ---------------- MAIN ----------------

$Root = Normalize-Root $Root

# Exclude “di buon senso” (in aggiunta a .gitignore) — tarati su NetVibe/Laravel
$DefaultExcludeDirFragments = @(
    "/.git/",
    "/vendor/",
    "/node_modules/",
    "/storage/",
    "/bootstrap/cache/",
    "/docker/mysql-data/",
    "/docker/redis-data/",

    "/public/build/",
    "/public/js/filament/",
    "/public/css/filament/",
    "/public/fonts/",
    "/public/js/html2media/",
    "/remote-agent/vendor/",
    "/docs/docs-laravelv12/",
    "/docs/docs-filamentv4/"
)

$BinaryExtensions = @(
    ".png",".jpg",".jpeg",".gif",".webp",".svgz",
    ".pdf",".zip",".7z",".rar",".gz",".tar",
    ".woff",".woff2",".ttf",".otf",".eot",
    ".ico",".icns",
    ".mp3",".mp4",".mov",".avi",".mkv",".wav",
    ".exe",".dll",".pdb",".so",".dylib",
    ".sqlite",".db"
)

$maxOutBytes  = if ($MaxOutputFileSizeMB -gt 0) { [int64]$MaxOutputFileSizeMB * 1MB } else { 0 }
$maxInBytes   = if ($MaxInputFileSizeMB -gt 0)  { [int64]$MaxInputFileSizeMB  * 1MB } else { 0 }

# Output paths
$outDir = Split-Path -Parent $OutPath
if ([string]::IsNullOrWhiteSpace($outDir)) { $outDir = (Get-Location).Path }
if (-not (Test-Path -LiteralPath $outDir)) { New-Item -ItemType Directory -Path $outDir | Out-Null }

$baseName = [IO.Path]::GetFileNameWithoutExtension($OutPath)
$ext      = [IO.Path]::GetExtension($OutPath)
if ([string]::IsNullOrWhiteSpace($ext)) { $ext = ".txt" }

$chunkIndex = 1
$currentBytes = 0
$writer = $null

function Open-Writer([int]$idx) {
    $global:currentBytes = 0

    $fileName = if ($maxOutBytes -gt 0) {
        "{0}_{1:D3}{2}" -f $baseName, $idx, $ext
    } else {
        "{0}{1}" -f $baseName, $ext
    }

    $fullOut = Join-Path $outDir $fileName

    $enc = New-Object System.Text.UTF8Encoding($false) # no BOM
    $global:writer = New-Object System.IO.StreamWriter($fullOut, $false, $enc)
    $global:writer.NewLine = "`n"

    Write-Info "Output: $fullOut"
    return $fullOut
}

function Close-Writer() {
    if ($global:writer) {
        $global:writer.Flush()
        $global:writer.Dispose()
        $global:writer = $null
    }
}

function Ensure-Capacity([int64]$bytesToAdd) {
    if ($maxOutBytes -le 0) { return }
    if (($global:currentBytes + $bytesToAdd) -le $maxOutBytes) { return }

    Close-Writer
    $global:chunkIndex++
    Open-Writer $global:chunkIndex | Out-Null
}

function Write-Line([string]$line) {
    $bytes = [System.Text.Encoding]::UTF8.GetByteCount($line + "`n")
    Ensure-Capacity $bytes
    $global:writer.WriteLine($line)
    $global:currentBytes += $bytes
}

function Write-FileBlock([string]$filePath, [string]$content) {
    $header = "===== FILE: {0} =====`n" -f $filePath
    if ($null -eq $content) { $content = "" }
    if (-not $content.EndsWith("`n")) { $content += "`n" }
    $footer = "===== END FILE =====`n`n"

    $all = $header + $content + $footer
    $bytes = [System.Text.Encoding]::UTF8.GetByteCount($all)

    # garantisce che header+contenuto+footer stiano nello stesso chunk
    Ensure-Capacity $bytes
    $global:writer.Write($all)
    $global:currentBytes += $bytes
}

# Apri writer iniziale
Open-Writer $chunkIndex | Out-Null

# Header bundle
Write-Line ("# Bundle generato: {0}" -f (Get-Date).ToString("yyyy-MM-dd HH:mm:ss"))
Write-Line ("# Root: {0}" -f $Root)
Write-Line ""

# Regole .gitignore (fallback)
$rules = Get-GitIgnoreRules $Root

# Lista file
$filesRel = $null
if ($PreferGit) { $filesRel = Get-FilesViaGit $Root }

$files = New-Object System.Collections.Generic.List[object]

if ($filesRel) {
    Write-Info "Uso Git per selezionare i file (ls-files -co --exclude-standard)."
    foreach ($r in $filesRel) {
        $full = Join-Path $Root $r
        if (Test-Path -LiteralPath $full -PathType Leaf) {
            $files.Add((Get-Item -LiteralPath $full))
        }
    }
} else {
    Write-Info "Git non disponibile o non è un work-tree: uso scansione filesystem + .gitignore (best-effort)."
    $all = Get-ChildItem -LiteralPath $Root -Recurse -File -Force
    foreach ($f in $all) { $files.Add($f) }
}

# Filtra e scrivi
$included = 0
$skipped  = 0

foreach ($f in ($files | Sort-Object FullName)) {
    # Escludi eventuali output precedenti
    if ($f.FullName.StartsWith($outDir, [System.StringComparison]::OrdinalIgnoreCase)) { continue }

    $fullPath = (Resolve-Path -LiteralPath $f.FullName).Path
    $relPath  = Get-RelUnixPath $Root $fullPath

    # Exclude dir fragments
    $relWithSlashes = "/" + $relPath.TrimStart('/')
    $excludedByDefault = $false
    foreach ($frag in $DefaultExcludeDirFragments) {
        if ($relWithSlashes -like ("*" + $frag + "*")) { $excludedByDefault = $true; break }
    }
    if ($excludedByDefault) { $skipped++; continue }

    # Exclude binary by extension
    $extLower = [IO.Path]::GetExtension($fullPath).ToLowerInvariant()
    if ($BinaryExtensions -contains $extLower) { $skipped++; continue }

    # Max input file size
    if ($maxInBytes -gt 0 -and $f.Length -gt $maxInBytes) { $skipped++; continue }

    # .gitignore rules (best-effort) se non siamo passati da git
    if (-not $filesRel -and $rules.Count -gt 0) {
        if (Test-IsIgnoredByRules $relPath $rules) { $skipped++; continue }
    }

    # Leggi contenuto (testo)
    $content = $null
    try {
        $sr = New-Object System.IO.StreamReader($fullPath, $true)
        $content = $sr.ReadToEnd()
        $sr.Dispose()
    } catch {
        $skipped++; continue
    }

    # Scrittura: path completo su una riga, contenuto dalla riga successiva
    Write-FileBlock $fullPath $content
    $included++
}

Write-Line ("# FINE. Inclusi: {0} | Saltati: {1}" -f $included, $skipped)

Close-Writer
Write-Info "Completato. Inclusi: $included - Saltati: $skipped"
