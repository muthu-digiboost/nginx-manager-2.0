#******************************************************************************
#*
#*  Project : nginx-manager-2.0
#*  File    : install-autostart.ps1
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
    Registers (or removes) a scheduled task that starts Nginx Manager at logon, elevated.

.DESCRIPTION
    The task "NginxManager2" runs start-all.ps1 -NoPause with the highest privileges when
    you log on, so the servers come up as Administrator WITHOUT a UAC prompt every time.

.PARAMETER Remove
    Unregister the task instead.

.EXAMPLE
    .\scripts\install-autostart.ps1
    .\scripts\install-autostart.ps1 -Remove

.NOTES
    Requires Admin : yes (self-elevates; creating a "run with highest privileges" task needs it)
    Called by      : you, once
    Encoding       : ASCII only.
#>
[CmdletBinding()]
param([switch]$Remove)

$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'lib\Common.psm1') -Force

$passArgs = @()
if ($Remove) { $passArgs += '-Remove' }
Invoke-SelfElevate -ScriptPath $PSCommandPath -Arguments $passArgs

$taskName = 'NginxManager2'
try {
    if ($Remove) {
        if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) {
            Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
            Write-Step "Scheduled task '$taskName' removed" 'ok'
        } else {
            Write-Step "Scheduled task '$taskName' does not exist"
        }
    } else {
        $startScript = Join-Path (Get-ManagerBase) 'start-all.ps1'
        $action    = New-ScheduledTaskAction -Execute 'powershell.exe' `
                        -Argument ('-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $startScript + '" -NoPause')
        $trigger   = New-ScheduledTaskTrigger -AtLogOn -User "$env:USERDOMAIN\$env:USERNAME"
        $principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive -RunLevel Highest
        $settings  = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Minutes 5)
        Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal `
            -Settings $settings -Description 'Starts Nginx Manager 2.0 (nginx + PHP) at logon, elevated.' -Force | Out-Null
        Write-Step "Scheduled task '$taskName' registered - servers will start at logon" 'ok'
    }
}
catch {
    Write-Step $_.Exception.Message 'error'
    exit 1
}
