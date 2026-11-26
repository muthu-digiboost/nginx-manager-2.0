<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : BrewCatalogTest.php
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
 * File: tests/Mac/BrewCatalogTest.php
 * Purpose: macOS PHP catalogue and updates: the branches the shivammathur/php tap offers,
 *          and "brew outdated --json=v2" turned into the same update list the Windows
 *          page shows (cached in data/cache like the php.net feed).
 */

declare(strict_types=1);

use NginxManager\Mac\Brew;
use NginxManager\Mac\BrewCatalog;

const OUTDATED_JSON = <<<'JSON'
{"formulae":[
  {"name":"shivammathur/php/php@8.2","installed_versions":["8.2.28"],"current_version":"8.2.29","pinned":false},
  {"name":"nginx","installed_versions":["1.27.1"],"current_version":"1.27.3","pinned":false},
  {"name":"php","installed_versions":["8.5.0"],"current_version":"8.5.1","pinned":false}
],"casks":[]}
JSON;

test('branches: every tap branch with an end-of-life flag, newest first', function () {
    $b = BrewCatalog::branches();
    assertSame('8.5', $b[0]['branch']);
    assertSame('5.6', end($b)['branch']);
    $byBranch = array_column($b, 'eol', 'branch');
    assertTrue($byBranch['5.6'] && $byBranch['7.4'] && $byBranch['8.0']);
    assertTrue(!$byBranch['8.4'] && !$byBranch['8.5']);
});

test('outdated formulas are read from brew and cached', function () {
    macBase();
    $sh = (new ScriptedShell())->on(['/opt/homebrew/bin/brew', 'outdated', '--json=v2'], 0, OUTDATED_JSON);
    $cat = new BrewCatalog(new Brew($sh, '/opt/homebrew'));
    assertSame(['php@8.2' => '8.2.29', 'nginx' => '1.27.3', 'php' => '8.5.1'], $cat->outdated(true));
    $offline = new BrewCatalog(new Brew(new ScriptedShell(), '/opt/homebrew'));
    assertSame('8.2.29', $offline->outdated()['php@8.2']);   // from the cache, no brew call
    assertTrue($offline->checkedAt() !== null);
});

test('compare gives the same shape as the Windows update checker', function () {
    macBase();
    $sh = (new ScriptedShell())->on(['/opt/homebrew/bin/brew', 'outdated', '--json=v2'], 0, OUTDATED_JSON);
    $cat = new BrewCatalog(new Brew($sh, '/opt/homebrew'));
    $cat->outdated(true);
    $installed = [
        ['id' => '7.4', 'version' => '7.4.33', 'formula' => 'php@7.4', 'isManager' => false, 'threadSafety' => ''],
        ['id' => '8.2', 'version' => '8.2.28', 'formula' => 'php@8.2', 'isManager' => false, 'threadSafety' => ''],
        ['id' => '8.5', 'version' => '8.5.0', 'formula' => 'php', 'isManager' => true, 'threadSafety' => ''],
    ];
    $r = $cat->compare($installed);
    assertSame(['8.2', '8.5'], array_column($r['updates'], 'id'));
    assertSame(['8.2.28', '8.2.29', false], [$r['updates'][0]['version'], $r['updates'][0]['latest'], $r['updates'][0]['isManager']]);
    assertSame(['7.4'], array_column($r['eol'], 'id'));
    assertSame([], $r['pinned']);
});

test('brew JSON is found even after warnings or tapping output (stderr is merged)', function () {
    macBase();
    $noisy = "Warning: You are using macOS 16.\n==> Tapping shivammathur/php\nCloning into ...\n" . OUTDATED_JSON . "\n";
    $sh = (new ScriptedShell())->on(['/opt/homebrew/bin/brew', 'outdated', '--json=v2'], 0, $noisy);
    assertSame('8.2.29', (new BrewCatalog(new Brew($sh, '/opt/homebrew')))->outdated(true)['php@8.2']);
});

test('an update that would move a version to another branch is not offered', function () {
    // Final review 3, I4: the plain "php" formula jumps 8.5 -> 8.6; the "8.5" entry must not become 8.6.
    macBase();
    $json = '{"formulae":[{"name":"php","installed_versions":["8.5.3"],"current_version":"8.6.0"}],"casks":[]}';
    $sh = (new ScriptedShell())->on(['/opt/homebrew/bin/brew', 'outdated', '--json=v2'], 0, $json);
    $cat = new BrewCatalog(new Brew($sh, '/opt/homebrew'));
    $cat->outdated(true);
    $r = $cat->compare([['id' => '8.5', 'version' => '8.5.3', 'formula' => 'php', 'isManager' => true, 'threadSafety' => '']]);
    assertSame([], $r['updates']);
});

test('checking for updates refreshes Homebrew first (brew outdated alone never sees new PHP releases)', function () {
    macBase();
    $sh = (new ScriptedShell())
        ->on(['/opt/homebrew/bin/brew', 'update'], 0)
        ->on(['/opt/homebrew/bin/brew', 'outdated', '--json=v2'], 0, OUTDATED_JSON);
    (new BrewCatalog(new Brew($sh, '/opt/homebrew')))->outdated(true);
    $names = array_map(fn($c) => $c[1], $sh->calls);
    assertSame(['update', 'outdated'], $names);
});
