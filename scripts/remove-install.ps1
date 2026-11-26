#******************************************************************************
#*
#*  Project : nginx-manager-2.0
#*  File    : remove-install.ps1
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
    Removes THIS Nginx Manager folder completely: stops nginx and PHP, then deletes it.

.DESCRIPTION
    Called by the desktop app's uninstaller when "Also delete the Nginx Manager folder" is
    ticked. Runs elevated (one UAC prompt; nginx and php-cgi were started as Administrator):
      1. refuses unless the folder was set up by the app (data\.nm-app-version) - it can
         only ever delete the folder it lives in, never a developer checkout
      2. stops nginx and every php-cgi of this folder (stop-all logic), then force-stops any
         process still running from inside the folder and waits until none is left
      3. deletes the folder with retries (files may be released a moment late)
    Exit code 0 = the folder is gone; 1 = something is left (the uninstaller says so).

.PARAMETER NoElevate
    Do not ask for Administrator (tests only).

.NOTES
    Requires Admin : yes (self-elevates and waits)
    Called by      : desktop/build/installer.nsh (customUnInstall)
    Encoding       : ASCII only.
#>
[CmdletBinding()]
param([switch]$NoElevate)

$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'lib\Common.psm1') -Force

if (-not $NoElevate) { Invoke-SelfElevate -ScriptPath $PSCommandPath -Wait }

$base = Get-ManagerBase
if (-not (Test-Path -LiteralPath (Join-Path $base 'data\.nm-app-version'))) {
    Write-Step "$base was not set up by the Nginx Manager app - not deleted." 'error'
    exit 1
}

# -- 1. stop the servers of this folder ---------------------------------------------
try {
    if ((Get-NginxProcess).Count -gt 0) { [void](Invoke-Nginx @('-s', 'quit')) }
    $settings = Get-ManagerSettings
    foreach ($v in @(if ($settings) { $settings.php.versions })) { [void](Stop-PhpCgi $v) }
} catch {
    Write-Step ("Stopping the servers: " + $_.Exception.Message) 'warn'
}

# -- 2. nothing may run from inside the folder any more --------------------------------
for ($i = 0; $i -lt 20; $i++) {
    $left = @(Get-Process -ErrorAction SilentlyContinue | Where-Object {
        $p = $null; try { $p = $_.Path } catch { }
        $p -and (Test-PathUnderBase $p)
    })
    if ($left.Count -eq 0) { break }
    $left | Stop-Process -Force -ErrorAction SilentlyContinue
    Start-Sleep -Milliseconds 500
}

# -- 3. delete, with retries -----------------------------------------------------------
Set-Location ([IO.Path]::GetTempPath())   # never stand inside the folder being deleted
for ($i = 0; $i -lt 10 -and (Test-Path -LiteralPath $base); $i++) {
    try { Remove-Item -LiteralPath $base -Recurse -Force -ErrorAction Stop } catch { Start-Sleep -Seconds 1 }
}
if (Test-Path -LiteralPath $base) {
    Write-Step "Some files in $base could not be deleted." 'error'
    exit 1
}
Write-Step "$base deleted." 'ok'
exit 0
