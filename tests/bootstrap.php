<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : bootstrap.php
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
 * File: tests/bootstrap.php
 * Purpose: A deliberately tiny test harness (no Composer / PHPUnit needed on a
 *          portable Windows install).
 * Responsibilities:
 *   - load the application autoloader + helpers (NOT the web bootstrap: no session,
 *     no .env from disk - each test sets what it needs via Env::fromArray()),
 *   - provide test() and assert*() functions,
 *   - provide fixture helpers: fixture(), tempDir(), tempCopy().
 * Used by: tests/run.php
 * Notes: an assertion failure throws AssertionFailed; run.php catches it per test.
 */

declare(strict_types=1);

require __DIR__ . '/../manager/src/autoload.php';

final class AssertionFailed extends RuntimeException {}

/** @var array<int, array{name:string, fn:callable, file:string}> */
$GLOBALS['__tests'] = [];

/** Registers a test case; run.php executes them in file order. */
function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = ['name' => $name, 'fn' => $fn, 'file' => $GLOBALS['__currentTestFile'] ?? '?'];
}

function export(mixed $v): string
{
    return var_export($v, true);
}

function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($msg ? "$msg\n" : '') . 'Expected ' . export($expected) . "\n     got " . export($actual));
    }
}

function assertTrue(mixed $value, string $msg = ''): void
{
    if ($value !== true) {
        throw new AssertionFailed(($msg ?: 'Expected true') . ', got ' . export($value));
    }
}

function assertContains(string $needle, string $haystack, string $msg = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new AssertionFailed(($msg ? "$msg\n" : '') . 'Missing ' . export($needle) . "\n     in " . export($haystack));
    }
}

function assertNotContains(string $needle, string $haystack, string $msg = ''): void
{
    if (str_contains($haystack, $needle)) {
        throw new AssertionFailed(($msg ? "$msg\n" : '') . 'Unexpected ' . export($needle) . ' in output');
    }
}

/** @param class-string<Throwable> $class */
function assertThrows(string $class, callable $fn, string $msgContains = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!$e instanceof $class) {
            throw new AssertionFailed("Expected $class, got " . get_class($e) . ': ' . $e->getMessage());
        }
        if ($msgContains !== '' && !str_contains($e->getMessage(), $msgContains)) {
            throw new AssertionFailed("Exception message '{$e->getMessage()}' does not contain '$msgContains'");
        }
        return;
    }
    throw new AssertionFailed("Expected $class to be thrown");
}

/** Contents of tests/fixtures/<rel>. */
function fixture(string $rel): string
{
    $path = __DIR__ . '/fixtures/' . $rel;
    if (!is_file($path)) {
        throw new RuntimeException("Missing fixture: $rel");
    }
    return (string) file_get_contents($path);
}

/** A fresh, empty temp directory (forward slashes). */
function tempDir(string $prefix = 'nm2'): string
{
    $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/' . $prefix . '-' . bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    return $dir;
}

/** Writes $content to a new temp file and returns its path. */
function tempCopy(string $content, string $name = 'file.txt'): string
{
    $path = tempDir() . '/' . $name;
    file_put_contents($path, $content);
    return $path;
}
