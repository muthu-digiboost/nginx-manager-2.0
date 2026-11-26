#******************************************************************************
#*
#*  Project : nginx-manager-2.0
#*  File    : run.ps1
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
    Runs all Pester tests in tests/ps and exits non-zero when any fail.

.DESCRIPTION
    Invoke-Pester (v3.4, built into Windows PowerShell 5.1) always exits 0 on its own;
    this wrapper turns the result into a process exit code so CI-style tools and the
    plan ledger can rely on it.

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File tests\ps\run.ps1

.NOTES
    Requires Admin : no
    Called by      : developers, tests/run-all checks
#>
$ErrorActionPreference = 'Stop'
$result = Invoke-Pester -Script $PSScriptRoot -PassThru
Write-Host ("Pester: {0} passed, {1} failed" -f $result.PassedCount, $result.FailedCount)
if ($result.FailedCount -gt 0) { exit 1 }
exit 0
