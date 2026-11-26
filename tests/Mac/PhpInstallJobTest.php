<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : PhpInstallJobTest.php
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
 * File: tests/Mac/PhpInstallJobTest.php
 * Purpose: macOS install / update of a PHP version with Homebrew: brew commands, the new
 *          settings entry, nginx upstreams, php-cgi start, and failure handling.
 */

declare(strict_types=1);

use NginxManager\App;
use NginxManager\Mac\Brew;
use NginxManager\Mac\PhpInstallJob;
use NginxManager\Support\JsonStore;
use NginxManager\Support\Settings;

function installFixture(array $versions = []): array
{
    $base = macBase();
    JsonStore::write("$base/data/settings.json", ['php' => ['managerVersion' => '8.4', 'versions' => $versions ?: [
        ['id' => '8.4', 'port' => 9084, 'upstream' => 'php84', 'version' => '8.4.1', 'formula' => 'php@8.4', 'prefix' => '/opt/homebrew/opt/php@8.4'],
    ]]]);
    $cgi = new class {
        public array $log = [];
        public function start(array $e): string { $this->log[] = "start {$e['id']}"; return 'running'; }
        public function stop(array $e): string { $this->log[] = "stop {$e['id']}"; return 'stopped'; }
    };
    return [$base, $cgi];
}

test('install: tap + brew install, then the version is registered, configured and started', function () {
    [$base, $cgi] = installFixture();
    $sh = (new ScriptedShell())
        ->on(['/opt/homebrew/bin/brew', 'tap', 'shivammathur/php'], 0)
        ->on(['/opt/homebrew/bin/brew', 'install', 'shivammathur/php/php@8.2'], 0, '==> Pouring php@8.2')
        ->on(['/opt/homebrew/opt/php@8.2/bin/php', '-r'], 0, "NMV=8.2.29\nNMI=/opt/homebrew/etc/php/8.2/php.ini\nNME=/opt/homebrew/lib/php/pecl/20220829");
    $reloaded = 0;
    $job = new PhpInstallJob(new App(), '', ['Mode' => 'install', 'Id' => '8.2'], new Brew($sh, '/opt/homebrew'), $cgi,
        function () use (&$reloaded) { $reloaded++; return true; });
    $msg = $job->run();
    assertContains('8.2.29', $msg);
    $e = Settings::load()->phpVersion('8.2');
    assertSame([9082, 'php82', '8.2.29', 'php@8.2', '/opt/homebrew/opt/php@8.2', false],
        [$e['port'], $e['upstream'], $e['version'], $e['formula'], $e['prefix'], $e['autostart']]);
    assertContains('upstream php82', (string) file_get_contents("$base/conf/generated/upstreams.conf"));
    assertSame(['start 8.2'], $cgi->log);
    assertSame(1, $reloaded);
});

test('install: a failing brew install changes nothing and reports brew\'s output', function () {
    [, $cgi] = installFixture();
    $sh = (new ScriptedShell())
        ->on(['/opt/homebrew/bin/brew', 'tap', 'shivammathur/php'], 0)
        ->on(['/opt/homebrew/bin/brew', 'install'], 1, 'Error: No available formula');
    $job = new PhpInstallJob(new App(), '', ['Mode' => 'install', 'Id' => '8.2'], new Brew($sh, '/opt/homebrew'), $cgi, fn() => true);
    assertThrows(RuntimeException::class, fn() => $job->run());
    assertSame(null, Settings::load()->phpVersion('8.2'));
    assertSame([], $cgi->log);
});

test('install: an already registered branch is refused', function () {
    [, $cgi] = installFixture();
    $job = new PhpInstallJob(new App(), '', ['Mode' => 'install', 'Id' => '8.4'], new Brew(new ScriptedShell(), '/opt/homebrew'), $cgi, fn() => true);
    assertThrows(InvalidArgumentException::class, fn() => $job->run());
});

test('update: brew upgrade, new version recorded, PHP restarted', function () {
    [, $cgi] = installFixture();
    $sh = (new ScriptedShell())
        ->on(['/opt/homebrew/bin/brew', 'upgrade', 'php@8.4'], 0)   // bare name: brew keeps the tap it came from
        ->on(['/opt/homebrew/opt/php@8.4/bin/php', '-r'], 0, "NMV=8.4.2\nNMI=/opt/homebrew/etc/php/8.4/php.ini\nNME=/opt/homebrew/lib/php/pecl/20240924");
    $job = new PhpInstallJob(new App(), '', ['Mode' => 'update', 'Id' => '8.4'], new Brew($sh, '/opt/homebrew'), $cgi, fn() => true);
    $job->run();
    assertSame('8.4.2', Settings::load()->phpVersion('8.4')['version']);
    assertSame(['stop 8.4', 'start 8.4'], $cgi->log);
});

test('update of the plain "php" formula upgrades "php"', function () {
    [, $cgi] = installFixture([['id' => '8.5', 'port' => 9085, 'upstream' => 'php85', 'version' => '8.5.0', 'formula' => 'php', 'prefix' => '/opt/homebrew/opt/php']]);
    $sh = (new ScriptedShell())
        ->on(['/opt/homebrew/bin/brew', 'upgrade', 'php'], 0)
        ->on(['/opt/homebrew/opt/php/bin/php', '-r'], 0, "NMV=8.5.1\nNMI=/opt/homebrew/etc/php/8.5/php.ini\nNME=/x");
    (new PhpInstallJob(new App(), '', ['Mode' => 'update', 'Id' => '8.5'], new Brew($sh, '/opt/homebrew'), $cgi, fn() => true))->run();
    assertSame('8.5.1', Settings::load()->phpVersion('8.5')['version']);
});
