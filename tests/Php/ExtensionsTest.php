<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : ExtensionsTest.php
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
 * File: tests/Php/ExtensionsTest.php
 * Purpose: The PHP Extensions page.
 *   - PhpIni           reads/toggles extension lines in php.ini without touching anything else
 *   - ExtensionManager applies toggles safely: php.ini is backed up, checked with php -v and
 *                      restored when PHP reports a load error
 *   - PeclCatalog      finds the newest Windows build of a PECL extension for a PHP branch
 */

declare(strict_types=1);

use NginxManager\Php\ExtensionManager;
use NginxManager\Php\JobRunner;
use NginxManager\Php\PeclCatalog;
use NginxManager\Php\PhpIni;
use NginxManager\Support\JsonStore;
use NginxManager\Support\Paths;
use NginxManager\Support\Platform;
use NginxManager\Support\Settings;
use NginxManager\Support\Shell;
use NginxManager\Support\ShellResult;

// -- PhpIni -------------------------------------------------------------------------------

test('ini detects enabled, commented and zend extensions, incl. trailing comments and php_ prefixes', function () {
    $x = (new PhpIni(fixture('php.ini')))->extensions();
    assertTrue($x['curl']['enabled']);
    assertTrue($x['gd']['enabled']);
    assertTrue(!$x['intl']['enabled']);
    assertTrue($x['exif']['enabled']);
    assertTrue(!$x['oci8_19']['enabled']);
    assertTrue($x['sodium']['enabled']);              // "php_sodium" active, ";sodium" commented
    assertTrue($x['opcache']['enabled'] && $x['opcache']['zend']);
    assertTrue(!$x['xdebug']['enabled'] && $x['xdebug']['zend']);
});

test('enabling a commented extension uncomments it in place', function () {
    $ini = new PhpIni("a=1\n;extension=gd\nb=2\n");
    $ini->setEnabled('gd', true, false);
    assertSame("a=1\nextension=gd\nb=2\n", $ini->text());
});

test('enabling an unknown extension appends it once under a managed section', function () {
    $ini = new PhpIni("a=1\n");
    $ini->setEnabled('redis', true, false);
    $ini->setEnabled('redis', true, false);
    assertSame(1, substr_count($ini->text(), 'extension=redis'));
    assertContains('; ---- Extensions managed by Nginx Manager ----', $ini->text());
});

test('disabling comments every active line of that extension and nothing else', function () {
    $src = fixture('php.ini');
    $ini = new PhpIni($src);
    $ini->setEnabled('sodium', false, false);
    $ini->setEnabled('exif', false, false);
    $expected = str_replace(["\nextension=php_sodium", "\nextension=exif "], ["\n;extension=php_sodium", "\n;extension=exif "], $src);
    assertSame($expected, $ini->text());
});

test('zend extensions are written as zend_extension', function () {
    $ini = new PhpIni("a=1\n");
    $ini->setEnabled('xdebug', true, true);
    assertContains("\nzend_extension=xdebug\n", $ini->text());
});

test('PHP below 7.2 gets extension=php_<name>.dll (short names need 7.2+)', function () {
    // Regression (final review #6): PECL install + enable on PHP 5.6 failed validation.
    $ini = new PhpIni("a=1\n", '5.6.40');
    $ini->setEnabled('redis', true, false);
    $ini->setEnabled('xdebug', true, true);
    assertContains("\nextension=php_redis.dll\n", $ini->text());
    assertContains("\nzend_extension=php_xdebug.dll\n", $ini->text());
    $new = new PhpIni("a=1\n", '7.2.0');
    $new->setEnabled('redis', true, false);
    assertContains("\nextension=redis\n", $new->text());
});

// -- ExtensionManager ------------------------------------------------------------------------

final class FakePhpShell extends Shell
{
    public string $output = 'PHP 8.3.0 (cli)';
    public function run(array $argv, ?string $cwd = null, int $timeout = 60): ShellResult
    {
        return new ShellResult(0, in_array('-m', $argv, true) ? "[PHP Modules]\ncurl\nmbstring\n" : $this->output);
    }
}

final class NoLaunchJobs extends JobRunner
{
    public array $launched = [];
    protected function launch(array $argv): void { $this->launched[] = $argv; }
}

function extFixture(): array
{
    $base = tempDir();
    Paths::overrideBase($base);
    mkdir("$base/php/8.3/ext", 0777, true);
    file_put_contents("$base/php/8.3/php.ini", fixture('php.ini'));
    foreach (['php_curl.dll', 'php_gd.dll', 'php_intl.dll', 'php_imagick.dll', 'php_opcache.dll'] as $dll) {
        file_put_contents("$base/php/8.3/ext/$dll", 'x');
    }
    JsonStore::write("$base/data/settings.json", ['php' => ['managerVersion' => '8.3', 'versions' => [
        ['id' => '8.3', 'port' => 9083, 'upstream' => 'php83', 'version' => '8.3.0'],
    ]]]);
    $shell = new FakePhpShell();
    $jobs = new NoLaunchJobs();
    return [$base, $shell, $jobs, new ExtensionManager(Settings::load(), $shell, $jobs, fn() => true)];
}

