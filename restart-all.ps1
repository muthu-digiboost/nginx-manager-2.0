#******************************************************************************
#*
#*  Project : nginx-manager-2.0
#*  File    : restart-all.ps1
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
    Restarts everything: stop-all.ps1 followed by start-all.ps1.

.PARAMETER NoPause
    Do not wait for a key press at the end.

.PARAMETER Open
    Open the manager UI in the browser when done.

.NOTES
    Requires Admin : yes (self-elevates once; the child scripts inherit it)
    Called by      : you
    Encoding       : ASCII only.
#>
[CmdletBinding()]
param([switch]$NoPause, [switch]$Open)

$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'scripts\lib\Common.psm1') -Force

$passArgs = @()
if ($NoPause) { $passArgs += '-NoPause' }
if ($Open)    { $passArgs += '-Open' }
Invoke-SelfElevate -ScriptPath $PSCommandPath -Arguments $passArgs

& (Join-Path $PSScriptRoot 'stop-all.ps1') -NoPause
$startArgs = @{ NoPause = [bool]$NoPause; Open = [bool]$Open }
& (Join-Path $PSScriptRoot 'start-all.ps1') @startArgs
exit $LASTEXITCODE
