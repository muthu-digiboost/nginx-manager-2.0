<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : CompatCheck.php
 *  Date    : 26/11/2026
 *  Author  : Semansys Development Team
 *
 *  Copyright (c) 2026
 *  Tel: +91 97863 37914
 *  Email: dev.digiboost.muthu@gmail.com
 *  All rights reserved, see the LICENSE file for more information.
 *
 ******************************************************************************/

/**
 * File: manager/src/Php/CompatCheck.php
 * Purpose: Can a given php binary run the manager's code cleanly? (Test-ManagerCompat /
 *          Find-PhpProblems in Common.psm1 are the Windows twins - keep the rules equal.)
 * Responsibilities:
 *   - problems(text): lines that are PHP messages (Deprecated, Warning, Notice, Fatal error,
 *     Parse error, Errors parsing - case-sensitive, at the start of a line, optionally "PHP "),
 *     "Uncaught" errors and failed tests; "PASS ..." lines never count
 *   - run(php, codeDir): php -l on every file under manager/ and the test suite, with all
 *     errors, warnings and deprecations shown
 * Used by: Mac\ManagerSwitchJob
 */

declare(strict_types=1);

namespace NginxManager\Php;

use NginxManager\Support\Shell;

final class CompatCheck
{
    /** @return list<string> */
    public static function problems(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $l = trim($line);
            if ($l === '' || str_starts_with($l, 'PASS ')) {
                continue;
            }
            if (preg_match('/^(PHP )?(Deprecated|Warning|Notice|Fatal error|Parse error|Errors parsing)\b/', $l)
                || str_starts_with($l, 'FAIL ') || str_contains($l, 'Uncaught ')) {
                $out[] = $l;
            }
        }
        return $out;
    }

    /** @return list<string> problems; [] = the manager runs cleanly on this PHP */
    public static function run(Shell $shell, string $php, string $codeDir): array
    {
        $flags = ['-d', 'error_reporting=-1', '-d', 'display_errors=1', '-d', 'display_startup_errors=1', '-d', 'log_errors=0'];
        $problems = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($codeDir . '/manager', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $r = $shell->run(array_merge([$php], $flags, ['-l', $f->getPathname()]), $codeDir, 30);
                $found = self::problems($r->output);
                array_push($problems, ...($found ?: ($r->ok() ? [] : ['php -l failed for ' . $f->getFilename()])));
            }
        }
        $runner = $codeDir . '/tests/run.php';
        if (is_file($runner)) {
            $r = $shell->run(array_merge([$php], $flags, [$runner]), $codeDir, 300);
            $found = self::problems($r->output);
            array_push($problems, ...($found ?: ($r->ok() ? [] : ['The test suite failed.'])));
        }
        return $problems;
    }
}