test('list merges ext DLLs and php.ini entries with loaded state', function () {
    [, , , $m] = extFixture();
    $list = [];
    foreach ($m->list('8.3') as $e) {
        $list[$e['name']] = $e;
    }
    assertTrue($list['curl']['enabled'] && $list['curl']['loaded'] && $list['curl']['available']);
    assertTrue(!$list['intl']['enabled'] && $list['intl']['available']);
    assertTrue(!$list['imagick']['enabled'] && $list['imagick']['available']);   // DLL only, not in ini
    assertTrue($list['gd']['enabled'] && !$list['gd']['loaded']);                 // broken: enabled, not loaded
    assertTrue(!isset($list['oci8_19']));          // commented sample line without a DLL: hidden
    assertTrue($list['exif']['enabled'] && !$list['exif']['available']);   // ACTIVE line without DLL: shown (it is broken)
});

test('internal test extensions are hidden', function () {
    [$base, , , $m] = extFixture();
    file_put_contents("$base/php/8.3/ext/php_zend_test.dll", 'x');
    file_put_contents("$base/php/8.3/ext/php_dl_test.dll", 'x');
    $names = array_column($m->list('8.3'), 'name');
    assertTrue(!in_array('zend_test', $names, true) && !in_array('dl_test', $names, true));
});

test('setEnabled writes php.ini, keeps a backup and restarts PHP as a job', function () {
    [$base, , $jobs, $m] = extFixture();
    $res = $m->setEnabled('8.3', ['intl' => true, 'curl' => false]);
    assertTrue($res['ok']);
    $ini = (string) file_get_contents("$base/php/8.3/php.ini");
    assertContains("\nextension=intl\n", $ini);
    assertContains("\n;extension=curl\n", $ini);
    assertSame(1, count(glob("$base/backups/php-ini/8.3/*.ini")));
    assertSame(1, count($jobs->launched));
    assertTrue(in_array('restart', $jobs->launched[0], true));
});

test('setEnabled rolls php.ini back when PHP reports a load error', function () {
    [$base, $shell, $jobs, $m] = extFixture();
    $shell->output = "PHP Warning:  PHP Startup: Unable to load dynamic library 'imagick'";
    $res = $m->setEnabled('8.3', ['imagick' => true]);
    assertTrue(!$res['ok']);
    assertContains('Unable to load', $res['output']);
    assertSame(fixture('php.ini'), file_get_contents("$base/php/8.3/php.ini"));
    assertSame(0, count($jobs->launched));
});

test('setEnabled rejects unknown versions and bad names', function () {
    [, , , $m] = extFixture();
    assertThrows(InvalidArgumentException::class, fn() => $m->setEnabled('9.9', ['curl' => true]));
    assertThrows(InvalidArgumentException::class, fn() => $m->setEnabled('8.3', ['../evil' => true]));
});

// -- PeclCatalog -----------------------------------------------------------------------------

function pecl(): PeclCatalog
{
    Paths::overrideBase(tempDir());
    return new PeclCatalog('https://pecl.example/releases/', function (string $url): string {
        return match (true) {
            str_ends_with($url, '/redis/')       => fixture('pecl/redis-index.html'),
            str_ends_with($url, '/redis/6.3.0/') => fixture('pecl/redis-6.3.0.html'),
            str_ends_with($url, '/redis/6.2.0/') => fixture('pecl/redis-6.2.0.html'),
            default                              => '',
        };
    }, 0);
}

test('pecl versions are stable releases, newest first', function () {
    $v = pecl()->versions('redis');
    assertSame('6.3.0', $v[0]);
    assertTrue(!in_array('6.4.0RC1', $v, true));
});

test('pecl finds the newest build for a branch and flavour', function () {
    $b = pecl()->findBuild('redis', '8.3', 'ts');
    assertSame('6.3.0', $b['version']);
    assertSame('php_redis-6.3.0-8.3-ts-vs16-x64.zip', $b['zip']);
    assertSame('https://pecl.example/releases/redis/6.3.0/php_redis-6.3.0-8.3-ts-vs16-x64.zip', $b['url']);
});

test('pecl returns null when no build exists for the branch, and rejects bad names', function () {
    assertSame(null, pecl()->findBuild('redis', '7.2', 'ts'));
    assertThrows(InvalidArgumentException::class, fn() => pecl()->findBuild('../x', '8.3', 'ts'));
});

// -- macOS (Homebrew PHP) ----------------------------------------------------------------------

