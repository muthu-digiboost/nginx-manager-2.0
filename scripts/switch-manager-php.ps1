#******************************************************************************
#*
#*  Project : nginx-manager-2.0
#*  File    : switch-manager-php.ps1
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
    Runs the Nginx Manager UI on another installed PHP version - safely.

.DESCRIPTION
    Launched as a background job by PHP Versions > Manager PHP. Steps:
      1. Target must be PHP 8.1 or newer (mirrors manager/src/Support/ManagerRequirements.php).
      2. Extensions the manager needs (mbstring, openssl) are enabled in the target's php.ini
         when missing (manager.php ext:enable - php.ini is validated and rolled back on error).
      3. Compatibility check with the TARGET php.exe: php -l on every manager file and the test
         suite, with all errors, warnings and deprecations shown. Any problem stops here -
         nothing has been switched yet.
      4. Target php-cgi is started (restarted when extensions were just enabled).
      5. settings.json: managerVersion = target. The previous version keeps running and keeps
         starting with the server (sites may use it).
      6. manager.conf is rewritten, nginx -t, nginx reload.
      7. http://<manager>/health must answer from the target PHP within ~20 s.
    Any failure after step 5 switches everything back automatically.

.PARAMETER Id
    PHP folder id of the target version (e.g. 8.4).

.PARAMETER JobId
    Background-job id (set by the web UI).

.NOTES
    Requires Admin : launched by the already-elevated web UI
    Called by      : manager JobRunner (PhpController::manager)
    Encoding       : ASCII only.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][ValidatePattern('^\d+\.\d+(\.\d+)?$')][string]$Id,
    [string]$JobId
)

$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'lib\Common.psm1') -Force

$MinPhp = '8.1'
$Required = @('mbstring', 'openssl')

function Write-Out([string]$Message, [string]$Level = 'info') {
    Write-Step $Message $Level
    if ($JobId) { Write-JobLog $JobId $Message }
}

function Write-ManagerConfig([string]$PhpExe) {
    $r = Invoke-Native -FilePath $PhpExe -Arguments @((Join-Path $base 'manager\bin\manager.php'), 'config:write') -WorkingDirectory $base
    if ($r.ExitCode -ne 0) { throw "Could not rewrite the nginx config: $($r.Output)" }
}

function Invoke-NginxReload {
    $t = Invoke-Nginx @('-t')
    if ($t.ExitCode -ne 0) { throw "nginx -t failed: $($t.Output)" }
    $r = Invoke-Nginx @('-s', 'reload')
    if ($r.ExitCode -ne 0) { throw "nginx reload failed: $($r.Output)" }
}

function Test-ManagerHealth([string]$ExpectedVersion) {
    $url = 'http://{0}:{1}/health' -f $env:MANAGER_HOST, $env:MANAGER_PORT
    for ($i = 0; $i -lt 20; $i++) {
        Start-Sleep -Seconds 1
        try {
            $resp = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 5
            $json = $resp.Content | ConvertFrom-Json
            if ($json.ok -and $json.php -eq $ExpectedVersion) { return $true }
        } catch { }
    }
    return $false
}

$base = Get-ManagerBase
$switched = $false
$oldId = $null
$oldExe = $null

