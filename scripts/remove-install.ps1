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
    Deletes an Nginx Manager server folder completely: stops what runs from it, then deletes it.

.DESCRIPTION
    Called by the desktop app's uninstaller (already elevated) when "Also delete the Nginx
    Manager folder" is ticked. The uninstaller runs THIS copy from the app's own protected
    folder (C:\Program Files\Nginx Manager\resources\...), never one from the folder being
    deleted - so nothing in that folder is ever executed with Administrator rights.
      1. refuses unless the folder was set up by the app (data\.nm-app-version) - never a
         developer checkout or any other folder
      2. force-stops every process running from inside the folder (nginx, php-cgi, ...) and
         waits until none is left - no program from the folder is started for this
      3. removes the "NginxManager2" logon task when it starts a script from this folder
      4. deletes the folder with "rmdir /s /q", which removes junctions and symbolic links
         WITHOUT following them (nothing outside the folder is ever deleted), with retries
    Exit code 0 = the folder is gone; 1 = refused or something is left.

.PARAMETER Base
    The folder to delete (the uninstaller passes the folder the app recorded).

.NOTES
    Requires Admin : yes (the uninstaller is elevated; nginx and php-cgi run as Administrator)
    Called by      : desktop/build/installer.nsh (customUnInstall)
    Encoding       : ASCII only.
#>
[CmdletBinding()]
param([Parameter(Mandatory)][string]$Base)

$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'lib\Common.psm1') -Force

$base = [IO.Path]::GetFullPath($Base).TrimEnd('\')
if ($base.Length -le 3 -or -not (Test-Path -LiteralPath (Join-Path $base 'data\.nm-app-version'))) {
    Write-Step "$base was not set up by the Nginx Manager app - not deleted." 'error'
    exit 1
}
$prefix = $base + '\'

# -- 1. nothing may run from inside the folder any more ------------------------------
for ($i = 0; $i -lt 20; $i++) {
    $left = @(Get-Process -ErrorAction SilentlyContinue | Where-Object {
        $p = $null; try { $p = $_.Path } catch { }
        $p -and $p.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)
    })
    if ($left.Count -eq 0) { break }
    $left | Stop-Process -Force -ErrorAction SilentlyContinue
    Start-Sleep -Milliseconds 500
}

# -- 2. the logon task (scripts\install-autostart.ps1) must not point at a deleted folder --
try {
    $task = Get-ScheduledTask -TaskName 'NginxManager2' -ErrorAction SilentlyContinue
    if ($task -and (@($task.Actions | Where-Object { "$($_.Arguments)" -like "*$base*" }).Count -gt 0)) {
        Unregister-ScheduledTask -TaskName 'NginxManager2' -Confirm:$false
        Write-Step 'Removed the "NginxManager2" logon task.' 'ok'
    }
} catch {
    Write-Step ("Logon task: " + $_.Exception.Message) 'warn'
}

# -- 3. delete, with retries (rmdir /s never follows junctions or symbolic links) ------------
Set-Location ([IO.Path]::GetTempPath())   # never stand inside the folder being deleted
for ($i = 0; $i -lt 10 -and (Test-Path -LiteralPath $base); $i++) {
    # errors go to nul INSIDE cmd: PowerShell 5.1 would turn rmdir's "in use" message into
    # a terminating error here and stop the retries
    & "$env:SystemRoot\System32\cmd.exe" /d /c "rmdir /s /q `"$base`" >nul 2>nul"
    if (Test-Path -LiteralPath $base) { Start-Sleep -Seconds 1 }
}
if (Test-Path -LiteralPath $base) {
    Write-Step "Some files in $base could not be deleted." 'error'
    exit 1
}
Write-Step "$base deleted." 'ok'
exit 0