final class FakeMacPhpShell extends Shell
{
    public array $calls = [];
    public function run(array $argv, ?string $cwd = null, int $timeout = 60): ShellResult
    {
        $this->calls[] = $argv;
        return new ShellResult(0, in_array('-m', $argv, true)
            ? "[PHP Modules]\nCore\ncurl\nmbstring\nopenssl\nredis\n\n[Zend Modules]\nZend OPcache\n" : 'PHP 8.4.1 (cli)');
    }
}

function macExtFixture(): array
{
    $base = tempDir();
    Paths::overrideBase($base);
    Platform::override('Darwin');
    mkdir("$base/brew/opt/php@8.4/bin", 0777, true);
    mkdir("$base/brew/etc/php/8.4", 0777, true);
    mkdir("$base/brew/lib/php/pecl/20240924", 0777, true);
    file_put_contents("$base/brew/etc/php/8.4/php.ini", "memory_limit=128M\n;extension=bz2\nextension=redis.so\n");
    foreach (['redis.so', 'xdebug.so'] as $so) {
        file_put_contents("$base/brew/lib/php/pecl/20240924/$so", 'x');
    }
    JsonStore::write("$base/data/settings.json", ['php' => ['managerVersion' => '8.4', 'versions' => [
        ['id' => '8.4', 'port' => 9084, 'upstream' => 'php84', 'version' => '8.4.1',
         'prefix' => "$base/brew/opt/php@8.4", 'extDir' => "$base/brew/lib/php/pecl/20240924"],
    ]]]);
    $shell = new FakeMacPhpShell();
    return [$base, $shell, new ExtensionManager(Settings::load(), $shell, new NoLaunchJobs(), fn() => false)];
}

test('macOS: .so files are switchable, compiled-in modules are listed as built in', function () {
    [$base, $shell, $m] = macExtFixture();
    $list = [];
    foreach ($m->list('8.4') as $e) {
        $list[$e['name']] = $e;
    }
    assertTrue($list['redis']['enabled'] && $list['redis']['available'] && !$list['redis']['builtin']);
    assertTrue(!$list['xdebug']['enabled'] && $list['xdebug']['available'] && $list['xdebug']['zend']);
    assertTrue($list['curl']['builtin'] && $list['curl']['enabled'] && $list['curl']['loaded']);
    assertTrue(!isset($list['bz2']));                    // commented sample line, no .so: hidden
    assertTrue(!isset($list['core']));                   // PHP itself is not an extension
    assertSame("$base/brew/opt/php@8.4/bin/php", $shell->calls[0][0]);
});

test('macOS: enabling writes the Homebrew php.ini and checks it with that PHP', function () {
    [$base, $shell, $m] = macExtFixture();
    $res = $m->setEnabled('8.4', ['xdebug' => true]);
    assertTrue($res['ok']);
    assertContains("zend_extension=xdebug", (string) file_get_contents("$base/brew/etc/php/8.4/php.ini"));
    $last = end($shell->calls);
    assertSame(["$base/brew/opt/php@8.4/bin/php", '-c', "$base/brew/etc/php/8.4/php.ini", '-v'], $last);
});

test('macOS: extensions switched on by Homebrew conf.d files are shown and switched there', function () {
    [$base, $shell, $m] = macExtFixture();
    $confd = "$base/brew/etc/php/8.4/conf.d";
    mkdir($confd, 0777, true);
    file_put_contents("$confd/ext-xdebug.ini", "[xdebug]\nzend_extension=\"$base/brew/lib/php/pecl/20240924/xdebug.so\"\n");
    $list = array_column($m->list('8.4'), null, 'name');
    assertTrue($list['xdebug']['enabled']);

    $iniBefore = (string) file_get_contents("$base/brew/etc/php/8.4/php.ini");
    $res = $m->setEnabled('8.4', ['xdebug' => false]);
    assertTrue($res['ok']);
    assertContains(';zend_extension=', (string) file_get_contents("$confd/ext-xdebug.ini"));
    assertSame($iniBefore, (string) file_get_contents("$base/brew/etc/php/8.4/php.ini"));   // php.ini untouched
});

test('macOS: an extension whose ini line names an existing .so elsewhere is available, also when switched off', function () {
    // Final review 3, I1: Homebrew opcache / shivammathur extensions live outside extension_dir.
    [$base, , $m] = macExtFixture();
    $confd = "$base/brew/etc/php/8.4/conf.d";
    mkdir($confd, 0777, true);
    mkdir("$base/brew/opt/amqp@8.4", 0777, true);
    file_put_contents("$base/brew/opt/amqp@8.4/amqp.so", 'x');
    file_put_contents("$confd/ext-amqp.ini", "[amqp]\n;extension=\"$base/brew/opt/amqp@8.4/amqp.so\"\n");
    $list = array_column($m->list('8.4'), null, 'name');
    assertTrue(isset($list['amqp']), 'switched-off extension must stay listed');
    assertTrue($list['amqp']['available'] && !$list['amqp']['enabled']);
});
