#******************************************************************************
#*
#*  Project : nginx-manager-2.0
#*  File    : install-php.ps1
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
    Installs a new PHP version, or updates an installed one to a newer patch.

.DESCRIPTION
    Runs as a background job started from the PHP Versions page (or by hand).

    Both modes
      1. Download   php.net zip -> temp\ (TLS 1.2, no progress bar = much faster on PS 5.1)
      2. Verify     SHA-256 when php.net publishes one (supported branches); archived
                    builds have no published checksum and rely on HTTPS only
      3. Extract    -> php\<Id>.new
      4. Smoke test php.exe -n -v; a missing Visual C++ runtime is reported by name

    install                                   update
      5. php.ini from php.ini-development       5. copy the old php.ini + every extension DLL
         (extension_dir + common extensions)       the new build does not ship (oci8, xdebug ...)
      6. php\<Id>.new -> php\<Id>               6. stop php-cgi, old folder -> backups\php\...,
      7. register in settings.json                 php\<Id>.new -> php\<Id>
         (port 90XX / next free, autostart off) 7. start again if it was running (always for the
      8. regenerate nginx upstreams, test,         manager's own PHP); if it does not come up the
         reload nginx                              old folder is restored and started
      9. start the new php-cgi                  8. new version saved in settings.json

    Any failure: job "failed", temp files and the half-extracted folder are removed.

.PARAMETER JobId     Background-job id (data\jobs\<id>.json/.log), set by the web UI.
.PARAMETER Mode      install | update
.PARAMETER Id        Folder id under php\ (e.g. 5.6, 8.3 or 8.3.37 for a side-by-side install).
.PARAMETER Version   Full version being installed (e.g. 5.6.40).
.PARAMETER ZipName   Zip file name from the php.net catalogue (e.g. php-5.6.40-Win32-VC11-x64.zip).
.PARAMETER Location  releases | archives - which php.net folder the zip lives in.
.PARAMETER Sha256    Expected SHA-256 (optional; empty for archived builds).
.PARAMETER ThreadSafety  ts | nts - stored in settings.json.

.EXAMPLE
    .\scripts\install-php.ps1 -Mode install -Id 5.6 -Version 5.6.40 -ZipName php-5.6.40-Win32-VC11-x64.zip -Location archives -ThreadSafety ts

.NOTES
    Requires Admin : yes (writes php\, stops/starts elevated php-cgi); the web UI is elevated
    Called by      : manager JobRunner (PHP Versions page), you
    Encoding       : ASCII only.
#>
[CmdletBinding()]
param(
    [string]$JobId,
    [Parameter(Mandatory)][ValidateSet('install', 'update')][string]$Mode,
    [Parameter(Mandatory)][ValidatePattern('^\d+\.\d+(\.\d+)?$')][string]$Id,
    [Parameter(Mandatory)][ValidatePattern('^\d+\.\d+\.\d+$')][string]$Version,
    [Parameter(Mandatory)][ValidatePattern('^php-[0-9A-Za-z.-]+-x64\.zip$')][string]$ZipName,
    [Parameter(Mandatory)][ValidateSet('releases', 'archives')][string]$Location,
    [ValidatePattern('^([0-9A-Fa-f]{64})?$')][string]$Sha256 = '',
    [ValidateSet('ts', 'nts')][string]$ThreadSafety = 'ts'
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'   # Invoke-WebRequest is ~10x slower with the progress bar
Import-Module (Join-Path $PSScriptRoot 'lib\Common.psm1') -Force

function Write-Out([string]$Message, [string]$Level = 'info') {
    Write-Step $Message $Level
    if ($JobId) { Write-JobLog $JobId $Message }
}

$base    = Get-ManagerBase
$phpRoot = Join-Path $base 'php'
$target  = Join-Path $phpRoot $Id
$staging = Join-Path $phpRoot ($Id + '.new')
$existing = $null; $wasRunning = $false; $isManager = $false; $stoppedByUs = $false   # read by the catch block
$zip     = Join-Path $base ('temp\php-' + $Version + '-' + [guid]::NewGuid().ToString('N').Substring(0, 8) + '.zip')

try {
    if ($JobId) { Set-JobState $JobId 'running' "PHP $Version - $Mode" }
    [void](Get-ManagerEnv)
    $settings = Get-ManagerSettings
    if (-not $settings) { throw 'data\settings.json not found - run start-all.ps1 once.' }
    $existing = @($settings.php.versions) | Where-Object { $_.id -eq $Id } | Select-Object -First 1

    if ($Mode -eq 'install' -and ((Test-Path -LiteralPath $target) -or $existing)) {
        throw "php\$Id already exists - choose another version or use Update."
    }
    if ($Mode -eq 'update' -and (-not $existing -or -not (Test-Path -LiteralPath $target))) {
        throw "PHP $Id is not installed - nothing to update."
    }

    # -- 1. Download ---------------------------------------------------------------
    $downloadBase = if ($settings.php.PSObject.Properties['downloadBase']) { $settings.php.downloadBase } else { 'https://downloads.php.net/~windows/releases/' }
    $url = $downloadBase.TrimEnd('/') + '/' + $(if ($Location -eq 'archives') { 'archives/' } else { '' }) + $ZipName
    Write-Out "Downloading $url"
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    New-Item -ItemType Directory -Force (Split-Path $zip) | Out-Null
    Invoke-WebRequest -Uri $url -OutFile $zip -UseBasicParsing -UserAgent 'NginxManager/2.0'
    Write-Out ("Downloaded {0:N1} MB" -f ((Get-Item $zip).Length / 1MB))

    # -- 2. Verify ---------------------------------------------------------------------
    if ($Sha256) {
        $hash = (Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash
        if ($hash -ine $Sha256) { throw "Checksum mismatch - expected $Sha256, got $hash. The download was discarded." }
        Write-Out 'SHA-256 checksum verified' 'ok'
    } else {
        Write-Out 'php.net publishes no checksum for archived builds - relying on the HTTPS download' 'warn'
    }

    # -- 3. Extract ----------------------------------------------------------------------
    if (Test-Path -LiteralPath $staging) { Remove-Item -LiteralPath $staging -Recurse -Force }
    Expand-Archive -LiteralPath $zip -DestinationPath $staging -Force
    Write-Out "Extracted to php\$Id.new"

    # -- 4. Smoke test -------------------------------------------------------------------
    $probe = Invoke-Native -FilePath (Join-Path $staging 'php.exe') -Arguments @('-n', '-v')
    if ($probe.ExitCode -ne 0) {
        throw ("This PHP build does not run on this machine (exit code {0}). Install {1}, then try again." -f $probe.ExitCode, (Get-VcRuntimeHint $ZipName))
    }
    Write-Out (($probe.Output -split "`n")[0]) 'ok'

    if ($Mode -eq 'install') {
        # -- 5. php.ini --------------------------------------------------------------------
        $template = Join-Path $staging 'php.ini-development'
        if (Test-Path -LiteralPath $template) {
            $ini = Enable-PhpIniDefaults ([IO.File]::ReadAllText($template)) @(
                'curl', 'fileinfo', 'gd', 'gd2', 'intl', 'mbstring', 'mysqli', 'openssl', 'pdo_mysql', 'pdo_sqlite', 'sodium', 'zip')
            Write-Utf8NoBom (Join-Path $staging 'php.ini') $ini
            Write-Out 'Created php.ini from php.ini-development (common extensions enabled)'
        }
        $check = Invoke-Native -FilePath (Join-Path $staging 'php.exe') -Arguments @('-c', (Join-Path $staging 'php.ini'), '-v')
        if ($check.Output -match 'Warning|Unable to load') { Write-Out ("php.ini warnings:`n" + $check.Output) 'warn' }

        # -- 6. Move into place --------------------------------------------------------------
        Rename-Item -LiteralPath $staging -NewName $Id

        # -- 7. Register -------------------------------------------------------------------------
        # Re-read under the lock: another install may have registered a version meanwhile.
        $script:entry = $null
        Update-ManagerSettings {
            param($s)
            $used = @(@($s.php.versions) | ForEach-Object { [int]$_.port })
            $script:entry = [pscustomobject]@{
                id           = $Id
                port         = Get-DefaultPhpPort $Id $used
                upstream     = Get-UpstreamName $Id
                version      = $Version
                threadSafety = $ThreadSafety
                autostart    = $false
            }
            $s.php.versions = @(@($s.php.versions) + $script:entry)
        }
        $entry = $script:entry
        $settings = Get-ManagerSettings
        Write-Out ("Registered PHP {0}: port {1}, upstream {2}" -f $Id, $entry.port, $entry.upstream) 'ok'

        # -- 8. nginx ------------------------------------------------------------------------------
        $mgr = @($settings.php.versions) | Where-Object { $_.id -eq $settings.php.managerVersion } | Select-Object -First 1
        if ($mgr) {
            $w = Invoke-Native -FilePath (Join-Path $phpRoot ($mgr.id + '\php.exe')) -Arguments @((Join-Path $base 'manager\bin\manager.php'), 'config:write') -WorkingDirectory $base
            if ($w.ExitCode -ne 0) { throw "config:write failed: $($w.Output)" }
        }
        $t = Invoke-Nginx @('-t')
        if ($t.ExitCode -ne 0) { throw "nginx config test failed after adding the upstream:`n$($t.Output)" }
        if ((Get-NginxProcess).Count -gt 0) { [void](Invoke-Nginx @('-s', 'reload')); Write-Out 'nginx reloaded with the new upstream' 'ok' }

        # -- 9. Start ------------------------------------------------------------------------------
        $state = Start-PhpCgi $entry
        Write-Out ("PHP {0} on port {1}: {2}" -f $Id, $entry.port, $state) $(if ($state -in 'running', 'already-running') { 'ok' } else { 'warn' })
    }
    else {
        $oldVersion = [string]$existing.version
        $wasRunning = Test-PortListening ([int]$existing.port)
        $isManager  = ($Id -eq $settings.php.managerVersion)

        # -- 5. Carry over configuration and extra extensions --------------------------------------
        if (Test-Path -LiteralPath (Join-Path $target 'php.ini')) {
            Copy-Item -LiteralPath (Join-Path $target 'php.ini') -Destination (Join-Path $staging 'php.ini') -Force
            Write-Out 'Kept your php.ini'
        }
        foreach ($dll in Get-MissingExtensionDlls (Join-Path $target 'ext') (Join-Path $staging 'ext')) {
            Copy-Item -LiteralPath (Join-Path $target "ext\$dll") -Destination (Join-Path $staging "ext\$dll")
            Write-Out "Kept extension $dll"
        }
        $check = Invoke-Native -FilePath (Join-Path $staging 'php.exe') -Arguments @('-c', (Join-Path $staging 'php.ini'), '-v')
        if ($check.Output -match 'Warning|Unable to load') { Write-Out ("php.ini warnings with the new build:`n" + $check.Output) 'warn' }

        # -- 6. Swap folders --------------------------------------------------------------------------
        # From here PHP is down; the catch block below starts it again whatever happens.
        if ($isManager) { Write-Out 'This PHP runs the manager - the page will reconnect in a few seconds.' 'warn' }
        $stopState = Stop-PhpCgi $existing
        $stoppedByUs = $true
        Write-Out "Stopped PHP $Id ($stopState)"
        if (-not (Test-FolderMovable $target)) {
            throw "php\$Id is in use by another program (an IDE, a terminal running php.exe, an antivirus scan). Close it and update again."
        }
        $backupDir = Join-Path $base 'backups\php'
        New-Item -ItemType Directory -Force $backupDir | Out-Null
        $backup = Join-Path $backupDir ("{0}-{1}-{2}" -f $Id, $oldVersion, (Get-Date -Format 'yyyyMMdd-HHmmss'))
        Switch-PhpFolder -Target $target -Staging $staging -Backup $backup
        Write-Out ("Old version kept in backups\php\{0}" -f (Split-Path -Leaf $backup))

        # -- 7. Start / roll back -----------------------------------------------------------------------
        if ($wasRunning -or $isManager) {
            $state = Start-PhpCgi $existing
            if ($state -notin 'running', 'already-running') {
                Write-Out "New PHP $Version did not start ($state) - restoring $oldVersion" 'error'
                [void](Stop-PhpCgi $existing)
                Move-Item -LiteralPath $target -Destination ($target + '.failed-' + $Version)
                Move-Item -LiteralPath $backup -Destination $target
                [void](Start-PhpCgi $existing)
                $stoppedByUs = $false
                throw "Update rolled back. The failed build is in php\$Id.failed-$Version for inspection."
            }
            Write-Out "PHP $Id is running $Version" 'ok'
        }
        $stoppedByUs = $false

        # -- 8. Save (re-read under lock: the user may have changed settings during the download) --
        Update-ManagerSettings {
            param($s)
            foreach ($v in @($s.php.versions)) {
                if ($v.id -eq $Id) {
                    $v.version = $Version
                    if ($v.PSObject.Properties['threadSafety']) { $v.threadSafety = $ThreadSafety }
                    else { $v | Add-Member -NotePropertyName threadSafety -NotePropertyValue $ThreadSafety }
                }
            }
        }
    }

    if ($JobId) { Set-JobState $JobId 'done' "PHP $Version $(if ($Mode -eq 'install') { 'installed' } else { 'updated' })" }
    exit 0
}
catch {
    Write-Out $_.Exception.Message 'error'
    # Never leave a PHP version down because of a failed update (final review #3).
    if ($stoppedByUs -and $existing -and ($wasRunning -or $isManager)) {
        $again = Start-PhpCgi $existing
        Write-Out "Started PHP $Id again ($again)" $(if ($again -in 'running', 'already-running') { 'ok' } else { 'error' })
    }
    if (Test-Path -LiteralPath $staging) { Remove-Item -LiteralPath $staging -Recurse -Force -ErrorAction SilentlyContinue }
    if ($JobId) { Set-JobState $JobId 'failed' $_.Exception.Message }
    exit 1
}
finally {
    if (Test-Path -LiteralPath $zip) { Remove-Item -LiteralPath $zip -Force -ErrorAction SilentlyContinue }
}
