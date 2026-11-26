#******************************************************************************
#*
#*  Project : nginx-manager-2.0
#*  File    : Common.psm1
#*  Date    : 26/11/2026
#*  Author  : Semansys Development Team
#*
#*  Copyright (c) 2026
#*  Tel: +91 97863 37914
#*  Email: dev.digiboost.muthu@gmail.com
#*  All rights reserved, see the LICENSE file for more information.
#*
#******************************************************************************

<#
.SYNOPSIS
    Shared PowerShell functions for Nginx Manager 2.0.

.DESCRIPTION
    Every script in this project (start-all.ps1, stop-all.ps1, php-service.ps1,
    install-php.ps1, ...) imports this module. It is the PowerShell side of the
    "single source of truth" design:

      - {base}            : the install folder, ALWAYS computed at runtime from this
                            file's location - never written anywhere.
      - .env              : machine-specific values (PROJECTS_DIR, ports, ...).
      - data/settings.json: application state (installed PHP versions, ports).

    Sections:
      1. Paths & files     Get-ManagerBase, Write-Utf8NoBom, Test-HasAbsolutePath
      2. .env              Import-DotEnv, Get-ManagerEnv, Get-ProjectRoots, ConvertTo-ProjectRelative
      3. Settings          Get-ManagerSettings, Save-ManagerSettings, Initialize-ManagerSettings
      4. PHP               Get-UpstreamName, Get-DefaultPhpPort, Get-PhpThreadSafety,
                           Get-PhpExeVersion, Test-PhpAutostart, Get-PhpVersionById,
                           Start-PhpCgi, Stop-PhpCgi
      5. Processes/ports   Test-IsAdmin, Invoke-SelfElevate, Test-PortListening,
                           Get-PortOwnerProcess, Test-PathUnderBase
      6. Native programs   Invoke-Native, Invoke-Nginx, Get-NginxProcess, Get-OpenSslPath
      7. Output & jobs     Write-Step, Write-JobLog, Set-JobState

