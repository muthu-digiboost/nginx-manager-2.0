#******************************************************************************
#*
#*  Project : nginx-manager-2.0
#*  File    : start-all.ps1
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
    Starts Nginx Manager 2.0: every configured PHP version (php-cgi) and nginx.

.DESCRIPTION
    Replaces v1's start-all.bat. In order:
      1. Elevate      - relaunches itself as Administrator (one UAC prompt). Everything
                        started below inherits the admin token, so the web UI can trust
                        certificates (certutil), edit the hosts file and install PHP.
      2. .env         - loads machine settings; stops with a hint when .env is missing.
      3. Settings     - creates data\settings.json on a fresh machine by scanning php\*.
      4. Folders      - logs, temp, data\jobs, data\cache, conf\generated, conf\manager.
      5. Localhost SSL- generates ssl\localhost (the default certificate) when missing.
      6. nginx config - regenerates conf\generated\*.conf and conf\manager\manager.conf
                        from settings + .env (manager\bin\manager.php config:write).
      7. PHP          - starts php-cgi on 127.0.0.1:<port> for the manager's own PHP and
                        every version marked "Start with server"; others stay stopped
                        until started from the PHP Versions page.
      8. nginx        - tests the config; reloads a running nginx or starts a new one.

.PARAMETER Hidden
    The Administrator copy runs without a PowerShell window (the desktop app passes this).

.PARAMETER NoPause
    Do not wait for a key press at the end (used by the scheduled task).

.PARAMETER NoElevate
    Run in the current session without asking for Administrator (diagnostics and
    automated checks). Services started this way cannot trust certificates, edit the
    hosts file or stop elevated processes.

.PARAMETER Open
    Open the manager UI in the default browser when done.

.EXAMPLE
    .\start-all.ps1 -Open

.NOTES
    Requires Admin : yes (self-elevates)
    Called by      : you, restart-all.ps1, the "NginxManager2" scheduled task
    Encoding       : ASCII only (Windows PowerShell 5.1 reads BOM-less files as ANSI).
#>
[CmdletBinding()]
param(
    [switch]$NoPause,
    [switch]$NoElevate,
    [switch]$Open,
    [switch]$Hidden   # no PowerShell window (the desktop app starts this script)
)

$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'scripts\lib\Common.psm1') -Force

$passArgs = @()
if ($NoPause) { $passArgs += '-NoPause' }
if ($Open)    { $passArgs += '-Open' }
if (-not $NoElevate) { Invoke-SelfElevate -ScriptPath $PSCommandPath -Arguments $passArgs -Hidden:$Hidden }

