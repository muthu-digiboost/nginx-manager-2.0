#******************************************************************************
#*
#*  Project : nginx-manager-2.0
#*  File    : Common.Tests.ps1
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
    Pester 3.4 tests for the service helpers in scripts/lib/Common.psm1.

.DESCRIPTION
    Covers port/upstream conventions, job state files, settings bootstrap on a fresh
    machine and port probing. Process start/stop itself is verified manually (it needs
    real php-cgi binaries and Administrator rights) - see the plan, Task 2 Step 6.

.NOTES
    Run: powershell -NoProfile -ExecutionPolicy Bypass -File tests\ps\run.ps1
    NM_BASE is a TEST-ONLY override of {base}; scripts never set it.
#>

Import-Module "$PSScriptRoot\..\..\scripts\lib\Common.psm1" -Force

Describe 'Import-DotEnv' {
    It 'parses comments, quotes and empty values' {
        $p = Join-Path $TestDrive '.env'
        Write-Utf8NoBom $p "# c`nNM_T_A=1`nNM_T_B=`"two words`"`nNM_T_C=`n"
        $e = Import-DotEnv $p
        $e.NM_T_A | Should Be '1'
        $e.NM_T_B | Should Be 'two words'
        $e.NM_T_C | Should Be ''
        $env:NM_T_B | Should Be 'two words'
    }
}

Describe 'Write-Utf8NoBom' {
    It 'writes without BOM' {
        $p = Join-Path $TestDrive 'bom.txt'
        Write-Utf8NoBom $p 'x'
        ([IO.File]::ReadAllBytes($p))[0] | Should Be 120
    }
}

Describe 'Get-ProjectRoots' {
    It 'maps PROJECTS_DIR and PROJECTS_DIR_<X> keys to nginx variable names' {
        $r = Get-ProjectRoots @{ PROJECTS_DIR = 'Y:\src\'; PROJECTS_DIR_D = 'Q:/git'; OTHER = 'x'; PROJECTS_DIR_E = '' }
        $r['projects_dir']   | Should Be 'Y:/src'
        $r['projects_dir_d'] | Should Be 'Q:/git'
        $r.Count             | Should Be 2
    }
}

Describe 'ConvertTo-ProjectRelative' {
    $roots = [ordered]@{ projects_dir = 'Y:/src'; projects_dir_d = 'Y:/src/deep' }
    It 'uses the longest matching root'  { ConvertTo-ProjectRelative 'y:\src\deep\a' $roots | Should Be '$projects_dir_d/a' }
    It 'uses the primary root'           { ConvertTo-ProjectRelative 'Y:/src/b/public' $roots | Should Be '$projects_dir/b/public' }
    It 'returns null outside all roots'  { ConvertTo-ProjectRelative 'Z:/x' $roots | Should Be $null }
}

Describe 'Test-HasAbsolutePath' {
    It 'finds drive-letter paths'       { Test-HasAbsolutePath 'root Q:/git/x;'            | Should Be $true }
    It 'finds backslash paths'          { Test-HasAbsolutePath 'Q:\Windows\hosts'          | Should Be $true }
    It 'ignores URLs'                   { Test-HasAbsolutePath 'return 301 https://$host;' | Should Be $false }
    It 'ignores relative and variables' { Test-HasAbsolutePath 'root $projects_dir/a;'     | Should Be $false }
}

Describe 'Get-DefaultPhpPort' {
    It 'uses 90XX for major.minor'               { Get-DefaultPhpPort '8.4' @()           | Should Be 9084 }
    It 'uses major.minor of a patch-level id'    { Get-DefaultPhpPort '8.5.12' @()        | Should Be 9085 }
    It 'falls back to >=9100 on collision'       { Get-DefaultPhpPort '8.4' @(9084)       | Should Be 9100 }
    It 'skips used fallback ports'               { Get-DefaultPhpPort '8.4' @(9084, 9100) | Should Be 9101 }
}

Describe 'Get-UpstreamName' {
    It 'strips dots' { Get-UpstreamName '8.5.12' | Should Be 'php8512' }
}

Describe 'Set-JobState / Write-JobLog' {
    $env:NM_BASE = Join-Path $TestDrive 'base'
    It 'creates and merges job json without BOM' {
        Set-JobState 'pester1' 'running' 'step 1'
        Set-JobState 'pester1' 'done' 'ok'
        $p = Join-Path $env:NM_BASE 'data\jobs\pester1.json'
        $j = Get-Content $p -Raw | ConvertFrom-Json
        $j.state   | Should Be 'done'
        $j.message | Should Be 'ok'
        $j.id      | Should Be 'pester1'
        ([IO.File]::ReadAllBytes($p))[0] | Should Be 123
    }
    It 'appends timestamped lines to the job log' {
        Write-JobLog 'pester1' 'hello'
        Write-JobLog 'pester1' 'world'
        $lines = Get-Content (Join-Path $env:NM_BASE 'data\jobs\pester1.log')
        $lines.Count | Should Be 2
        $lines[1]    | Should Match '^\[\d\d:\d\d:\d\d\] world$'
    }
    It 'rejects job ids that could escape data/jobs' {
        { Set-JobState '..\evil' 'running' 'x' } | Should Throw
    }
    $env:NM_BASE = $null
}

Describe 'Initialize-ManagerSettings' {
    It 'builds settings from php\*\php-cgi.exe folders on a fresh machine' {
        $env:NM_BASE = Join-Path $TestDrive 'fresh'
        foreach ($id in '8.3', '8.4', '8.4.19-backup') {
            New-Item -ItemType Directory -Force (Join-Path $env:NM_BASE "php\$id") | Out-Null
            Set-Content (Join-Path $env:NM_BASE "php\$id\php-cgi.exe") 'x'
        }
        Set-Content (Join-Path $env:NM_BASE 'php\8.3\php8ts.dll') 'x'
        Mock -ModuleName Common Get-PhpExeVersion { param($Dir) if ($Dir -like '*8.3') { '8.3.0' } else { '8.4.27' } }

        Initialize-ManagerSettings | Should Be $true
        $s = Get-ManagerSettings
        @($s.php.versions).Count       | Should Be 2
        $s.php.versions[0].id          | Should Be '8.3'
        $s.php.versions[0].port        | Should Be 9083
        $s.php.versions[0].threadSafety| Should Be 'ts'
        $s.php.versions[1].port        | Should Be 9084
        $s.php.versions[1].version     | Should Be '8.4.27'
        $s.php.versions[1].threadSafety| Should Be 'nts'
        $s.php.managerVersion          | Should Be '8.3'
        $s.php.versions[0].autostart   | Should Be $true    # the manager's own PHP
        $s.php.versions[1].autostart   | Should Be $false   # others start only when chosen
    }
    It 'does nothing when settings already exist' {
        Initialize-ManagerSettings | Should Be $false
        $env:NM_BASE = $null
    }
}

Describe 'Start-PhpCgi / Stop-PhpCgi with a stale port (final review #1)' {
    It 'does not report already-running when the listening process is gone' {
        Mock -ModuleName Common Test-PortListening { $true }
        Mock -ModuleName Common Get-PortOwnerProcess { $null }
        Mock -ModuleName Common Start-Sleep { }
        Start-PhpCgi ([pscustomobject]@{ id = '8.3'; port = 9083 }) | Should Be 'stale'
    }
    It 'reports still-listening when the port does not close after stopping' {
        Mock -ModuleName Common Get-PortOwnerProcess { New-Object psobject -Property @{ Id = 4242; Path = (Join-Path (Get-ManagerBase) 'php\8.3\php-cgi.exe') } }
        Mock -ModuleName Common Stop-Process { }
        Mock -ModuleName Common Test-PortListening { $true }
        Mock -ModuleName Common Start-Sleep { }
        Stop-PhpCgi ([pscustomobject]@{ id = '8.3'; port = 9083 }) | Should Be 'still-listening'
    }
}

Describe 'Test-PhpAutostart' {
    It 'starts the manager version always'      { Test-PhpAutostart ([pscustomobject]@{ id = '8.3' }) '8.3' | Should Be $true }
    It 'starts versions with autostart on'      { Test-PhpAutostart ([pscustomobject]@{ id = '8.4'; autostart = $true }) '8.3' | Should Be $true }
    It 'skips versions with autostart off'      { Test-PhpAutostart ([pscustomobject]@{ id = '8.4'; autostart = $false }) '8.3' | Should Be $false }
    It 'skips versions without the flag'        { Test-PhpAutostart ([pscustomobject]@{ id = '7.2' }) '8.3' | Should Be $false }
}

Describe 'Test-PortListening' {
    It 'sees an open listener and a closed port' {
        $l = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback, 0)
        $l.Start()
        try {
            $port = $l.LocalEndpoint.Port
            Test-PortListening $port | Should Be $true
        } finally { $l.Stop() }
        Test-PortListening $port | Should Be $false
    }
}

Describe 'Update-ManagerSettings (final review #4)' {
    $env:NM_BASE = Join-Path $TestDrive 'lockbase'
    $path = Join-Path $env:NM_BASE 'data\settings.json'
    New-Item -ItemType Directory -Force (Split-Path $path) | Out-Null

    It 're-reads the file so changes made meanwhile are kept' {
        Write-Utf8NoBom $path '{"php":{"versions":[{"id":"8.4","version":"8.4.27","autostart":false}]}}'
        $stale = Get-ManagerSettings
        # the web UI toggles autostart while a long download is running
        Write-Utf8NoBom $path '{"php":{"versions":[{"id":"8.4","version":"8.4.27","autostart":true}]}}'
        Update-ManagerSettings { param($s) $s.php.versions[0].version = '8.4.28' }
        $now = Get-ManagerSettings
        $now.php.versions[0].version   | Should Be '8.4.28'
        $now.php.versions[0].autostart | Should Be $true
        $stale | Should Not BeNullOrEmpty
    }
    It 'leaves no temp files behind' {
        @(Get-ChildItem (Split-Path $path) -Filter '*.tmp').Count | Should Be 0
    }
    It 'waits for the lock PHP holds and gives up after the timeout' {
        $fs = [IO.File]::Open("$path.lock", 'OpenOrCreate', 'ReadWrite', 'ReadWrite')
        $fs.Lock(0, 1)
        try {
            { Update-ManagerSettings { param($s) } -TimeoutMs 300 } | Should Throw
        } finally { $fs.Unlock(0, 1); $fs.Dispose() }
    }
    $env:NM_BASE = $null
}
