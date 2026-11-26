<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : PhpPathsTest.php
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
 * File: tests/Php/PhpPathsTest.php
 * Purpose: Where one PHP version's files are: {base}/php/<id>/ on Windows, the Homebrew
 *          prefix on macOS; extension file naming (php_x.dll / x.so).
 */

declare(strict_types=1);

use NginxManager\Php\PhpIni;
use NginxManager\Php\PhpPaths;
use NginxManager\Support\Paths;
use NginxManager\Support\Platform;

test('windows: everything inside php/<id>', function () {
    $base = tempDir();
    Paths::overrideBase($base);
    Platform::override('Windows');
    $p = PhpPaths::forEntry(['id' => '8.4']);
    assertSame(["$base/php/8.4/php.exe", "$base/php/8.4/php-cgi.exe", "$base/php/8.4/php.ini", "$base/php/8.4/ext"],
        [$p->php, $p->cgi, $p->ini, $p->extDir]);
    assertSame('php_*.dll', PhpPaths::extensionGlob());
});

test('macOS: Homebrew prefix, its php.ini and extension dir', function () {
    Platform::override('Darwin');
    $p = PhpPaths::forEntry(['id' => '8.4', 'prefix' => '/opt/homebrew/opt/php@8.4',
        'extDir' => '/opt/homebrew/lib/php/pecl/20240924']);
    assertSame('/opt/homebrew/opt/php@8.4/bin/php', $p->php);
    assertSame('/opt/homebrew/opt/php@8.4/bin/php-cgi', $p->cgi);
    assertSame('/opt/homebrew/etc/php/8.4/php.ini', $p->ini);          // derived from the Homebrew root
    assertSame('/opt/homebrew/lib/php/pecl/20240924', $p->extDir);
    assertSame('*.so', PhpPaths::extensionGlob());
    $q = PhpPaths::forEntry(['id' => '8.4', 'prefix' => '/usr/local/opt/php@8.4', 'ini' => '/custom/php.ini']);
    assertSame('/custom/php.ini', $q->ini);
});

test('extension names from both file styles', function () {
    assertSame('curl', PhpIni::normalise('php_curl.dll'));
    assertSame('redis', PhpIni::normalise('redis.so'));
    assertSame('xdebug', PhpIni::normalise('/opt/homebrew/lib/php/pecl/20240924/xdebug.so'));
});

test('old PHP on macOS gets extension=<name>.so lines', function () {
    Platform::override('Darwin');
    $ini = new PhpIni("a=1\n", '5.6.40');
    $ini->setEnabled('redis', true, false);
    assertContains("\nextension=redis.so\n", $ini->text());
});
