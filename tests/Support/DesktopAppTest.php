<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : DesktopAppTest.php
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
 * File: tests/Support/DesktopAppTest.php
 * Purpose: The manager knows when it is shown inside the desktop app (the app adds
 *          "NginxManagerApp/<version>" to its user agent) - only then the Settings page
 *          offers the app's "Check for updates".
 */

declare(strict_types=1);

use NginxManager\Support\DesktopApp;

test('the desktop app version is read from its user agent', function () {
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'nginx-manager-desktop/2.0.12 Chrome/140.0.0.0 Electron/45.5.0 Safari/537.36 NginxManagerApp/2.0.12';
    assertSame('2.0.12', DesktopApp::versionFromUserAgent($ua));
});

test('a normal browser is not the desktop app', function () {
    assertSame(null, DesktopApp::versionFromUserAgent('Mozilla/5.0 (Windows NT 10.0) Chrome/140.0.0.0 Safari/537.36'));
    assertSame(null, DesktopApp::versionFromUserAgent(''));
    assertSame(null, DesktopApp::versionFromUserAgent(null));
    assertSame(null, DesktopApp::versionFromUserAgent('NginxManagerApp/<script>'));   // only a plain version
});

test('the app links are fixed', function () {
    assertSame('nginx-manager://check-updates', DesktopApp::CHECK_UPDATES_URL);
    assertSame('nginx-manager://settings', DesktopApp::SETTINGS_URL);
});

function renderSettings(?string $desktopApp): string
{
    return \NginxManager\Support\View::render('settings/index', [
        'title' => 'Settings', 'folders' => [], 'usage' => [], 'extraPath' => [], 'openssl' => '',
        'opensslFound' => null, 'ts' => 'ts', 'timezone' => 'UTC', 'zones' => ['UTC'],
        'system' => ['Install folder' => 'x'], 'desktopApp' => $desktopApp,
    ], null);
}

test('the Settings page offers Check for updates only inside the desktop app', function () {
    $inApp = renderSettings('2.0.12');
    assertTrue(str_contains($inApp, 'href="nginx-manager://check-updates"'));
    assertTrue(str_contains($inApp, 'href="#desktop-app"'));
    assertTrue(str_contains($inApp, '2.0.12'));
    $browser = renderSettings(null);
    assertTrue(!str_contains($browser, 'nginx-manager://'));
    assertTrue(!str_contains($browser, 'desktop-app'));
});

test('the installed version comes from data/.nm-app-version (written by the installer)', function () {
    $base = tempDir();
    \NginxManager\Support\Paths::overrideBase($base);
    assertSame(null, DesktopApp::installedVersion());                       // a repo checkout: no file
    @mkdir($base . '/data', 0777, true);
    file_put_contents($base . '/data/.nm-app-version', "2.0.12\n");
    assertSame('2.0.12', DesktopApp::installedVersion());
    file_put_contents($base . '/data/.nm-app-version', "<b>x</b>\n");      // only a plain version
    assertSame(null, DesktopApp::installedVersion());
});

test('the sidebar badge shows the installed version, "2.0" without one', function () {
    $base = tempDir();
    \NginxManager\Support\Paths::overrideBase($base);
    $html = \NginxManager\Support\View::render('partials/sidebar', ['updateCount' => 0], null);
    assertTrue(str_contains($html, '<span class="brand-version">2.0</span>'));
    @mkdir($base . '/data', 0777, true);
    file_put_contents($base . '/data/.nm-app-version', "2.0.12\n");
    $html = \NginxManager\Support\View::render('partials/sidebar', ['updateCount' => 0], null);
    assertTrue(str_contains($html, '<span class="brand-version">2.0.12</span>'));
});
