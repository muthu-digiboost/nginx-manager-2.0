<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : ExtensionInstallJobTest.php
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
 * File: tests/Mac/ExtensionInstallJobTest.php
 * Purpose: macOS extensions from the shivammathur/extensions tap: finding a formula for a
 *          PHP branch, installing it as a job, and restarting PHP when it was running.
 */

declare(strict_types=1);

use NginxManager\App;
use NginxManager\Mac\Brew;
use NginxManager\Mac\ExtensionInstallJob;
use NginxManager\Support\JsonStore;

test('find: the tap formula <ext>@<branch> and its version', function () {
    macBase();
    $json = '{"formulae":[{"name":"redis@8.4","full_name":"shivammathur/extensions/redis@8.4","versions":{"stable":"6.1.0"}}],"casks":[]}';
    $sh = (new ScriptedShell())
        ->on(['/opt/homebrew/bin/brew', 'info', '--json=v2', 'shivammathur/extensions/redis@8.4'], 0, $json)
        ->on(['/opt/homebrew/bin/brew', 'info', '--json=v2', 'shivammathur/extensions/nope@8.4'], 1, 'Error: No available formula');
    $brew = new Brew($sh, '/opt/homebrew');
    assertSame(['ext' => 'redis', 'version' => '6.1.0', 'formula' => 'shivammathur/extensions/redis@8.4'], $brew->findExtension('redis', '8.4'));
    assertSame(null, $brew->findExtension('nope', '8.4'));
    assertThrows(InvalidArgumentException::class, fn() => $brew->findExtension('x; rm', '8.4'));
});

test('install job: tap + install, PHP restarted when it was running', function () {
    $base = macBase();
    JsonStore::write("$base/data/settings.json", ['php' => ['managerVersion' => '8.4', 'versions' => [
        ['id' => '8.4', 'port' => 9084, 'upstream' => 'php84', 'version' => '8.4.1', 'prefix' => '/opt/homebrew/opt/php@8.4'],
    ]]]);
    $sh = (new ScriptedShell())
        ->on(['/opt/homebrew/bin/brew', 'tap', 'shivammathur/extensions'], 0)
        ->on(['/opt/homebrew/bin/brew', 'install', 'shivammathur/extensions/redis@8.4'], 0, '==> Pouring redis@8.4');
    $cgi = new class {
        public array $log = [];
        public function start(array $e): string { $this->log[] = 'start'; return 'running'; }
        public function stop(array $e): string { $this->log[] = 'stop'; return 'stopped'; }
    };
    $job = new ExtensionInstallJob(new App(), '', ['Id' => '8.4', 'Ext' => 'redis', 'Enable' => '1'], new Brew($sh, '/opt/homebrew'), $cgi);
    assertContains('redis', $job->run());
    assertTrue($sh->ran(['/opt/homebrew/bin/brew', 'install', 'shivammathur/extensions/redis@8.4']));
    assertSame(['stop', 'start'], $cgi->log);
});

test('install job: a failing brew install fails the job', function () {
    $base = macBase();
    JsonStore::write("$base/data/settings.json", ['php' => ['managerVersion' => '8.4', 'versions' => [
        ['id' => '8.4', 'port' => 9084, 'upstream' => 'php84', 'version' => '8.4.1', 'prefix' => '/opt/homebrew/opt/php@8.4'],
    ]]]);
    $sh = (new ScriptedShell())
        ->on(['/opt/homebrew/bin/brew', 'tap'], 0)
        ->on(['/opt/homebrew/bin/brew', 'install'], 1, 'Error: build failed');
    $job = new ExtensionInstallJob(new App(), '', ['Id' => '8.4', 'Ext' => 'redis', 'Enable' => '1'], new Brew($sh, '/opt/homebrew'), new stdClass());
    assertThrows(RuntimeException::class, fn() => $job->run());
});
