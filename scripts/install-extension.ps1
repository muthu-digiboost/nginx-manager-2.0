#******************************************************************************
#*
#*  Project : nginx-manager-2.0
#*  File    : install-extension.ps1
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
    Downloads a PECL extension build for one PHP version and installs it.

.DESCRIPTION
    Background job started from the PHP Extensions page.
      1. Download    downloads.php.net/~windows/pecl/releases/<ext>/<ver>/<zip> -> temp\
      2. Extract     -> temp\ext-<JobId>\
      3. Copy        php_<ext>.dll (and any other php_*.dll) -> php\<Id>\ext\
                     (an existing DLL of the same name is backed up to backups\ext\<Id>\)
                     other *.dll dependencies -> php\<Id>\  (never overwriting existing ones)
      4. Enable      optional: "manager.php ext:enable" - same php.ini backup / check /
                     rollback as the web page
      5. Restart     the PHP version, if it is running

.PARAMETER JobId    Background-job id (set by the web UI).
.PARAMETER Id       PHP folder id (e.g. 8.3).
.PARAMETER Ext      Extension name (e.g. redis).
.PARAMETER Version  Extension version (e.g. 6.3.0).
.PARAMETER ZipName  Zip file name from the PECL listing.
.PARAMETER Enable   1 = enable in php.ini after copying.

.NOTES
    Requires Admin : yes (writes php\<Id>\, restarts elevated php-cgi); the web UI is elevated
    Called by      : manager JobRunner (PHP Extensions page)
    Encoding       : ASCII only.
#>
[CmdletBinding()]
param(
    [string]$JobId,
    [Parameter(Mandatory)][ValidatePattern('^\d+\.\d+(\.\d+)?$')][string]$Id,
    [Parameter(Mandatory)][ValidatePattern('^[a-z0-9_]{2,40}$')][string]$Ext,
    [Parameter(Mandatory)][ValidatePattern('^\d+\.\d+\.\d+$')][string]$Version,
    [Parameter(Mandatory)][ValidatePattern('^php_[a-z0-9_]+-[0-9A-Za-z.-]+-x64\.zip$')][string]$ZipName,
    [ValidateSet('0', '1')][string]$Enable = '1'
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
Import-Module (Join-Path $PSScriptRoot 'lib\Common.psm1') -Force

function Write-Out([string]$Message, [string]$Level = 'info') {
    Write-Step $Message $Level
    if ($JobId) { Write-JobLog $JobId $Message }
}

$base    = Get-ManagerBase
$phpDir  = Join-Path $base "php\$Id"
$tag     = if ($JobId) { $JobId } else { [guid]::NewGuid().ToString('N').Substring(0, 8) }
$zip     = Join-Path $base "temp\$ZipName"
$work    = Join-Path $base "temp\ext-$tag"

try {
    if ($JobId) { Set-JobState $JobId 'running' "Install $Ext $Version for PHP $Id" }
    [void](Get-ManagerEnv)
    $php = Get-PhpVersionById $Id
    if (-not (Test-Path -LiteralPath (Join-Path $phpDir 'php.exe'))) { throw "PHP $Id is not installed in php\$Id." }

    # -- 1. Download ---------------------------------------------------------------------
    $url = "https://downloads.php.net/~windows/pecl/releases/$Ext/$Version/$ZipName"
    Write-Out "Downloading $url"
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    New-Item -ItemType Directory -Force (Split-Path $zip) | Out-Null
    Invoke-WebRequest -Uri $url -OutFile $zip -UseBasicParsing -UserAgent 'NginxManager/2.0'
    Write-Out ("Downloaded {0:N1} MB (php.net publishes no checksum for PECL builds)" -f ((Get-Item $zip).Length / 1MB))

    # -- 2. Extract ------------------------------------------------------------------------
    if (Test-Path -LiteralPath $work) { Remove-Item -LiteralPath $work -Recurse -Force }
    Expand-Archive -LiteralPath $zip -DestinationPath $work -Force

    # -- 3. Copy ------------------------------------------------------------------------------
    $extDir = Join-Path $phpDir 'ext'
    $main = Get-ChildItem -LiteralPath $work -Recurse -Filter "php_$Ext.dll" | Select-Object -First 1
    if (-not $main) { throw "The zip does not contain php_$Ext.dll." }
    foreach ($dll in Get-ChildItem -LiteralPath $work -Recurse -Filter '*.dll') {
        if ($dll.Name -like 'php_*.dll') {
            $dest = Join-Path $extDir $dll.Name
            if (Test-Path -LiteralPath $dest) {
                $bk = Join-Path $base "backups\ext\$Id"
                New-Item -ItemType Directory -Force $bk | Out-Null
                Copy-Item -LiteralPath $dest -Destination (Join-Path $bk ("{0}-{1}" -f (Get-Date -Format 'yyyyMMdd-HHmmss'), $dll.Name))
                Write-Out "Backed up the existing $($dll.Name)"
            }
            Copy-Item -LiteralPath $dll.FullName -Destination $dest -Force
            Write-Out "Copied $($dll.Name) to php\$Id\ext"
        } else {
            $dest = Join-Path $phpDir $dll.Name
            if (Test-Path -LiteralPath $dest) {
                Write-Out "Kept the existing $($dll.Name) in php\$Id (not overwritten)" 'warn'
            } else {
                Copy-Item -LiteralPath $dll.FullName -Destination $dest
                Write-Out "Copied dependency $($dll.Name) to php\$Id"
            }
        }
    }

    # -- 4. Enable ------------------------------------------------------------------------------
    if ($Enable -eq '1') {
        $mgr = Get-PhpVersionById ((Get-ManagerSettings).php.managerVersion)
        $mgrPhp = Join-Path $base ("php\" + $mgr.id + '\php.exe')
        $r = Invoke-Native -FilePath $mgrPhp -Arguments @((Join-Path $base 'manager\bin\manager.php'), 'ext:enable', $Id, $Ext) -WorkingDirectory $base
        if ($r.ExitCode -ne 0) { throw "PHP could not load $Ext - php.ini was left unchanged:`n$($r.Output)" }
        Write-Out "Enabled $Ext in php\$Id\php.ini" 'ok'
    }

    # -- 5. Restart -------------------------------------------------------------------------------
    if (Test-PortListening ([int]$php.port)) {
        [void](Stop-PhpCgi $php)
        $state = Start-PhpCgi $php
        Write-Out "Restarted PHP $Id ($state)" $(if ($state -in 'running', 'already-running') { 'ok' } else { 'warn' })
    }

    if ($JobId) { Set-JobState $JobId 'done' "$Ext $Version installed for PHP $Id" }
    exit 0
}
catch {
    Write-Out $_.Exception.Message 'error'
    if ($JobId) { Set-JobState $JobId 'failed' $_.Exception.Message }
    exit 1
}
finally {
    foreach ($p in $zip, $work) {
        if (Test-Path -LiteralPath $p) { Remove-Item -LiteralPath $p -Recurse -Force -ErrorAction SilentlyContinue }
    }
}
