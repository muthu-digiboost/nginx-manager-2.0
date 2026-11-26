#******************************************************************************
#*
#*  Project : nginx-manager-2.0
#*  File    : Install.Tests.ps1
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
    Pester 3.4 tests for the PHP install/update helpers in scripts/lib/Common.psm1.

.DESCRIPTION
    Pure text/file helpers used by scripts/install-php.ps1:
      - Enable-PhpIniDefaults     : starter php.ini (extension_dir + common extensions),
                                    for both old (5.x "php_curl.dll") and new ("curl") ini styles
      - Get-MissingExtensionDlls  : extension DLLs to carry over on update (oci8, xdebug, ...)
      - Get-VcRuntimeHint         : which Visual C++ runtime a build needs
    Download, extraction and process handling are verified live (see the plan).

.NOTES
    Run: powershell -NoProfile -ExecutionPolicy Bypass -File tests\ps\run.ps1
#>

Import-Module "$PSScriptRoot\..\..\scripts\lib\Common.psm1" -Force

Describe 'Enable-PhpIniDefaults' {
    It 'uncomments the Windows extension_dir and new-style extensions' {
        $ini = "; extension_dir = `"./`"`n; On windows:`n;extension_dir = `"ext`"`n;extension=curl`n;extension=gd`n;extension=ldap`n"
        $out = Enable-PhpIniDefaults $ini @('curl', 'gd')
        $out | Should Match '(?m)^extension_dir = "ext"$'
        $out | Should Match '(?m)^extension=curl$'
        $out | Should Match '(?m)^extension=gd$'
        $out | Should Match '(?m)^;extension=ldap$'
        $out | Should Match '(?m)^; extension_dir = "\./"$'
    }
    It 'handles PHP 5.x style php_<name>.dll lines' {
        $ini = ";extension=php_curl.dll`n;extension=php_mbstring.dll`n;extension=php_xsl.dll`n"
        $out = Enable-PhpIniDefaults $ini @('curl', 'mbstring')
        $out | Should Match '(?m)^extension=php_curl\.dll$'
        $out | Should Match '(?m)^extension=php_mbstring\.dll$'
        $out | Should Match '(?m)^;extension=php_xsl\.dll$'
    }
    It 'switches on only the real list entry, not the example in the documentation text' {
        # php.ini-development mentions ";extension=mysqli" in its docs AND in the list:
        # enabling both makes PHP warn "Module mysqli is already loaded".
        $ini = "; example:`n;extension=mysqli`n; list:`n;extension=curl`n;extension=mysqli`n"
        $out = Enable-PhpIniDefaults $ini @('mysqli', 'curl')
        ([regex]::Matches($out, '(?m)^extension=mysqli$')).Count | Should Be 1
        $out | Should Match "list:`n;?extension=curl`nextension=mysqli`n$"
        $out | Should Match "example:`n;extension=mysqli"
    }
    It 'keeps CRLF line endings' {
        $out = Enable-PhpIniDefaults ";extension=curl`r`nx=1`r`n" @('curl')
        $out | Should Be "extension=curl`r`nx=1`r`n"
    }
}

Describe 'Get-MissingExtensionDlls' {
    It 'lists DLLs present in the old ext folder but not in the new one' {
        $old = Join-Path $TestDrive 'old'; $new = Join-Path $TestDrive 'new'
        New-Item -ItemType Directory -Force $old, $new | Out-Null
        'php_curl.dll', 'php_oci8_19.dll', 'php_xdebug.dll' | ForEach-Object { Set-Content (Join-Path $old $_) 'x' }
        'php_curl.dll' | ForEach-Object { Set-Content (Join-Path $new $_) 'x' }
        @(Get-MissingExtensionDlls $old $new) | Should Be @('php_oci8_19.dll', 'php_xdebug.dll')
    }
}

Describe 'Switch-PhpFolder (final review #3)' {
    function New-Folder([string]$path, [string]$marker) {
        New-Item -ItemType Directory -Force $path | Out-Null
        Set-Content (Join-Path $path 'marker.txt') $marker
    }
    It 'swaps the new build in and keeps the old one as backup' {
        $t = Join-Path $TestDrive 'a\8.4'; $s = "$t.new"; $b = Join-Path $TestDrive 'a\backup-8.4'
        New-Folder $t 'old'; New-Folder $s 'new'
        Switch-PhpFolder -Target $t -Staging $s -Backup $b
        Get-Content (Join-Path $t 'marker.txt') | Should Be 'new'
        Get-Content (Join-Path $b 'marker.txt') | Should Be 'old'
    }
    It 'puts the old build back when the new one cannot be moved in' {
        $t = Join-Path $TestDrive 'b\8.4'; $s = "$t.new"; $b = Join-Path $TestDrive 'b\backup-8.4'
        New-Folder $t 'old'       # no staging folder -> second move fails
        { Switch-PhpFolder -Target $t -Staging $s -Backup $b } | Should Throw
        Get-Content (Join-Path $t 'marker.txt') | Should Be 'old'
        Test-Path $b | Should Be $false
    }
}

