#******************************************************************************
#*
#*  Project : nginx-manager-2.0
#*  File    : php-service.ps1
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
    Starts, stops or restarts one OR MORE PHP versions (their php-cgi processes).

.DESCRIPTION
    Used from the console and, with -JobId, as a background job launched by the web UI
    (dashboard, PHP Versions and Extensions pages). In job mode every message is also
    appended to data\jobs\<JobId>.log and the final state is written to
    data\jobs\<JobId>.json, which the UI polls. Job mode is detached from the web
    request, so restarting the PHP version the manager itself runs on is safe: the UI
    reconnects when it is back.

    Each version is handled independently - one failing version does not stop the
    others; the job ends "failed" if any of them failed.

.PARAMETER Action
    start | stop | restart

.PARAMETER Id
    One PHP folder id, or several separated by commas (e.g. 8.4  or  7.4,8.1,8.4).

.PARAMETER JobId
    Optional background-job id (set by the web UI).

.EXAMPLE
    .\scripts\php-service.ps1 -Action restart -Id 8.4
    .\scripts\php-service.ps1 -Action start -Id 7.4,8.1

.NOTES
    Requires Admin : yes when the PHP was started elevated (self-elevates in console mode;
                     in job mode it is launched by the already-elevated web UI)
    Called by      : you, manager JobRunner (PHP Versions / Extensions / dashboard), install-php.ps1
    Encoding       : ASCII only.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][ValidateSet('start', 'stop', 'restart')][string]$Action,
    [Parameter(Mandatory)][string]$Id,
    [string]$JobId
)

$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'lib\Common.psm1') -Force

if (-not $JobId) {
    Invoke-SelfElevate -ScriptPath $PSCommandPath -Arguments @('-Action', $Action, '-Id', $Id)
}

function Write-Out([string]$Message, [string]$Level = 'info') {
    Write-Step $Message $Level
    if ($JobId) { Write-JobLog $JobId $Message }
}

$ids = @($Id -split ',' | ForEach-Object { $_.Trim() } | Where-Object { $_ })
$failed = @()

try {
    if ($JobId) { Set-JobState $JobId 'running' ("PHP {0} - {1}" -f ($ids -join ', '), $Action) }
    [void](Get-ManagerEnv)                     # PHP_EXTRA_PATH etc. for php-cgi
}
catch {
    Write-Out $_.Exception.Message 'error'
    if ($JobId) { Set-JobState $JobId 'failed' $_.Exception.Message }
    exit 1
}

foreach ($one in $ids) {
    try {
        $v = Get-PhpVersionById $one
        if ($Action -in 'stop', 'restart') {
            $s = Stop-PhpCgi $v
            if ($s -eq 'foreign') { throw "Port $($v.port) is used by a program outside this install - not stopping it." }
            Write-Out "PHP $one on port $($v.port): $s"
        }
        if ($Action -in 'start', 'restart') {
            $s = Start-PhpCgi $v
            if ($s -notin 'running', 'already-running') { throw "PHP $one did not start ($s)." }
            Write-Out "PHP $one on port $($v.port): $s" 'ok'
        }
    }
    catch {
        $failed += $one
        Write-Out ("PHP {0}: {1}" -f $one, $_.Exception.Message) 'error'
    }
}

if ($failed.Count) {
    if ($JobId) { Set-JobState $JobId 'failed' ("Failed: PHP {0}" -f ($failed -join ', ')) }
    exit 1
}
if ($JobId) { Set-JobState $JobId 'done' ("PHP {0} - {1} done" -f ($ids -join ', '), $Action) }
exit 0
