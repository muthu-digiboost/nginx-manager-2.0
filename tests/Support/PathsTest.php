<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : PathsTest.php
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
 * File: tests/Support/PathsTest.php
 * Purpose: Project roots from .env, absolute <-> "$projects_dir/..." conversion,
 *          and resolving nginx roots for display.
 */

declare(strict_types=1);

use NginxManager\Support\Env;
use NginxManager\Support\Paths;

test('project roots come from PROJECTS_DIR and PROJECTS_DIR_<X>', function () {
    Env::fromArray(['PROJECTS_DIR' => 'Q:\\src\\', 'PROJECTS_DIR_D' => 'R:/git', 'PROJECTS_DIR_E' => '', 'OTHER' => 'x']);
    assertSame(['projects_dir' => 'Q:/src', 'projects_dir_d' => 'R:/git'], Paths::projectRoots());
});

test('absolute path converts to the longest matching root', function () {
    Env::fromArray(['PROJECTS_DIR' => 'Q:/src', 'PROJECTS_DIR_DEEP' => 'Q:/src/deep']);
    assertSame('$projects_dir/a/public', Paths::toProjectRelative('q:\\src\\a\\public'));
    assertSame('$projects_dir_deep/x', Paths::toProjectRelative('Q:/src/deep/x'));
    assertSame(null, Paths::toProjectRelative('Q:/other/a'));
    assertSame(null, Paths::toProjectRelative('Q:/srcx/a'));   // prefix must end at a folder boundary
});

test('resolveRoot expands project variables and prefix-relative roots', function () {
    $base = tempDir();
    Paths::overrideBase($base);
    Env::fromArray(['PROJECTS_DIR' => 'Q:/src', 'PROJECTS_DIR_D' => 'R:/git']);
    assertSame('Q:/src/a', Paths::resolveRoot('$projects_dir/a'));
    assertSame('R:/git/b', Paths::resolveRoot('$projects_dir_d/b'));
    assertSame($base . '/html', Paths::resolveRoot('html'));
    assertSame('Q:/abs', Paths::resolveRoot('Q:/abs'));
});

test('hosts file path comes from SystemRoot', function () {
    $sys = getenv('SystemRoot') ?: 'C:\\Windows';
    assertSame(str_replace('\\', '/', $sys) . '/System32/drivers/etc/hosts', Paths::hostsFile());
});

test('Paths::in joins under the base', function () {
    $base = tempDir();
    Paths::overrideBase($base);
    assertSame($base . '/data/settings.json', Paths::in('data', 'settings.json'));
});