Describe 'Test-FolderMovable' {
    It 'is true for a folder nobody holds open' {
        $f = Join-Path $TestDrive 'free'; New-Item -ItemType Directory $f | Out-Null
        Test-FolderMovable $f | Should Be $true
        Test-Path $f | Should Be $true
    }
    It 'is false while a file inside is open' {
        $f = Join-Path $TestDrive 'busy'; New-Item -ItemType Directory $f | Out-Null
        $stream = [IO.File]::Open((Join-Path $f 'x.dll'), 'Create', 'ReadWrite', 'None')
        try { Test-FolderMovable $f | Should Be $false } finally { $stream.Close() }
    }
}

Describe 'Get-VcRuntimeHint' {
    It 'maps old compilers'    { Get-VcRuntimeHint 'php-5.6.40-Win32-VC11-x64.zip' | Should Match '2012' }
    It 'maps modern compilers' { Get-VcRuntimeHint 'php-8.3.37-Win32-vs16-x64.zip' | Should Match '2015-2022' }
    It 'gives the Microsoft download for the current runtime' {
        Get-VcRuntimeHint 'php-8.5.12-Win32-vs17-x64.zip' | Should Match ([regex]::Escape('https://aka.ms/vs/17/release/vc_redist.x64.exe'))
    }
    It 'points older runtimes (2012, 2008) to Microsoft''s list of supported downloads' {
        Get-VcRuntimeHint 'php-5.6.40-Win32-VC11-x64.zip' | Should Match ([regex]::Escape('https://learn.microsoft.com/cpp/windows/latest-supported-vc-redist'))
        Get-VcRuntimeHint 'php-5.3.29-Win32-VC9-x86.zip'  | Should Match '2008.*learn\.microsoft\.com'
    }
}

Describe 'Find-PhpProblems (manager PHP switch)' {
    It 'keeps deprecations, warnings, errors and failed tests, drops clean lint output' {
        $out = @(
            'No syntax errors detected in a.php',
            'PHP Deprecated:  Optional parameter $a declared before required parameter $b in x.php on line 2',
            'Warning: Undefined variable $x in y.php on line 3',
            'PASS  Http/RouterTest.php :: ok',
            'FAIL  Php/JobRunnerTest.php :: launch',
            'PHP Fatal error:  Uncaught Error in z.php',
            'OK: 10 passed, 0 failed'
        ) -join "`n"
        $p = @(Find-PhpProblems $out)
        $p.Count | Should Be 4
        $p[0] | Should Match 'Deprecated'
        $p[2] | Should Match '^FAIL'
    }
    It 'ignores passing tests whose names mention warnings or notices' {
        $out = "PASS  Php/ExtensionsTest.php :: php.ini warnings are reported`nPASS  Http/FlashTest.php :: flash notice is shown`nOK: 2 passed, 0 failed"
        @(Find-PhpProblems $out).Count | Should Be 0
    }
    It 'matches PHP message prefixes, not the words anywhere' {
        @(Find-PhpProblems "Deprecated: x in a.php on line 1").Count | Should Be 1
        @(Find-PhpProblems "PHP Warning:  y in b.php on line 2").Count | Should Be 1
        @(Find-PhpProblems "some text about a deprecated option").Count | Should Be 0
    }
    It 'returns nothing for clean output' {
        @(Find-PhpProblems "No syntax errors detected in a.php`nOK: 3 passed, 0 failed").Count | Should Be 0
    }
}

Describe 'Test-ManagerCompat' {
    $php = Join-Path $PSScriptRoot '..\..\php\8.3\php.exe'
    It 'reports a deprecation in the manager code with a real php.exe' {
        if (-not (Test-Path $php)) { return }   # php\ is machine data (gitignored)
        $code = Join-Path $TestDrive 'code'
        New-Item -ItemType Directory -Force (Join-Path $code 'manager\src') | Out-Null
        Set-Content (Join-Path $code 'manager\src\Old.php') '<?php function f($a = 1, $b) { return $b; }'
        $r = Test-ManagerCompat -PhpExe $php -CodeDir $code
        $r.Ok | Should Be $false
        ($r.Problems -join "`n") | Should Match 'Deprecated'
    }
    It 'passes clean code' {
        if (-not (Test-Path $php)) { return }
        $code = Join-Path $TestDrive 'clean'
        New-Item -ItemType Directory -Force (Join-Path $code 'manager\src') | Out-Null
        Set-Content (Join-Path $code 'manager\src\Ok.php') '<?php function f(int $a): int { return $a; }'
        (Test-ManagerCompat -PhpExe $php -CodeDir $code).Ok | Should Be $true
    }
}
