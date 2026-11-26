<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : PhpVersionRepositoryTest.php
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
 * File: tests/Php/PhpVersionRepositoryTest.php
 * Purpose: Installed PHP versions as the UI sees them: branch, running state (port
 *          probe), autostart flag (only the versions you choose start with the server;
 *          the manager's own PHP always does) and validation of bulk selections.
 */

declare(strict_types=1);

use NginxManager\Php\PhpVersionRepository;
use NginxManager\Support\JsonStore;
use NginxManager\Support\Paths;
use NginxManager\Support\Settings;

function phpRepo(array $running = [9083]): PhpVersionRepository
{
    $base = tempDir();
    Paths::overrideBase($base);
    JsonStore::write($base . '/data/settings.json', ['php' => ['managerVersion' => '8.3', 'versions' => [
        ['id' => '7.2', 'port' => 9072, 'upstream' => 'php72', 'version' => '7.2.34', 'threadSafety' => 'ts'],
        ['id' => '8.3', 'port' => 9083, 'upstream' => 'php83', 'version' => '8.3.0', 'threadSafety' => 'ts'],
        ['id' => '8.5.12', 'port' => 9100, 'upstream' => 'php8512', 'version' => '8.5.12', 'threadSafety' => 'ts', 'autostart' => true],
    ]]]);
    return new PhpVersionRepository(Settings::load(), fn(int $port) => in_array($port, $running, true));
}

test('installed versions carry branch, running and manager flags', function () {
    $list = phpRepo()->installed();
    assertSame(['7.2', '8.3', '8.5.12'], array_column($list, 'id'));
    assertSame(['7.2', '8.3', '8.5'], array_column($list, 'branch'));
    assertSame([false, true, false], array_column($list, 'running'));
    assertSame([false, true, false], array_column($list, 'isManager'));
});

test('autostart defaults to off except for the manager version', function () {
    $list = phpRepo()->installed();
    assertSame([false, true, true], array_column($list, 'autostart'));
});

test('setAutostart persists the flag; the manager version cannot be turned off', function () {
    $repo = phpRepo();
    $repo->setAutostart('7.2', true);
    assertSame(true, Settings::load()->phpVersion('7.2')['autostart']);
    assertThrows(InvalidArgumentException::class, fn() => $repo->setAutostart('8.3', false), 'manager');
    assertThrows(InvalidArgumentException::class, fn() => $repo->setAutostart('9.9', true));
});

test('dashboard pin: off by default, persisted per version', function () {
    $repo = phpRepo();
    assertSame([false, false, false], array_column($repo->installed(), 'dashboard'));
    $repo->setDashboard('8.5.12', true);
    $fresh = new PhpVersionRepository(Settings::load(), fn() => false);
    assertSame([false, false, true], array_column($fresh->installed(), 'dashboard'));
    assertThrows(InvalidArgumentException::class, fn() => $repo->setDashboard('9.9', true));
});

test('install target folder: branch first, then the exact version, never a duplicate', function () {
    $repo = phpRepo();
    assertSame('5.6', $repo->installTargetId('5.6', '5.6.40'));
    assertSame('8.3.37', $repo->installTargetId('8.3', '8.3.37'));      // 8.3 folder exists -> side by side
    assertThrows(InvalidArgumentException::class, fn() => $repo->installTargetId('8.5', '8.5.12'), 'already installed');
    assertThrows(InvalidArgumentException::class, fn() => $repo->installTargetId('8.3', '8.3.0'), 'already installed');
    assertThrows(InvalidArgumentException::class, fn() => $repo->installTargetId('8.3', '9.0.0'));
});

test('selection validation keeps only known ids and refuses stopping the manager', function () {
    $repo = phpRepo();
    assertSame(['7.2', '8.5.12'], $repo->validateSelection(['7.2', 'x', '8.5.12', '7.2'], 'start'));
    assertThrows(InvalidArgumentException::class, fn() => $repo->validateSelection(['8.3'], 'stop'), 'manager');
    assertSame(['8.3'], $repo->validateSelection(['8.3'], 'restart'));
    assertThrows(InvalidArgumentException::class, fn() => $repo->validateSelection([], 'start'));
});

test('manager candidates: every installed version, eligible only from PHP 8.1 and not the current one', function () {
    $c = phpRepo()->managerCandidates();
    assertSame(['7.2', '8.3', '8.5.12'], array_column($c, 'id'));
    assertSame([false, false, true], array_column($c, 'eligible'));
    assertContains('8.1 or newer', $c[0]['reason']);
    assertContains('already', $c[1]['reason']);
    assertSame('', $c[2]['reason']);
});

test('switching the manager PHP validates the target', function () {
    $r = phpRepo();
    assertSame('8.5.12', $r->validateManagerTarget('8.5.12'));
    assertThrows(InvalidArgumentException::class, fn() => $r->validateManagerTarget('7.2'));
    assertThrows(InvalidArgumentException::class, fn() => $r->validateManagerTarget('8.3'));
    assertThrows(InvalidArgumentException::class, fn() => $r->validateManagerTarget('9.9'));
});