try {
    if ($JobId) { Set-JobState $JobId 'running' "Run the manager on PHP $Id" }
    [void](Get-ManagerEnv)
    if (-not $env:MANAGER_HOST) { $env:MANAGER_HOST = '127.0.0.1' }
    if (-not $env:MANAGER_PORT) { $env:MANAGER_PORT = '8080' }

    $settings = Get-ManagerSettings
    $oldId = [string]$settings.php.managerVersion
    if ($Id -eq $oldId) { throw "The manager already runs on PHP $Id." }
    $target = Get-PhpVersionById $Id
    $old = Get-PhpVersionById $oldId
    $dir = Join-Path $base "php\$Id"
    $exe = Join-Path $dir 'php.exe'
    $oldExe = Join-Path $base "php\$oldId\php.exe"
    $ini = Join-Path $dir 'php.ini'

    # -- 1. Version ---------------------------------------------------------------------------
    $version = Get-PhpExeVersion $dir
    if (-not $version) { throw "php\$Id\php.exe does not run." }
    if ([version]($version -replace '[^0-9.].*$', '') -lt [version]$MinPhp) {
        throw "The manager needs PHP $MinPhp or newer - PHP $Id is $version."
    }
    Write-Out "Switching the manager from PHP $($old.version) to PHP $version"

    # -- 2. Required extensions ----------------------------------------------------------------
    $iniArgs = @()
    if (Test-Path -LiteralPath $ini) { $iniArgs = @('-c', $ini) }
    $modules = @((Invoke-Native -FilePath $exe -Arguments ($iniArgs + @('-m'))).Output -split "`r?`n" |
        ForEach-Object { $_.Trim().ToLower() })
    $enabledNow = $false
    foreach ($ext in $Required) {
        if ($modules -contains $ext) { continue }
        Write-Out "Enabling $ext in PHP $Id (the manager needs it)..."
        $r = Invoke-Native -FilePath $oldExe -Arguments @((Join-Path $base 'manager\bin\manager.php'), 'ext:enable', $Id, $ext) -WorkingDirectory $base
        if ($r.ExitCode -ne 0) { throw ("Could not enable {0} in PHP {1}: {2}" -f $ext, $Id, $r.Output) }
        $enabledNow = $true
        Write-Out "Enabled $ext" 'ok'
    }

    # -- 3. Compatibility check (nothing switched so far) ---------------------------------------
    Write-Out "Checking the manager's code with PHP $version (syntax, tests, deprecations)..."
    $check = Test-ManagerCompat -PhpExe $exe -CodeDir $base
    if (-not $check.Ok) {
        $shown = @($check.Problems | Select-Object -First 30)
        foreach ($p in $shown) { Write-Out "  $p" 'warn' }
        if ($check.Problems.Count -gt $shown.Count) { Write-Out ("  ... and {0} more" -f ($check.Problems.Count - $shown.Count)) 'warn' }
        throw ("PHP {0} cannot run the manager cleanly yet ({1} problem(s)). Nothing was switched - the manager stays on PHP {2}." -f $Id, $check.Problems.Count, $old.version)
    }
    Write-Out 'Compatible: no errors, warnings or deprecations.' 'ok'

    # -- 4. Start target -----------------------------------------------------------------------
    if ($enabledNow -and (Test-PortListening ([int]$target.port))) { [void](Stop-PhpCgi $target) }
    $state = Start-PhpCgi $target
    if ($state -notin 'running', 'already-running') { throw "PHP $Id did not start ($state)." }
    Write-Out "PHP $Id is running on port $($target.port)" 'ok'

    # -- 5. Switch -------------------------------------------------------------------------------
    Update-ManagerSettings {
        param($s)
        $s.php.managerVersion = $Id
        foreach ($v in @($s.php.versions)) {
            if ($v.id -eq $oldId) {
                # It always started as the manager's PHP; keep it starting (sites may use it).
                if ($v.PSObject.Properties['autostart']) { $v.autostart = $true }
                else { $v | Add-Member -NotePropertyName autostart -NotePropertyValue $true }
            }
        }
    }
    $switched = $true

    # -- 6. nginx --------------------------------------------------------------------------------
    Write-ManagerConfig $exe
    Write-Out 'This page reconnects in a few seconds...' 'warn'
    Invoke-NginxReload
    Write-Out 'nginx reloaded'

    # -- 7. Health -------------------------------------------------------------------------------
    if (-not (Test-ManagerHealth $version)) { throw "The manager did not answer on PHP $version." }
    Write-Out "The manager now runs on PHP $version" 'ok'
    if ($JobId) { Set-JobState $JobId 'done' "The manager now runs on PHP $version" }
    exit 0
}
catch {
    Write-Out $_.Exception.Message 'error'
    if ($switched) {
        Write-Out "Switching back to PHP $oldId..." 'warn'
        try {
            Update-ManagerSettings { param($s) $s.php.managerVersion = $oldId }
            Write-ManagerConfig $oldExe
            Invoke-NginxReload
            Write-Out "Switched back - the manager runs on PHP $oldId again." 'ok'
        } catch {
            Write-Out ("Switching back failed: {0}. Run start-all.ps1." -f $_.Exception.Message) 'error'
        }
    }
    if ($JobId) { Set-JobState $JobId 'failed' $_.Exception.Message }
    exit 1
}
