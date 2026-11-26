#******************************************************************************
#*
#*  Project : nginx-manager-2.0
#*  File    : stop-all.ps1
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
    Stops nginx and every php-cgi of THIS install.

.DESCRIPTION
    Replaces v1's stop-all.bat. Unlike v1 (taskkill /IM php-cgi.exe) it only stops
    processes whose executable lives inside this folder, so other PHP/nginx installs
    on the machine keep running.
      1. nginx: graceful "nginx -s quit", then force-stop leftovers after 3 seconds.
      2. PHP  : stops the php-cgi listening on each configured port.

.PARAMETER Hidden
    The Administrator copy runs without a PowerShell window (the desktop app passes this).

.PARAMETER NoPause
    Do not wait for a key press at the end.

.PARAMETER NoElevate
    Run in the current session without asking for Administrator (diagnostics and
    automated checks). Services started this way cannot trust certificates, edit the
    hosts file or stop elevated processes.

.NOTES
    Requires Admin : yes (self-elevates; elevated processes can only be stopped by admin)
    Called by      : you, restart-all.ps1
    Encoding       : ASCII only.
#>
[CmdletBinding()]
param([switch]$NoPause,
    [switch]$NoElevate,
    [switch]$WaitElevated,   # wait for the elevated run to finish (used by the app's uninstaller)
    [switch]$Hidden)         # no PowerShell window (the desktop app starts this script)

$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'scripts\lib\Common.psm1') -Force

$passArgs = @()
if ($NoPause) { $passArgs += '-NoPause' }
if (-not $NoElevate) { Invoke-SelfElevate -ScriptPath $PSCommandPath -Arguments $passArgs -Wait:$WaitElevated -Hidden:$Hidden }

$exitCode = 0
try {
    Write-Host '============================================'
    Write-Host '  Nginx Manager 2.0 - stopping'
    Write-Host '============================================'

    # -- nginx -------------------------------------------------------------------
    if ((Get-NginxProcess).Count -gt 0) {
        [void](Invoke-Nginx @('-s', 'quit'))
        for ($i = 0; $i -lt 15 -and (Get-NginxProcess).Count -gt 0; $i++) { Start-Sleep -Milliseconds 200 }
        $left = Get-NginxProcess
        if ($left.Count -gt 0) { $left | Stop-Process -Force }
        Write-Step 'nginx stopped' 'ok'
    } else {
        Write-Step 'nginx was not running'
    }

    # -- PHP ---------------------------------------------------------------------
    $settings = Get-ManagerSettings
    foreach ($v in @(if ($settings) { $settings.php.versions })) {
        $state = Stop-PhpCgi $v
        $level = if ($state -eq 'foreign') { 'warn' } else { 'ok' }
        $note  = if ($state -eq 'foreign') { ' (port owned by another program - left running)' } else { '' }
        Write-Step ("PHP {0,-7} :{1}  {2}{3}" -f $v.id, $v.port, $state, $note) $level
    }
}
catch {
    Write-Step $_.Exception.Message 'error'
    $exitCode = 1
}
finally {
    if (-not $NoPause) {
        Write-Host ''
        Write-Host 'Press any key to close...'
        [void][Console]::ReadKey($true)
    }
}
exit $exitCode
