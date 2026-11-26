<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : JsonStoreTest.php
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
 * File: tests/Support/JsonStoreTest.php
 * Purpose: JSON state files - BOM tolerance (PowerShell 5.1 may write one), round trips,
 *          defaults for missing files and refusal to silently overwrite corrupt files.
 */

declare(strict_types=1);

use NginxManager\Support\JsonStore;

test('json store strips BOM and round-trips', function () {
    $p = tempCopy("\xEF\xBB\xBF{\"a\":1}", 'store.json');
    assertSame(['a' => 1], JsonStore::read($p));
    JsonStore::write($p, ['b' => [1, 2]]);
    assertSame(['b' => [1, 2]], JsonStore::read($p));
    assertTrue(substr((string) file_get_contents($p), 0, 1) === '{', 'written without BOM');
});

test('json store returns default for a missing file', function () {
    assertSame(['x' => true], JsonStore::read(tempDir() . '/missing.json', ['x' => true]));
});

test('json store throws on corrupt json instead of returning empty data', function () {
    $p = tempCopy('{not json', 'bad.json');
    assertThrows(RuntimeException::class, fn() => JsonStore::read($p), 'bad.json');
});

test('json store creates parent folders', function () {
    $p = tempDir() . '/a/b/c.json';
    JsonStore::write($p, ['ok' => 1]);
    assertSame(['ok' => 1], JsonStore::read($p));
});

test('json store update applies the mutator to the current file under the lock', function () {
    // Regression (final review #4): read-modify-write must re-read inside the lock, or a
    // PowerShell job's save between read and write is lost.
    $p = tempDir() . '/s.json';
    JsonStore::write($p, ['a' => 1, 'b' => 1]);
    JsonStore::update($p, function (array &$d) use ($p) {
        $d['a'] = 2;
    });
    assertSame(['a' => 2, 'b' => 1], JsonStore::read($p));
    assertSame([], glob(dirname($p) . '/*.tmp'));
});
