<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : PlatformTest.php
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
 * File: tests/Support/PlatformTest.php
 * Purpose: Windows / macOS differences behind one API: OS detection (overridable in tests),
 *          listening ports from netstat (both output formats), hosts file, time zone,
 *          absolute folder paths, nginx binary.
 */

declare(strict_types=1);

use NginxManager\Support\Env;
use NginxManager\Support\Paths;
use NginxManager\Support\Platform;
use NginxManager\Support\Timezone;
use NginxManager\Support\Validator;

test('the platform follows PHP_OS_FAMILY and can be overridden for tests', function () {
    Platform::override(null);   // the runner defaults every test to Windows
    assertSame(PHP_OS_FAMILY === 'Darwin', Platform::isMac());
    Platform::override('Darwin');
    assertTrue(Platform::isMac());
    assertTrue(!Platform::isWindows());
    Platform::override('Windows');
    assertTrue(Platform::isWindows());
});

test('listening ports are read from Windows netstat output', function () {
    $out = "  TCP    127.0.0.1:9083         0.0.0.0:0              LISTENING\n"
         . "  TCP    0.0.0.0:80             0.0.0.0:0              LISTENING\n"
         . "  TCP    127.0.0.1:9084         127.0.0.1:50000        ESTABLISHED\n";
    Platform::override('Windows');
    assertSame([80, 9083], Platform::parseListeningPorts($out));
});

test('listening ports are read from macOS netstat output', function () {
    $out = "tcp4       0      0  127.0.0.1.9084         *.*                    LISTEN\n"
         . "tcp46      0      0  *.80                   *.*                    LISTEN\n"
         . "tcp4       0      0  127.0.0.1.9085         127.0.0.1.50000        ESTABLISHED\n";
    Platform::override('Darwin');
    assertSame([80, 9084], Platform::parseListeningPorts($out));
    assertSame(['netstat', '-an', '-p', 'tcp'], Platform::netstatCommand());
});

test('hosts file location per platform', function () {
    Platform::override('Darwin');
    assertSame('/etc/hosts', Paths::hostsFile());
});

test('time zone on macOS comes from the /etc/localtime link', function () {
    Platform::override('Darwin');
    assertSame('Asia/Kolkata', Timezone::fromLocaltimeLink('/var/db/timezone/zoneinfo/Asia/Kolkata'));
    assertSame('Europe/London', Timezone::fromLocaltimeLink('/usr/share/zoneinfo/Europe/London'));
    assertSame('UTC', Timezone::fromLocaltimeLink(''));
    Env::fromArray([]);
    assertSame('America/New_York', Timezone::configured(null, static fn() => '/var/db/timezone/zoneinfo/America/New_York'));
});

test('absolute folder paths: drive paths on Windows, /paths on macOS', function () {
    Platform::override('Windows');
    assertTrue(Validator::absolutePath('Q:/git'));
    assertTrue(!Validator::absolutePath('/Users/me/Sites'));
    Platform::override('Darwin');
    assertTrue(Validator::absolutePath('/Users/me/Sites'));
    assertTrue(!Validator::absolutePath('Sites'));
});

test('nginx binary: nginx.exe in the install folder on Windows, NGINX_BIN or Homebrew on macOS', function () {
    $base = tempDir();
    Paths::overrideBase($base);
    Platform::override('Windows');
    assertSame("$base/nginx.exe", Platform::nginxBinary());
    Platform::override('Darwin');
    Env::fromArray(['NGINX_BIN' => '/opt/custom/nginx']);
    assertSame('/opt/custom/nginx', Platform::nginxBinary());
    Env::fromArray([]);
    assertSame('nginx', Platform::nginxBinary(static fn(string $p) => false));   // falls back to PATH
    assertSame('/opt/homebrew/bin/nginx', Platform::nginxBinary(static fn(string $p) => $p === '/opt/homebrew/bin/nginx'));
});

test('null device: NUL on Windows, /dev/null on macOS (stdin of every command)', function () {
    Platform::override('Windows');
    assertSame('NUL', Platform::nullDevice());
    Platform::override('Darwin');
    assertSame('/dev/null', Platform::nullDevice());
});
