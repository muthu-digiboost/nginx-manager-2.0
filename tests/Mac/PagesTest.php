<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : PagesTest.php
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
 * File: tests/Mac/PagesTest.php
 * Purpose: Every main page renders on macOS (Platform::override('Darwin')) through the real
 *          router - with Homebrew-style settings and no Mac tools available (on this test
 *          machine every macOS command fails to start, which pages must survive).
 */

declare(strict_types=1);

use NginxManager\App;
use NginxManager\Http\Request;
use NginxManager\Support\JsonStore;
use NginxManager\Support\View;

require_once __DIR__ . '/../../manager/src/routes.php';

test('macOS: every main page renders', function () {
    $base = macBase();
    @mkdir("$base/conf/sites", 0777, true);
    @mkdir("$base/ssl", 0777, true);
    JsonStore::write("$base/data/settings.json", ['php' => ['managerVersion' => '8.4', 'versions' => [
        ['id' => '8.4', 'port' => 9084, 'upstream' => 'php84', 'version' => '8.4.1', 'formula' => 'php@8.4',
         'prefix' => '/opt/homebrew/opt/php@8.4', 'ini' => '/opt/homebrew/etc/php/8.4/php.ini',
         'extDir' => '/opt/homebrew/lib/php/pecl/20240924', 'autostart' => true],
    ]]]);
    $app = new App();
    View::share('statusBar', static fn(): array => ['nginx' => false, 'phpRunning' => 0, 'phpTotal' => 1]);
    View::share('updateCount', static fn(): int => $app->phpUpdateCount());
    $router = build_router($app);
    foreach (['/', '/php', '/extensions', '/settings', '/ssl', '/sites', '/sites/new', '/groups', '/jobs'] as $path) {
        $res = $router->dispatch('GET', $path, Request::create('GET', $path));
        assertSame(200, $res->status, "GET $path");
        assertNotContains('Something went wrong', $res->body, "GET $path");
    }
    $php = $router->dispatch('GET', '/php', Request::create('GET', '/php'))->body;
    assertContains('shivammathur/php', $php);              // Homebrew catalogue, not php.net
    assertNotContains('Thread safe (TS)', $php);
    $settings = $router->dispatch('GET', '/settings', Request::create('GET', '/settings'))->body;
    assertNotContains('Default build for new versions', $settings);
    assertNotContains('Windows', $router->dispatch('GET', '/ssl', Request::create('GET', '/ssl'))->body);
});