.NOTES
    Requires Admin : no (functions that need it say so)
    Called by      : every *.ps1 in the project, tests/ps/*.Tests.ps1
    Encoding rule  : this file is ASCII only. Windows PowerShell 5.1 reads BOM-less
                     files as ANSI, so non-ASCII characters would be corrupted.
                     Everything we WRITE is UTF-8 without BOM (see Write-Utf8NoBom).
#>

Set-StrictMode -Version 2.0

# =============================================================================
#  1. Paths & files
# =============================================================================

function Get-ManagerBase {
    <#
    .SYNOPSIS  Returns the install folder ({base}) with forward slashes, no trailing slash.
    .NOTES     This module lives in {base}\scripts\lib, so {base} is two levels up.
               $env:NM_BASE is a TEST-ONLY override (Pester points it at $TestDrive).
    #>
    if ($env:NM_BASE) { return ($env:NM_BASE -replace '\\', '/').TrimEnd('/') }
    $base = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
    return ($base -replace '\\', '/').TrimEnd('/')
}

function Write-Utf8NoBom {
    <#
    .SYNOPSIS  Writes text as UTF-8 WITHOUT a byte-order mark, creating parent folders.
    .NOTES     Never use Set-Content -Encoding UTF8 in this project: on PS 5.1 it writes a
               BOM, which breaks PHP's json_decode and nginx's config parser.
    #>
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)][AllowEmptyString()][string]$Text
    )
    $dir = Split-Path -Parent $Path
    if ($dir -and -not (Test-Path -LiteralPath $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
    [IO.File]::WriteAllText($Path, $Text, (New-Object Text.UTF8Encoding $false))
}

function Test-HasAbsolutePath {
    <#
    .SYNOPSIS  True when text contains a Windows drive path (C:\ or C:/). URLs such as
               https://host are not paths: the drive letter must not follow another letter.
    #>
    param([Parameter(Mandatory)][AllowEmptyString()][string]$Text)
    return ($Text -match '(?<![A-Za-z0-9])[A-Za-z]:[/\\]')
}

# =============================================================================
#  2. .env
# =============================================================================

function Import-DotEnv {
    <#
    .SYNOPSIS  Parses a .env file into a hashtable and exports each key to the process env.
    .DESCRIPTION
        Format: KEY=value per line, '#' comment lines, optional single/double quotes
        around the value, no ${VAR} expansion. Exporting to the process environment
        means child processes (php-cgi, nginx) see the same values.
    #>
    param([Parameter(Mandatory)][string]$Path)

    $result = @{}
    $text = [IO.File]::ReadAllText($Path)
    if ($text.Length -gt 0 -and $text[0] -eq [char]0xFEFF) { $text = $text.Substring(1) }

    foreach ($raw in ($text -split "`r?`n")) {
        $line = $raw.Trim()
        if ($line -eq '' -or $line.StartsWith('#')) { continue }
        $eq = $line.IndexOf('=')
        if ($eq -lt 1) { continue }
        $key   = $line.Substring(0, $eq).Trim()
        $value = $line.Substring($eq + 1).Trim()
        if ($value.Length -ge 2 -and (
                ($value.StartsWith('"') -and $value.EndsWith('"')) -or
                ($value.StartsWith("'") -and $value.EndsWith("'")))) {
            $value = $value.Substring(1, $value.Length - 2)
        }
        $result[$key] = $value
        [Environment]::SetEnvironmentVariable($key, $value, 'Process')
    }
    return $result
}

function Get-ManagerEnv {
    <#
    .SYNOPSIS  Loads {base}/.env, creating it from .env.example on first run.
    #>
    $path = Join-Path (Get-ManagerBase) '.env'
    $example = Join-Path (Get-ManagerBase) '.env.example'
    if (-not (Test-Path -LiteralPath $path) -and (Test-Path -LiteralPath $example)) {
        # First run: start from the template; values are edited on the manager's Settings page.
        Copy-Item -LiteralPath $example -Destination $path
    }
    if (-not (Test-Path -LiteralPath $path)) {
        throw "Settings file missing: neither .env nor .env.example exists in $(Get-ManagerBase)."
    }
    return Import-DotEnv $path
}

function Get-ProjectRoots {
    <#
    .SYNOPSIS  Project root folders from .env, keyed by their nginx variable name.
    .DESCRIPTION
        PROJECTS_DIR      -> projects_dir      ($projects_dir in nginx configs)
        PROJECTS_DIR_<X>  -> projects_dir_<x>  ($projects_dir_x)
        Empty values are skipped. Paths use forward slashes, no trailing slash.
    .OUTPUTS   [ordered] hashtable  varName -> path  (PROJECTS_DIR first)
    #>
    param([Parameter(Mandatory)][hashtable]$Env)
    $result = [ordered]@{}
    $keys = @($Env.Keys | Where-Object { $_ -cmatch '^PROJECTS_DIR(_[A-Z0-9]+)?$' } | Sort-Object { $_.Length }, { $_ })
    foreach ($k in $keys) {
        $v = [string]$Env[$k]
        if ($v.Trim() -eq '') { continue }
        $result[$k.ToLowerInvariant()] = ($v.Trim() -replace '\\', '/').TrimEnd('/')
    }
    return $result
}

function ConvertTo-ProjectRelative {
    <#
    .SYNOPSIS  'E:/projects/a/b' -> '$projects_dir/a/b' using the longest matching project root,
               or $null when the path is in none of them.
    #>
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)]$ProjectRoots
    )
    $norm = $Path -replace '\\', '/'
    $best = $null; $bestLen = -1
    foreach ($var in $ProjectRoots.Keys) {
        $root = ([string]$ProjectRoots[$var]).TrimEnd('/') + '/'
        if ($norm.StartsWith($root, [StringComparison]::OrdinalIgnoreCase) -and $root.Length -gt $bestLen) {
            $best = '$' + $var + '/' + $norm.Substring($root.Length); $bestLen = $root.Length
        } elseif ($norm.TrimEnd('/') -ieq $root.TrimEnd('/') -and $root.Length -gt $bestLen) {
            $best = '$' + $var; $bestLen = $root.Length
        }
    }
    return $best
}

# =============================================================================
#  3. Settings (data/settings.json)
# =============================================================================

function Get-ManagerSettingsPath {
    return (Join-Path (Get-ManagerBase) 'data\settings.json')
}

function Get-ManagerSettings {
    <#
    .SYNOPSIS  Reads data/settings.json as an object, or $null when it does not exist.
    #>
    $path = Get-ManagerSettingsPath
    if (-not (Test-Path -LiteralPath $path)) { return $null }
    $text = [IO.File]::ReadAllText($path)
    if ($text.Length -gt 0 -and $text[0] -eq [char]0xFEFF) { $text = $text.Substring(1) }
    return ($text | ConvertFrom-Json)
}

function Invoke-WithJsonLock {
    <#
    .SYNOPSIS  Runs a script block while holding "<file>.lock" - the same lock PHP's
               JsonStore takes with flock(), so the web UI and scripts never interleave.
    .NOTES     PHP's flock() on Windows locks the whole file range; locking byte 0 here
               overlaps it. Throws after TimeoutMs.
    #>
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)][scriptblock]$ScriptBlock,
        [int]$TimeoutMs = 15000
    )
    $dir = Split-Path -Parent $Path
    if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Force $dir | Out-Null }
    $fs = [IO.File]::Open("$Path.lock", 'OpenOrCreate', 'ReadWrite', 'ReadWrite')
    try {
        $deadline = [DateTime]::UtcNow.AddMilliseconds($TimeoutMs)
        while ($true) {
            try { $fs.Lock(0, 1); break }
            catch [IO.IOException] {
                if ([DateTime]::UtcNow -gt $deadline) { throw "Timed out waiting for the lock on $(Split-Path -Leaf $Path)." }
                Start-Sleep -Milliseconds 50
            }
        }
        try { & $ScriptBlock } finally { $fs.Unlock(0, 1) }
    } finally {
        $fs.Dispose()
    }
}

function Write-JsonAtomic {
    <#
    .SYNOPSIS  Writes JSON text through a temp file that replaces the target in one step,
               so a reader never sees a half-written file. Caller holds the lock.
    #>
    param([Parameter(Mandatory)][string]$Path, [Parameter(Mandatory)][string]$Text)
    $tmp = "$Path." + [guid]::NewGuid().ToString('N').Substring(0, 8) + '.tmp'
    Write-Utf8NoBom $tmp $Text
    try {
        if (Test-Path -LiteralPath $Path) { [IO.File]::Replace($tmp, $Path, [NullString]::Value) }
        else { [IO.File]::Move($tmp, $Path) }
    } catch {
        Remove-Item -LiteralPath $tmp -Force -ErrorAction SilentlyContinue
        throw
    }
}

function Save-ManagerSettings {
    <#
    .SYNOPSIS  Writes data/settings.json (UTF-8, no BOM, -Depth 10), locked and atomic.
    .NOTES     Use Update-ManagerSettings for read-modify-write: it re-reads under the lock.
    #>
    param([Parameter(Mandatory)]$Settings)
    $path = Get-ManagerSettingsPath
    Invoke-WithJsonLock $path { Write-JsonAtomic $path ($Settings | ConvertTo-Json -Depth 10) }
}

function Update-ManagerSettings {
    <#
    .SYNOPSIS  Re-reads data/settings.json under the lock, lets the script block change it
               (param($settings)), and saves it. Changes the web UI made meanwhile are kept.
    #>
    param([Parameter(Mandatory)][scriptblock]$Mutator, [int]$TimeoutMs = 15000)
    $path = Get-ManagerSettingsPath
    Invoke-WithJsonLock $path -TimeoutMs $TimeoutMs {
        $s = Get-ManagerSettings
        if (-not $s) { throw 'data\settings.json not found.' }
        & $Mutator $s
        Write-JsonAtomic $path ($s | ConvertTo-Json -Depth 10)
    }
}

function Initialize-ManagerSettings {
    <#
    .SYNOPSIS  Creates data/settings.json on a fresh machine by scanning php\*\php-cgi.exe.
    .DESCRIPTION
        Folders named *backup* or *.new are ignored. Each PHP gets the default port and
        upstream name, its real version (from php.exe) and its build flavour (ts/nts).
        The manager runs on PHP 8.3 when present, otherwise on the newest version.
    .OUTPUTS   $true when settings were created, $false when they already existed.
    #>
    if (Test-Path -LiteralPath (Get-ManagerSettingsPath)) { return $false }

    $phpRoot = Join-Path (Get-ManagerBase) 'php'
    $found = @()
    if (Test-Path -LiteralPath $phpRoot) {
        $found = @(Get-ChildItem -LiteralPath $phpRoot -Directory |
            Where-Object { $_.Name -notlike '*backup*' -and $_.Name -notlike '*.new' -and
                           $_.Name -match '^\d+\.\d+' -and
                           (Test-Path -LiteralPath (Join-Path $_.FullName 'php-cgi.exe')) } |
            Sort-Object { [version](($_.Name -replace '[^0-9.]', '') + '.0.0' -replace '^(\d+\.\d+\.\d+).*', '$1') })
    }

    $versions = @()
    $used = @()
    foreach ($dir in $found) {
        $port = Get-DefaultPhpPort $dir.Name $used
        $used += $port
        $versions += [pscustomobject]@{
            id           = $dir.Name
            port         = $port
            upstream     = Get-UpstreamName $dir.Name
            version      = Get-PhpExeVersion $dir.FullName
            threadSafety = Get-PhpThreadSafety $dir.FullName
        }
    }

    $manager = ''
    if ($versions | Where-Object { $_.id -eq '8.3' }) { $manager = '8.3' }
    elseif ($versions.Count) { $manager = $versions[-1].id }

    # Only the manager's own PHP starts with the server by default; the others are
    # switched on per version from the PHP Versions page ("Start with server").
    foreach ($v in $versions) {
        $v | Add-Member -NotePropertyName autostart -NotePropertyValue ($v.id -eq $manager)
    }

    Save-ManagerSettings ([pscustomobject]@{
        php = [pscustomobject]@{
            managerVersion = $manager
            feedUrl        = 'https://downloads.php.net/~windows/releases/releases.json'
            downloadBase   = 'https://downloads.php.net/~windows/releases/'
            peclBase       = 'https://downloads.php.net/~windows/pecl/releases/'
            versions       = @($versions)
        }
    })
    return $true
}

# =============================================================================
#  4. PHP
# =============================================================================

function Get-UpstreamName {
    <#
    .SYNOPSIS  nginx upstream name for a PHP folder id: '8.5.12' -> 'php8512'.
    #>
    param([Parameter(Mandatory)][string]$Id)
    return 'php' + ($Id -replace '[^0-9]', '')
}

function Get-DefaultPhpPort {
    <#
    .SYNOPSIS  Port convention: 9000 + major.minor digits (8.4 -> 9084, 8.5.12 -> 9085);
               when taken, the first free port from 9100 upwards.
    #>
    param(
        [Parameter(Mandatory)][string]$Id,
        [AllowEmptyCollection()][int[]]$UsedPorts = @()
    )
    if ($Id -match '^(\d+)\.(\d+)') {
        $port = 9000 + [int]($Matches[1] + $Matches[2])
        if ($UsedPorts -notcontains $port) { return $port }
    }
    $port = 9100
    while ($UsedPorts -contains $port) { $port++ }
    return $port
}

function Get-PhpThreadSafety {
    <#
    .SYNOPSIS  'ts' when a PHP folder holds a thread-safe build (php7ts.dll / php8ts.dll), else 'nts'.
    #>
    param([Parameter(Mandatory)][string]$PhpDir)
    if (Get-ChildItem -LiteralPath $PhpDir -Filter 'php*ts.dll' -ErrorAction SilentlyContinue) { return 'ts' }
    return 'nts'
}

function Get-PhpExeVersion {
    <#
    .SYNOPSIS  The PHP_VERSION reported by <Dir>\php.exe (no ini loaded), or '' on failure.
    #>
    param([Parameter(Mandatory)][string]$Dir)
    $exe = Join-Path $Dir 'php.exe'
    if (-not (Test-Path -LiteralPath $exe)) { return '' }
    $r = Invoke-Native -FilePath $exe -Arguments @('-n', '-r', 'echo PHP_VERSION;')
    if ($r.ExitCode -ne 0) { return '' }
    return $r.Output.Trim()
}

function Test-PhpAutostart {
    <#
    .SYNOPSIS  Should start-all.ps1 start this PHP? Yes for the manager's own version
               (the UI needs it) and for versions whose "autostart" flag is on.
    #>
    param([Parameter(Mandatory)]$Version, [AllowEmptyString()][string]$ManagerVersion = '')
    if ($Version.id -eq $ManagerVersion) { return $true }
    $flag = $Version.PSObject.Properties['autostart']
    return [bool]($flag -and $flag.Value)
}

function Enable-PhpIniDefaults {
    <#
    .SYNOPSIS  Starter php.ini for a fresh install: uncomments the Windows extension_dir
               line and the given extensions. Works for both ini styles:
               ";extension=curl" (PHP 7.2+) and ";extension=php_curl.dll" (PHP 5.x - 7.1).
    .NOTES     Only existing commented lines are switched on - nothing is appended - so an
               extension the build does not ship can never break PHP's start-up.
    #>
    param(
        [Parameter(Mandatory)][AllowEmptyString()][string]$IniText,
        [string[]]$Extensions = @()
    )
    $text = [regex]::Replace($IniText, '(?m)^;\s*(extension_dir\s*=\s*"ext")', '$1')
    foreach ($ext in $Extensions) {
        $name = [regex]::Escape($ext)
        # Only the LAST matching line: php.ini-development also shows ";extension=mysqli" as an
        # example in its documentation text, and enabling both loads the module twice.
        $found = [regex]::Matches($text, "(?m)^;\s*(extension\s*=\s*(?:php_)?$name(?:\.dll)?)(?=\r?$)")
        if ($found.Count -gt 0) {
            $m = $found[$found.Count - 1]
            $text = $text.Substring(0, $m.Index) + $m.Groups[1].Value + $text.Substring($m.Index + $m.Length)
        }
    }
    return $text
}

function Get-MissingExtensionDlls {
    <#
    .SYNOPSIS  Extension DLL names in the old ext\ folder that the new build does not ship
               (third-party ones such as oci8 or xdebug) - copied over on update.
    #>
    param([Parameter(Mandatory)][string]$OldExtDir, [Parameter(Mandatory)][string]$NewExtDir)
    if (-not (Test-Path -LiteralPath $OldExtDir)) { return @() }
    $have = @{}
    if (Test-Path -LiteralPath $NewExtDir) {
        Get-ChildItem -LiteralPath $NewExtDir -Filter '*.dll' | ForEach-Object { $have[$_.Name.ToLowerInvariant()] = $true }
    }
    return @(Get-ChildItem -LiteralPath $OldExtDir -Filter '*.dll' |
        Where-Object { -not $have.ContainsKey($_.Name.ToLowerInvariant()) } |
        Sort-Object Name | ForEach-Object { $_.Name })
}

function Test-FolderMovable {
    <#
    .SYNOPSIS  True when a folder can be renamed right now - i.e. no file inside is held open
               (an IDE using php.exe, an antivirus scan, a running php-cgi ...).
    .NOTES     Renames the folder to "<name>.movetest" and straight back.
    #>
    param([Parameter(Mandatory)][string]$Path)
    $probe = $Path.TrimEnd('\', '/') + '.movetest'
    try {
        [IO.Directory]::Move($Path, $probe)
    } catch {
        return $false
    }
    [IO.Directory]::Move($probe, $Path)
    return $true
}

function Switch-PhpFolder {
    <#
    .SYNOPSIS  Replaces php\<id> with the freshly extracted build, keeping the old one as backup.
    .DESCRIPTION
        Target -> Backup, then Staging -> Target. When the second move fails the old build
        is moved back, so php\<id> is never left missing. Throws on failure.
    #>
    param(
        [Parameter(Mandatory)][string]$Target,
        [Parameter(Mandatory)][string]$Staging,
        [Parameter(Mandatory)][string]$Backup
    )
    [IO.Directory]::Move($Target, $Backup)
    try {
        [IO.Directory]::Move($Staging, $Target)
    } catch {
        [IO.Directory]::Move($Backup, $Target)
        throw "Could not move the new build into place - the previous version was put back. ($($_.Exception.Message))"
    }
}

function Find-PhpProblems {
    <#
    .SYNOPSIS  Lines of php.exe / test-runner output that mean "this PHP cannot run the
               manager cleanly": Deprecated, Warning, Notice, Fatal/Parse errors, failed tests.
    #>
    param([AllowEmptyString()][string]$Text)
    # PHP's own message prefixes (case-sensitive, at the start of a line, optionally "PHP "),
    # plus failed tests. "PASS ..." lines are never problems, whatever the test is called.
    $php = '^(PHP )?(Deprecated|Warning|Notice|Fatal error|Parse error|Errors parsing)\b'
    return @($Text -split "`r?`n" | Where-Object {
        $l = $_.Trim()
        $l -ne '' -and -not $l.StartsWith('PASS ') -and
        ($l -cmatch $php -or $l -cmatch '^FAIL ' -or $l -cmatch 'Uncaught ')
    })
}

function Test-ManagerCompat {
    <#
    .SYNOPSIS  Checks the manager's code with a given php.exe before the manager is switched
               to it (or before an update is installed): php -l on every file under
               <CodeDir>\manager and the test suite (<CodeDir>\tests\run.php when present),
               all with every error, warning and deprecation shown.
    .OUTPUTS   [pscustomobject]@{ Ok = bool; Problems = string[] }
    #>
    param(
        [Parameter(Mandatory)][string]$PhpExe,
        [Parameter(Mandatory)][string]$CodeDir
    )
    $ini = Join-Path (Split-Path -Parent $PhpExe) 'php.ini'
    $common = @('-d', 'error_reporting=-1', '-d', 'display_errors=1', '-d', 'display_startup_errors=1', '-d', 'log_errors=0')
    if (Test-Path -LiteralPath $ini) { $common = @('-c', $ini) + $common }

    $problems = New-Object System.Collections.Generic.List[string]
    $files = @(Get-ChildItem -LiteralPath (Join-Path $CodeDir 'manager') -Recurse -Filter '*.php' -File -ErrorAction SilentlyContinue)
    foreach ($f in $files) {
        $r = Invoke-Native -FilePath $PhpExe -Arguments ($common + @('-l', $f.FullName)) -WorkingDirectory $CodeDir
        foreach ($line in Find-PhpProblems $r.Output) { $problems.Add($line) }
        if ($r.ExitCode -ne 0 -and -not (Find-PhpProblems $r.Output)) { $problems.Add("php -l failed for $($f.Name)") }
    }
    $runner = Join-Path $CodeDir 'tests\run.php'
    if (Test-Path -LiteralPath $runner) {
        $r = Invoke-Native -FilePath $PhpExe -Arguments ($common + @($runner)) -WorkingDirectory $CodeDir
        foreach ($line in Find-PhpProblems $r.Output) { $problems.Add($line) }
        if ($r.ExitCode -ne 0 -and $problems.Count -eq 0) { $problems.Add('The test suite failed: ' + ($r.Output -split "`r?`n" | Select-Object -Last 1)) }
    }
    return [pscustomobject]@{ Ok = ($problems.Count -eq 0); Problems = $problems.ToArray() }
}

function Find-ExtensionDll {
    <#
    .SYNOPSIS  The extension DLL inside an unpacked PECL zip, and the name php.ini enables.
    .DESCRIPTION
        Usually php_<ext>.dll (name <ext>). Some extensions ship one DLL per library version
        instead, e.g. oci8: php_oci8_19.dll (Oracle Instant Client 19) - then the name is
        oci8_19; with several variants the newest one wins (19 before 12c before 11g).
        Returns $null when the folder holds neither.
    #>
    param([Parameter(Mandatory)][string]$Folder, [Parameter(Mandatory)][string]$Ext)
    $exact = Get-ChildItem -LiteralPath $Folder -Recurse -File -Filter "php_$Ext.dll" | Select-Object -First 1
    if ($exact) { return [pscustomobject]@{ File = $exact; Name = $Ext } }
    $prefix = "php_$($Ext)_"
    $variants = @(Get-ChildItem -LiteralPath $Folder -Recurse -File -Filter "$prefix*.dll" | ForEach-Object {
        $suffix = $_.BaseName.Substring($prefix.Length)
        $num = if ($suffix -match '^(\d+)') { [int]$Matches[1] } else { 0 }
        [pscustomobject]@{ File = $_; Name = "$($Ext)_$suffix"; Rank = $num }
    })
    if ($variants.Count -eq 0) { return $null }
    $best = $variants | Sort-Object Rank -Descending | Select-Object -First 1
    return [pscustomobject]@{ File = $best.File; Name = $best.Name }
}

function Get-VcRuntimeHint {
    <#
    .SYNOPSIS  Which Microsoft Visual C++ runtime a PHP zip needs, from the compiler in its name.
    #>
    param([Parameter(Mandatory)][string]$ZipName)
    # Microsoft's own links: the current runtime downloads directly; the older ones are listed
    # (with their downloads) on Microsoft's page of supported Visual C++ runtimes.
    $current = 'https://aka.ms/vs/17/release/vc_redist.x64.exe'
    $older   = 'https://learn.microsoft.com/cpp/windows/latest-supported-vc-redist'
    $arch    = if ($ZipName -match '-x86\.zip$') { 'x86' } else { 'x64' }
    if ($ZipName -match '-(vc|vs)(\d+)-') {
        switch ([int]$Matches[2]) {
            9       { return "Microsoft Visual C++ 2008 Redistributable ($arch) (download: $older)" }
            11      { return "Microsoft Visual C++ 2012 Redistributable ($arch) (download: $older)" }
            default { return "Microsoft Visual C++ 2015-2022 Redistributable (x64), latest version (download: $current)" }
        }
    }
    return "the Microsoft Visual C++ Redistributable that matches this PHP build (download: $older)"
}

function Get-PhpVersionById {
    <#
    .SYNOPSIS  The settings entry { id, port, upstream, version, threadSafety } for a PHP id.
    #>
    param([Parameter(Mandatory)][string]$Id)
    $s = Get-ManagerSettings
    if (-not $s) { throw 'data\settings.json not found - run start-all.ps1 once.' }
    $v = @($s.php.versions) | Where-Object { $_.id -eq $Id } | Select-Object -First 1
    if (-not $v) { throw "PHP '$Id' is not in data\settings.json." }
    return $v
}

function Start-PhpCgi {
    <#
    .SYNOPSIS  Starts php-cgi for one settings entry, hidden, bound to 127.0.0.1:<port>.
    .DESCRIPTION
        Skips when the port is already listening. PHP_EXTRA_PATH from .env is prepended
        to PATH (e.g. Oracle Instant Client for oci8) and PHP_FCGI_MAX_REQUESTS=0 keeps
        php-cgi from exiting after 500 requests. Waits up to 5 s for the port.
    .OUTPUTS   'running' | 'already-running' | 'foreign' (port used by another program,
               e.g. the old v1 manager) | 'missing' (no php-cgi.exe) | 'failed' |
               'stale' (port still listening but its process is gone - another process
               holds an inherited copy of the socket; nothing would answer requests)
    #>
    param([Parameter(Mandatory)]$Version)

    if (Test-PortListening $Version.port) {
        $owner = Get-PortOwnerProcess $Version.port
        if (-not $owner) {
            # The listening process has exited but the socket is still open somewhere.
            # Give Windows a moment to release it before giving up.
            for ($i = 0; $i -lt 15 -and (Test-PortListening $Version.port); $i++) { Start-Sleep -Milliseconds 200 }
            if (Test-PortListening $Version.port) { return 'stale' }
        } else {
            $path = $null
            try { $path = $owner.Path } catch { }
            if ($path -and -not (Test-PathUnderBase $path 'php')) { return 'foreign' }
            return 'already-running'
        }
    }
    $dir = Join-Path (Get-ManagerBase) ('php\' + $Version.id)
    $exe = Join-Path $dir 'php-cgi.exe'
    if (-not (Test-Path -LiteralPath $exe)) { return 'missing' }

    $env:PHP_FCGI_MAX_REQUESTS = '0'
    if ($env:PHP_EXTRA_PATH) {
        foreach ($p in ($env:PHP_EXTRA_PATH -split ';' | Where-Object { $_ })) {
            if (($env:PATH -split ';') -notcontains $p) { $env:PATH = "$p;$env:PATH" }
        }
    }

    Start-Process -FilePath $exe -ArgumentList "-b 127.0.0.1:$($Version.port)" `
        -WorkingDirectory $dir -WindowStyle Hidden | Out-Null

    for ($i = 0; $i -lt 25; $i++) {
        if (Test-PortListening $Version.port) { return 'running' }
        Start-Sleep -Milliseconds 200
    }
    return 'failed'
}

function Stop-PhpCgi {
    <#
    .SYNOPSIS  Stops the php-cgi listening on a settings entry's port.
    .DESCRIPTION
        Only processes whose executable lives under {base}\php are stopped - a php-cgi
        from another install (e.g. the old v1 manager) on the same port is left alone.
    .OUTPUTS   'stopped' | 'not-running' | 'foreign' (port owned by a process outside {base}) |
               'still-listening' (process stopped but the port did not close within 5 s)
    #>
    param([Parameter(Mandatory)]$Version)

    $proc = Get-PortOwnerProcess $Version.port
    if (-not $proc) { return 'not-running' }
    $path = $null
    try { $path = $proc.Path } catch { }
    if (-not $path -or -not (Test-PathUnderBase $path 'php')) { return 'foreign' }

    Stop-Process -Id $proc.Id -Force
    for ($i = 0; $i -lt 25; $i++) {
        if (-not (Test-PortListening $Version.port)) { return 'stopped' }
        Start-Sleep -Milliseconds 200
    }
    return 'still-listening'
}

# =============================================================================
#  5. Processes & ports
# =============================================================================

function Test-IsAdmin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    return (New-Object Security.Principal.WindowsPrincipal $id).IsInRole(
        [Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Get-SelfElevateParams {
    <#
    .SYNOPSIS  The Start-Process parameters for the Administrator copy of a script.
    .DESCRIPTION
        -Hidden (the desktop app starts the scripts): the elevated PowerShell runs without a
        window. Started by hand the window stays, it shows what happens.
    #>
    param(
        [Parameter(Mandatory)][string]$ScriptPath,
        [string[]]$Arguments = @(),
        [switch]$Hidden
    )
    $argLine = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', ('"' + $ScriptPath + '"')) + $Arguments
    $params = @{ FilePath = 'powershell.exe'; Verb = 'RunAs'; ArgumentList = ($argLine -join ' ') }
    if ($Hidden) { $params.WindowStyle = 'Hidden' }
    return $params
}

function Invoke-SelfElevate {
    <#
    .SYNOPSIS  Relaunches a script as Administrator (one UAC prompt) and exits this one.
    .DESCRIPTION
        Does nothing when already elevated. Everything the elevated script starts
        (nginx, php-cgi and therefore the web UI) inherits the admin token - needed for
        certutil (trusting certificates), the hosts file and installing PHP.
    #>
    param(
        [Parameter(Mandatory)][string]$ScriptPath,
        [string[]]$Arguments = @(),
        # Wait for the elevated copy to finish (the desktop app's uninstaller stops the
        # servers this way before deleting the Nginx Manager folder).
        [switch]$Wait,
        # No PowerShell window for the elevated copy (the desktop app runs the scripts).
        [switch]$Hidden
    )
    if (Test-IsAdmin) { return }
    $params = Get-SelfElevateParams -ScriptPath $ScriptPath -Arguments $Arguments -Hidden:$Hidden
    if ($Wait) {
        $p = Start-Process @params -Wait -PassThru
        exit $p.ExitCode
    }
    Start-Process @params | Out-Null
    exit 0
}

function Test-PortListening {
    <#
    .SYNOPSIS  True when something accepts TCP connections on 127.0.0.1:<Port> (300 ms timeout).
    #>
    param([Parameter(Mandatory)][int]$Port)
    $client = New-Object Net.Sockets.TcpClient
    try {
        $ar = $client.BeginConnect('127.0.0.1', $Port, $null, $null)
        if (-not $ar.AsyncWaitHandle.WaitOne(300)) { return $false }
        $client.EndConnect($ar)
        return $true
    } catch {
        return $false
    } finally {
        $client.Close()
    }
}

function Get-PortOwnerProcess {
    <#
    .SYNOPSIS  The process listening on a local TCP port, or $null.
    #>
    param([Parameter(Mandatory)][int]$Port)
    $conn = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue | Select-Object -First 1
    if (-not $conn) { return $null }
    return (Get-Process -Id $conn.OwningProcess -ErrorAction SilentlyContinue)
}

function Test-PathUnderBase {
    <#
    .SYNOPSIS  True when a file path is inside {base}\<Sub> (case-insensitive).
    #>
    param([Parameter(Mandatory)][string]$Path, [string]$Sub = '')
    $root = ((Join-Path (Get-ManagerBase) $Sub) -replace '\\', '/').TrimEnd('/') + '/'
    return (($Path -replace '\\', '/').StartsWith($root, [StringComparison]::OrdinalIgnoreCase))
}

# =============================================================================
#  6. nginx & OpenSSL
# =============================================================================

function Invoke-Native {
    <#
    .SYNOPSIS  Runs an external program and returns @{ ExitCode; Output } (stdout+stderr text).
    .NOTES     Windows PowerShell 5.1 turns ANY stderr line of a native program into an
               error record, which is terminating under $ErrorActionPreference = 'Stop'.
               nginx -t and openssl both write normal progress to stderr, so every native
               call in this project goes through here with the preference relaxed locally.
    #>
    param(
        [Parameter(Mandatory)][string]$FilePath,
        [string[]]$Arguments = @(),
        [string]$WorkingDirectory
    )
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    if ($WorkingDirectory) { Push-Location $WorkingDirectory }
    try {
        $out = & $FilePath @Arguments 2>&1 | ForEach-Object { "$_" }
        return @{ ExitCode = $LASTEXITCODE; Output = (@($out) -join "`n") }
    } finally {
        if ($WorkingDirectory) { Pop-Location }
        $ErrorActionPreference = $prev
    }
}

function Invoke-Nginx {
    <#
    .SYNOPSIS  Runs nginx.exe with -p {base}/ so every relative path in the configs resolves
               against the install folder. Returns @{ ExitCode; Output }.
    #>
    param([string[]]$Arguments = @())
    $base = Get-ManagerBase
    $exe = Join-Path $base 'nginx.exe'
    if (-not (Test-Path -LiteralPath $exe)) { throw "nginx.exe not found in $base" }
    return Invoke-Native -FilePath $exe -Arguments (@('-p', "$base/") + $Arguments) -WorkingDirectory $base
}

function Get-NginxProcess {
    <#
    .SYNOPSIS  nginx processes started from THIS install (other nginx installs are ignored).
    #>
    return @(Get-Process -Name nginx -ErrorAction SilentlyContinue | Where-Object {
        $p = $null; try { $p = $_.Path } catch { }
        $p -and (Test-PathUnderBase $p)
    })
}

function Get-OpenSslPath {
    <#
    .SYNOPSIS  openssl.exe: .env OPENSSL_BIN, then Git for Windows, then PATH. $null if none.
    #>
    if ($env:OPENSSL_BIN -and (Test-Path -LiteralPath $env:OPENSSL_BIN)) { return $env:OPENSSL_BIN }
    foreach ($pf in @($env:ProgramFiles, ${env:ProgramFiles(x86)})) {
        if (-not $pf) { continue }
        $c = Join-Path $pf 'Git\usr\bin\openssl.exe'
        if (Test-Path -LiteralPath $c) { return $c }
    }
    $cmd = Get-Command openssl -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($cmd) { return $cmd.Source }
    return $null
}

# =============================================================================
#  7. Output & background jobs (data/jobs/<id>.json + <id>.log, polled by the web UI)
# =============================================================================

function Write-Step {
    <#
    .SYNOPSIS  Consistent console output: [*] info, [+] ok, [!] warn, [x] error.
    #>
    param(
        [Parameter(Mandatory)][string]$Message,
        [ValidateSet('info', 'ok', 'warn', 'error')][string]$Level = 'info'
    )
    switch ($Level) {
        'ok'    { Write-Host "[+] $Message" -ForegroundColor Green }
        'warn'  { Write-Host "[!] $Message" -ForegroundColor Yellow }
        'error' { Write-Host "[x] $Message" -ForegroundColor Red }
        default { Write-Host "[*] $Message" -ForegroundColor Cyan }
    }
}

function Get-JobFilePath {
    <# Validates the id (no path separators, no '..') and returns data/jobs/<id>.<ext>. #>
    param([Parameter(Mandatory)][string]$JobId, [Parameter(Mandatory)][string]$Extension)
    if ($JobId -notmatch '^[A-Za-z0-9][A-Za-z0-9_.-]*$' -or $JobId -match '\.\.') {
        throw "Invalid job id: $JobId"
    }
    return (Join-Path (Get-ManagerBase) ("data\jobs\$JobId.$Extension"))
}

function Write-JobLog {
    <#
    .SYNOPSIS  Appends "[HH:mm:ss] message" to data/jobs/<id>.log (UTF-8, no BOM).
    #>
    param([Parameter(Mandatory)][string]$JobId, [Parameter(Mandatory)][AllowEmptyString()][string]$Message)
    $path = Get-JobFilePath $JobId 'log'
    $dir = Split-Path -Parent $path
    if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Force $dir | Out-Null }
    $line = '[' + (Get-Date -Format 'HH:mm:ss') + '] ' + $Message + "`n"
    [IO.File]::AppendAllText($path, $line, (New-Object Text.UTF8Encoding $false))
}

function Set-JobState {
    <#
    .SYNOPSIS  Merges { id, state, message, updatedAt } into data/jobs/<id>.json, keeping
               fields written by the web UI (title, createdAt, ...).
    #>
    param(
        [Parameter(Mandatory)][string]$JobId,
        [Parameter(Mandatory)][ValidateSet('queued', 'running', 'done', 'failed')][string]$State,
        [AllowEmptyString()][string]$Message = ''
    )
    $path = Get-JobFilePath $JobId 'json'
    Invoke-WithJsonLock $path {
        $data = [ordered]@{}
        if (Test-Path -LiteralPath $path) {
            $old = [IO.File]::ReadAllText($path) | ConvertFrom-Json
            foreach ($p in $old.PSObject.Properties) { $data[$p.Name] = $p.Value }
        }
        $data['id']        = $JobId
        $data['state']     = $State
        $data['message']   = $Message
        # ISO 8601 WITH the UTC offset - the web UI compares it with createdAt written by PHP.
        $data['updatedAt'] = (Get-Date).ToString('yyyy-MM-ddTHH:mm:sszzz')
        Write-JsonAtomic $path ([pscustomobject]$data | ConvertTo-Json -Depth 10)
    }
}

Export-ModuleMember -Function *
