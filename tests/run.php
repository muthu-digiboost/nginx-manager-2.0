<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : run.php
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
 * File: tests/run.php
 * Purpose: Runs every tests/**\/*Test.php and reports PASS/FAIL per test.
 * Usage (from the install folder):
 *     php\8.3\php.exe tests\run.php            all tests
 *     php\8.3\php.exe tests\run.php Router     only test files whose path contains "Router"
 * Exit code: 0 when everything passed, 1 otherwise.
 * Notes: each test runs in the same process; tests must clean up global state they
 *        change (Env::fromArray, Paths::overrideBase) - see tests/bootstrap.php.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$filter = $argv[1] ?? '';
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    if (str_ends_with($path, 'Test.php') && ($filter === '' || str_contains($path, $filter))) {
        $files[] = $path;
    }
}
sort($files);

foreach ($files as $path) {
    $GLOBALS['__currentTestFile'] = substr($path, strlen(str_replace('\\', '/', __DIR__)) + 1);
    require $path;
}

putenv('NM_QUIET=1');   // macOS jobs run without a job id in tests: no console progress lines
$passed = 0;
$failed = 0;
foreach ($GLOBALS['__tests'] as $t) {
    try {
        \NginxManager\Support\Env::fromArray([]);
        \NginxManager\Support\Paths::overrideBase(null);
        // Tests describe Windows behaviour unless they switch to 'Darwin' themselves, so the
        // suite gives the same result on a Mac (the macOS manager PHP switch runs it).
        \NginxManager\Support\Platform::override('Windows');
        ($t['fn'])();
        $passed++;
        echo "PASS  {$t['file']} :: {$t['name']}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL  {$t['file']} :: {$t['name']}\n";
        $where = $e instanceof AssertionFailed ? '' : ' (' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        foreach (explode("\n", $e->getMessage() . $where) as $line) {
            echo "      $line\n";
        }
    }
}

echo "\n" . ($failed ? 'FAILED' : 'OK') . ": $passed passed, $failed failed\n";
exit($failed ? 1 : 0);
