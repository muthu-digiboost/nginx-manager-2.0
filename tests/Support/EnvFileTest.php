<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : EnvFileTest.php
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
 * File: tests/Support/EnvFileTest.php
 * Purpose: The Settings page edits .env in place: values change, comments and order stay,
 *          new keys are appended, removed keys disappear, unsafe values are refused.
 */

declare(strict_types=1);

use NginxManager\Support\EnvFile;

test('set replaces values, keeps comments, appends and removes keys', function () {
    $path = tempCopy("# Projects\nPROJECTS_DIR=\nPROJECTS_DIR_OLD=Q:/old\n# keep me\nMANAGER_PORT=8080\n", '.env');
    EnvFile::set($path, ['PROJECTS_DIR' => 'Q:/src', 'PROJECTS_DIR_D' => 'R:/git', 'PROJECTS_DIR_OLD' => null]);
    assertSame("# Projects\nPROJECTS_DIR=Q:/src\n# keep me\nMANAGER_PORT=8080\nPROJECTS_DIR_D=R:/git\n", file_get_contents($path));
});

test('values with spaces are quoted and read back unchanged', function () {
    $path = tempCopy("OPENSSL_BIN=\n", '.env');
    EnvFile::set($path, ['OPENSSL_BIN' => 'Q:/Program Files/Git/usr/bin/openssl.exe']);
    assertSame('Q:/Program Files/Git/usr/bin/openssl.exe', \NginxManager\Support\Env::parse((string) file_get_contents($path))['OPENSSL_BIN']);
});

test('unsafe keys and values are refused', function () {
    $path = tempCopy("A=1\n", '.env');
    assertThrows(InvalidArgumentException::class, fn() => EnvFile::set($path, ['bad key' => 'x']));
    assertThrows(InvalidArgumentException::class, fn() => EnvFile::set($path, ['A' => "x\nB=2"]));
    assertThrows(InvalidArgumentException::class, fn() => EnvFile::set($path, ['A' => 'x"y']));
});

test('ensure creates .env from .env.example once', function () {
    $dir = tempDir();
    file_put_contents("$dir/.env.example", "PROJECTS_DIR=\n");
    assertTrue(EnvFile::ensure("$dir/.env", "$dir/.env.example"));
    assertSame("PROJECTS_DIR=\n", file_get_contents("$dir/.env"));
    assertTrue(!EnvFile::ensure("$dir/.env", "$dir/.env.example"));
});
