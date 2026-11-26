#******************************************************************************
#*
#*  Project : nginx-manager-2.0
#*  File    : reload-nginx.ps1
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
    Tests the nginx configuration and, only if it is valid, reloads nginx.

.DESCRIPTION
    Replaces v1's reload-nginx.bat. A reload never happens with a broken config,
    so running sites keep working while you fix the error that is printed.

.PARAMETER Hidden
    The Administrator copy runs without a PowerShell window (the desktop app passes this).

.PARAMETER NoPause
    Do not wait for a key press at the end.

.NOTES
    Requires Admin : yes when nginx was started elevated (self-elevates)
    Called by      : you after editing conf\ by hand (the web UI reloads by itself)
    Exit code      : 0 reloaded, 1 config test failed or nginx not running
    Encoding       : ASCII only.
#>
[CmdletBinding()]
param([switch]$NoPause, [switch]$Hidden)   # -Hidden: no PowerShell window (desktop app)

$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'scripts\lib\Common.psm1') -Force

$passArgs = @()
if ($NoPause) { $passArgs += '-NoPause' }
Invoke-SelfElevate -ScriptPath $PSCommandPath -Arguments $passArgs -Hidden:$Hidden

$exitCode = 0
try {
    $test = Invoke-Nginx @('-t')
    Write-Host $test.Output
    if ($test.ExitCode -ne 0) {
        Write-Step 'Config test FAILED - nginx was not reloaded. Fix the error above.' 'error'
        $exitCode = 1
    } elseif ((Get-NginxProcess).Count -eq 0) {
        Write-Step 'nginx is not running - use start-all.ps1' 'warn'
        $exitCode = 1
    } else {
        $r = Invoke-Nginx @('-s', 'reload')
        if ($r.ExitCode -ne 0) { Write-Step "Reload failed: $($r.Output)" 'error'; $exitCode = 1 }
        else { Write-Step 'Config OK - nginx reloaded' 'ok' }
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
