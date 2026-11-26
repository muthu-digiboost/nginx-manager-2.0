<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : ManagerSwitchJobTest.php
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
 * File: tests/Mac/ManagerSwitchJobTest.php
 * Purpose: Switching the manager's own PHP on macOS - same rules as switch-manager-php.ps1:
 *          the compatibility filter, the happy path, and the automatic switch back.
 */

declare(strict_types=1);

use NginxManager\App;
use NginxManager\Mac\ManagerSwitchJob;
use NginxManager\Php\CompatCheck;
use NginxManager\Support\JsonStore;
use NginxManager\Support\Settings;

test('compat filter: PHP message prefixes and failed tests only (same rules as Find-PhpProblems)', function () {
    $out = implode("\n", [
        'No syntax errors detected in a.php',
        'PHP Deprecated:  Optional parameter $a declared before required parameter $b in x.php on line 2',
        'Warning: Undefined variable $x in y.php on line 3',
        'PASS  Php/ExtensionsTest.php :: php.ini warnings are reported',
        'FAIL  Php/JobRunnerTest.php :: launch',
        'PHP Fatal error:  Uncaught Error in z.php',
        'some text about a deprecated option',
        'OK: 10 passed, 0 failed',
    ]);
    $p = CompatCheck::problems($out);
    assertSame(4, count($p));
    assertContains('Deprecated', $p[0]);
    assertSame('FAIL  Php/JobRunnerTest.php :: launch', $p[2]);
});

function switchFixture(): array
{
    $base = macBase();
    JsonStore::write("$base/data/settings.json", ['php' => ['managerVersion' => '8.4', 'versions' => [
        ['id' => '8.4', 'port' => 9084, 'upstream' => 'php84', 'version' => '8.4.1', 'prefix' => '/b/opt/php@8.4'],
        ['id' => '8.5', 'port' => 9085, 'upstream' => 'php85', 'version' => '8.5.0', 'prefix' => '/b/opt/php@8.5'],
    ]]]);
    $cgi = new class {
        public array $log = [];
        public function start(array $e): string { $this->log[] = "start {$e['id']}"; return 'running'; }
        public function stop(array $e): string { $this->log[] = "stop {$e['id']}"; return 'stopped'; }
    };
    return [$base, $cgi];
}

test('switch: checked, started, switched, nginx reloaded, health confirmed', function () {
    [$base, $cgi] = switchFixture();
    $reloads = 0;
    $job = new ManagerSwitchJob(new App(), '', ['Id' => '8.5'], $cgi,
        compat: fn(string $php) => [],
        modules: fn(string $php) => ['mbstring', 'openssl'],
        reload: function () use (&$reloads) { $reloads++; return true; },
        health: fn() => '8.5.0');
    assertContains('8.5.0', $job->run());
    $s = Settings::load();
    assertSame('8.5', $s->managerVersion());
    assertSame(true, $s->phpVersion('8.4')['autostart']);   // the old one keeps starting
    assertSame(['start 8.5'], $cgi->log);
    assertSame(1, $reloads);
    assertContains('php85', (string) file_get_contents("$base/conf/manager/manager.conf"));
});

test('switch: compatibility problems stop it before anything changes', function () {
    [, $cgi] = switchFixture();
    $job = new ManagerSwitchJob(new App(), '', ['Id' => '8.5'], $cgi,
        compat: fn(string $php) => ['PHP Deprecated: something in a.php on line 1'],
        modules: fn(string $php) => ['mbstring', 'openssl'],
        reload: fn() => true, health: fn() => '8.5.0');
    assertThrows(RuntimeException::class, fn() => $job->run());
    assertSame('8.4', Settings::load()->managerVersion());
    assertSame([], $cgi->log);
});

test('switch: no answer from the new PHP switches everything back', function () {
    [$base, $cgi] = switchFixture();
    $reloads = 0;
    $job = new ManagerSwitchJob(new App(), '', ['Id' => '8.5'], $cgi,
        compat: fn(string $php) => [],
        modules: fn(string $php) => ['mbstring', 'openssl'],
        reload: function () use (&$reloads) { $reloads++; return true; },
        health: fn() => null);
    assertThrows(RuntimeException::class, fn() => $job->run());
    assertSame('8.4', Settings::load()->managerVersion());
    assertSame(2, $reloads);   // switch + switch back
    assertContains('php84', (string) file_get_contents("$base/conf/manager/manager.conf"));
});

test('switch: PHP below 8.1 or the current version is refused', function () {
    [$base, $cgi] = switchFixture();
    JsonStore::update("$base/data/settings.json", function (array &$s) {
        $s['php']['versions'][] = ['id' => '7.4', 'port' => 9074, 'upstream' => 'php74', 'version' => '7.4.33', 'prefix' => '/b/opt/php@7.4'];
    });
    foreach (['7.4', '8.4'] as $id) {
        $job = new ManagerSwitchJob(new App(), '', ['Id' => $id], $cgi, compat: fn($p) => [], modules: fn($p) => [], reload: fn() => true, health: fn() => null);
        assertThrows(InvalidArgumentException::class, fn() => $job->run());
    }
});