$exitCode = 0
try {
    $base = Get-ManagerBase
    Set-Location $base
    Write-Host '============================================'
    Write-Host '  Nginx Manager 2.0 - starting'
    Write-Host '============================================'

    # -- 2. .env ---------------------------------------------------------------
    $envValues = Get-ManagerEnv

    # -- 3. Settings -----------------------------------------------------------
    if (Initialize-ManagerSettings) { Write-Step 'Created data\settings.json from the PHP folders found in php\' 'ok' }
    $settings = Get-ManagerSettings
    $versions = @($settings.php.versions)
    if ($versions.Count -eq 0) { Write-Step 'No PHP versions configured yet - install one from the PHP Versions page.' 'warn' }

    # -- 4. Folders ------------------------------------------------------------
    foreach ($d in 'logs', 'temp', 'data\jobs', 'data\cache', 'backups', 'conf\generated', 'conf\manager') {
        New-Item -ItemType Directory -Force -Path (Join-Path $base $d) | Out-Null
    }

    # -- 5. Localhost certificate (nginx.conf's default ssl_certificate) -------
    $lhCrt = Join-Path $base 'ssl\localhost\ssl.crt\server.crt'
    $lhKey = Join-Path $base 'ssl\localhost\ssl.key\server.key'
    if (-not (Test-Path -LiteralPath $lhCrt)) {
        $openssl = Get-OpenSslPath
        $mgrForCert = $versions | Where-Object { $_.id -eq $settings.php.managerVersion } | Select-Object -First 1
        if (-not $openssl -and $mgrForCert) {
            # No openssl.exe (a new PC without Git for Windows): PHP's own OpenSSL creates it.
            $r = Invoke-Native -FilePath (Join-Path $base ('php\' + $mgrForCert.id + '\php.exe')) `
                -Arguments @((Join-Path $base 'manager\bin\manager.php'), 'ssl:localhost') -WorkingDirectory $base
            if ((Test-Path -LiteralPath $lhCrt)) { Write-Step 'Generated ssl\localhost certificate (PHP OpenSSL)' 'ok' }
            else { Write-Step ("Could not generate ssl\localhost certificate - HTTPS will fail:`n" + $r.Output) 'warn' }
        } elseif (-not $openssl) {
            Write-Step 'OpenSSL not found (install Git for Windows or set OPENSSL_BIN) - HTTPS will fail.' 'warn'
        } else {
            New-Item -ItemType Directory -Force (Split-Path $lhCrt), (Split-Path $lhKey) | Out-Null
            [void](Invoke-Native -FilePath $openssl -Arguments @(
                'req', '-x509', '-nodes', '-days', '3650', '-newkey', 'rsa:2048', '-sha256',
                '-subj', '/C=IN/ST=TN/L=Chennai/O=LocalDev/CN=localhost',
                '-addext', 'subjectAltName=DNS:localhost,DNS:*.localhost,IP:127.0.0.1',
                '-keyout', $lhKey, '-out', $lhCrt))
            if (Test-Path -LiteralPath $lhCrt) { Write-Step 'Generated ssl\localhost certificate' 'ok' }
            else { Write-Step 'Could not generate ssl\localhost certificate' 'warn' }
        }
    }

    # -- 6. Generated nginx config ---------------------------------------------
    $cli = Join-Path $base 'manager\bin\manager.php'
    $mgr = $versions | Where-Object { $_.id -eq $settings.php.managerVersion } | Select-Object -First 1
    if ((Test-Path -LiteralPath $cli) -and $mgr) {
        $phpExe = Join-Path $base ('php\' + $mgr.id + '\php.exe')
        $r = Invoke-Native -FilePath $phpExe -Arguments @($cli, 'config:write') -WorkingDirectory $base
        if ($r.ExitCode -ne 0) { throw "config:write failed:`n$($r.Output)" }
        Write-Step 'Generated nginx config from settings + .env' 'ok'
    }

    # -- 7. PHP ------------------------------------------------------------------
    # Only versions marked "Start with server" (plus the manager's own PHP) start here;
    # any other version can be started on demand from the PHP Versions page.
    foreach ($v in $versions) {
        if (-not (Test-PhpAutostart $v $settings.php.managerVersion)) {
            Write-Step ("PHP {0,-7} 127.0.0.1:{1}  skipped (start it from the PHP Versions page when needed)" -f $v.id, $v.port)
            continue
        }
        $state = Start-PhpCgi $v
        $level = switch ($state) { 'running' { 'ok' } 'already-running' { 'ok' } default { 'warn' } }
        $hint = switch ($state) {
            'foreign' { ' (port used by another program - is the old manager still running?)' }
            'missing' { ' (php-cgi.exe not found)' }
            'failed'  { ' (did not start listening - check php.ini / extensions)' }
            default   { '' }
        }
        Write-Step ("PHP {0,-7} 127.0.0.1:{1}  {2}{3}" -f $v.id, $v.port, $state, $hint) $level
    }

    # -- 8. nginx ----------------------------------------------------------------
    $test = Invoke-Nginx @('-t')
    if ($test.ExitCode -ne 0) { throw "nginx config test failed:`n$($test.Output)" }
    if ((Get-NginxProcess).Count -gt 0) {
        $r = Invoke-Nginx @('-s', 'reload')
        if ($r.ExitCode -ne 0) { throw "nginx reload failed:`n$($r.Output)" }
        Write-Step 'nginx reloaded' 'ok'
    } else {
        Start-Process -FilePath (Join-Path $base 'nginx.exe') -ArgumentList @('-p', "`"$base/`"") `
            -WorkingDirectory $base -WindowStyle Hidden | Out-Null
        Start-Sleep -Milliseconds 800
        if ((Get-NginxProcess).Count -gt 0) { Write-Step 'nginx started' 'ok' }
        else { throw 'nginx did not start - see logs\error.log' }
    }

    $hostName = if ($envValues['MANAGER_HOST']) { $envValues['MANAGER_HOST'] } else { '127.0.0.1' }
    $port     = if ($envValues['MANAGER_PORT']) { $envValues['MANAGER_PORT'] } else { '8080' }
    $url = "http://${hostName}:$port"
    Write-Host ''
    Write-Host "Manager UI: $url" -ForegroundColor Green
    if ($Open) { Start-Process $url | Out-Null }
}
catch {
    Write-Step $_.Exception.Message 'error'
    $exitCode = 1
}
finally {
    if (-not $NoPause) {
        Write-Host ''
        Write-Host 'Press any key to close (services keep running)...'
        [void][Console]::ReadKey($true)
    }
}
exit $exitCode
