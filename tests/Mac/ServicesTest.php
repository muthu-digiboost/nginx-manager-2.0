<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : ServicesTest.php
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
 * File: tests/Mac/ServicesTest.php
 * Purpose: macOS services without a Mac: Homebrew lookups, php-cgi start/stop, first-run
 *          settings from the PHP versions Homebrew has installed, the PHP service job.
 *          Every command is answered by ScriptedShell (prefix match), so the exact
 *          commands are checked too.
 */

declare(strict_types=1);

use NginxManager\Mac\Brew;
use NginxManager\Mac\PhpCgi;
use NginxManager\Mac\Server;
use NginxManager\Support\JsonStore;
use NginxManager\Support\Paths;
use NginxManager\Support\Platform;
use NginxManager\Support\Settings;
use NginxManager\Support\Shell;
use NginxManager\Support\ShellResult;

final class ScriptedShell extends Shell
{
    /** @var list<list<string>> */
    public array $calls = [];
    /** @var list<array{0:list<string>, 1:int, 2:string}> */
    private array $rules = [];

    public function on(array $prefix, int $exit, string $output = ''): self
    {
        $this->rules[] = [$prefix, $exit, $output];
        return $this;
    }

    public function run(array $argv, ?string $cwd = null, int $timeout = 60): ShellResult
    {
        $this->calls[] = $argv;
        foreach (array_reverse($this->rules) as [$prefix, $exit, $out]) {
            if (array_slice($argv, 0, count($prefix)) === $prefix) {
                return new ShellResult($exit, $out);
            }
        }
        return new ShellResult(127, 'not scripted: ' . implode(' ', $argv));
    }

    public function ran(array $prefix): bool
    {
        foreach ($this->calls as $c) {
            if (array_slice($c, 0, count($prefix)) === $prefix) {
                return true;
            }
        }
        return false;
    }
}

function macBase(): string
{
    $base = tempDir();
    Paths::overrideBase($base);
    Platform::override('Darwin');
    return $base;
}

test('brew: installed PHP formulas, versioned and the plain "php" one', function () {
    macBase();
    $sh = (new ScriptedShell())
        ->on(['/opt/homebrew/bin/brew', 'list', '--formula', '-1'], 0, "nginx\nphp\nphp@7.4\nphp@8.2\nopenssl@3\nshivammathur/php/php@5.6\n")
        ->on(['/opt/homebrew/opt/php/bin/php', '-r'], 0, "NMB=8.5\n");
    $brew = new Brew($sh, '/opt/homebrew');
    assertSame(['5.6' => 'php@5.6', '7.4' => 'php@7.4', '8.2' => 'php@8.2', '8.5' => 'php'], $brew->installedPhp());
});

test('brew: a registered entry records prefix, version, php.ini and extension dir', function () {
    macBase();
    $sh = (new ScriptedShell())
        ->on(['/opt/homebrew/opt/php@8.2/bin/php', '-r'], 0, "NMV=8.2.29\nNMI=/opt/homebrew/etc/php/8.2/php.ini\nNME=/opt/homebrew/lib/php/pecl/20220829");
    $e = (new Brew($sh, '/opt/homebrew'))->describe('php@8.2');
    assertSame(['prefix' => '/opt/homebrew/opt/php@8.2', 'version' => '8.2.29',
                'ini' => '/opt/homebrew/etc/php/8.2/php.ini', 'extDir' => '/opt/homebrew/lib/php/pecl/20220829'], $e);
});

test('php-cgi: started detached on 127.0.0.1:<port> with its php.ini; waits for the port', function () {
    macBase();
    $listening = false;
    $sh = new ScriptedShell();
    $spawned = [];
    $cgi = new PhpCgi($sh,
        function (int $p) use (&$listening) { return $listening; },
        function (array $argv) use (&$spawned, &$listening) { $spawned[] = $argv; $listening = true; },
        fn() => null);
    $entry = ['id' => '8.4', 'port' => 9084, 'prefix' => '/opt/homebrew/opt/php@8.4', 'ini' => '/opt/homebrew/etc/php/8.4/php.ini'];
    assertSame('running', $cgi->start($entry));
    assertSame(['/opt/homebrew/opt/php@8.4/bin/php-cgi', '-b', '127.0.0.1:9084', '-c', '/opt/homebrew/etc/php/8.4/php.ini'], $spawned[0]);
    $running = (new ScriptedShell())->on(['lsof'], 0, "123\n")->on(['ps'], 0, "/opt/homebrew/Cellar/php@8.4/8.4.1/bin/php-cgi\n");
    assertSame('already-running', (new PhpCgi($running, fn($p) => true, fn($a) => null, fn() => null))->start($entry));
});

test('php-cgi: stop kills only a php-cgi listening on the port', function () {
    macBase();
    $open = true;
    $sh = (new ScriptedShell())
        ->on(['lsof', '-t', '-nP', '-iTCP:9084', '-sTCP:LISTEN'], 0, "4242\n")
        ->on(['ps', '-o', 'comm=', '-p', '4242'], 0, "/opt/homebrew/Cellar/php@8.4/8.4.1/bin/php-cgi\n")
        ->on(['kill', '-TERM', '4242'], 0);
    $cgi = new PhpCgi($sh, function (int $p) use (&$open, $sh) {
        if ($sh->ran(['kill'])) {
            $open = false;
        }
        return $open;
    }, fn($a) => null, fn() => null);
    assertSame('stopped', $cgi->stop(['id' => '8.4', 'port' => 9084]));
    $foreign = (new ScriptedShell())->on(['lsof'], 0, "77\n")->on(['ps'], 0, "/usr/local/bin/node\n");
    assertSame('foreign', (new PhpCgi($foreign, fn($p) => true, fn($a) => null, fn() => null))->stop(['id' => '8.4', 'port' => 9084]));
    assertTrue(!$foreign->ran(['kill']));
});

test('php-cgi: a listening port without a process is reported as stale, not running', function () {
    macBase();
    $sh = (new ScriptedShell())->on(['lsof'], 1, '');
    $cgi = new PhpCgi($sh, fn($p) => true, fn($a) => null, fn() => null);
    assertSame('stale', $cgi->start(['id' => '8.4', 'port' => 9084, 'prefix' => '/opt/homebrew/opt/php@8.4']));
});

test('first run: settings.json from Homebrew PHP, the manager runs on the newest 8.1+', function () {
    macBase();
    $sh = (new ScriptedShell())
        ->on(['/opt/homebrew/bin/brew', 'list', '--formula', '-1'], 0, "php@7.4\nphp@8.4\n")
        ->on(['/opt/homebrew/opt/php@7.4/bin/php', '-r'], 0, "NMV=7.4.33\nNMI=/opt/homebrew/etc/php/7.4/php.ini\nNME=/opt/homebrew/lib/php/pecl/20190902")
        ->on(['/opt/homebrew/opt/php@8.4/bin/php', '-r'], 0, "NMV=8.4.1\nNMI=/opt/homebrew/etc/php/8.4/php.ini\nNME=/opt/homebrew/lib/php/pecl/20240924");
    assertTrue(Server::initSettings(new Brew($sh, '/opt/homebrew')));
    $s = Settings::load();
    assertSame('8.4', $s->managerVersion());
    assertSame(['7.4', '8.4'], array_column($s->phpVersions(), 'id'));
    $v84 = $s->phpVersion('8.4');
    assertSame([9084, 'php84', true, '/opt/homebrew/opt/php@8.4'], [$v84['port'], $v84['upstream'], $v84['autostart'], $v84['prefix']]);
    assertSame(false, $s->phpVersion('7.4')['autostart']);
    assertTrue(!Server::initSettings(new Brew($sh, '/opt/homebrew')));   // only once
});

test('job: restart several PHP versions; one failing version fails the job but the others still run', function () {
    $base = macBase();
    JsonStore::write("$base/data/settings.json", ['php' => ['managerVersion' => '8.4', 'versions' => [
        ['id' => '8.2', 'port' => 9082, 'upstream' => 'php82', 'prefix' => '/b/opt/php@8.2'],
        ['id' => '8.4', 'port' => 9084, 'upstream' => 'php84', 'prefix' => '/b/opt/php@8.4'],
    ]]]);
    $cgi = new class {   // PhpCgi double: records calls; PHP 8.2 fails to start
        public array $log = [];
        public function start(array $e): string { $this->log[] = "start {$e['id']}"; return $e['id'] === '8.2' ? 'failed' : 'running'; }
        public function stop(array $e): string { $this->log[] = "stop {$e['id']}"; return 'stopped'; }
    };
    $job = new NginxManager\Mac\PhpServiceJob(new NginxManager\App(), '', ['Action' => 'restart', 'Id' => '8.2,8.4'], $cgi);
    try {
        $job->run();
        throw new AssertionFailed('expected the job to fail');
    } catch (RuntimeException $e) {
        assertContains('8.2', $e->getMessage());
    }
    assertSame(['stop 8.2', 'start 8.2', 'stop 8.4', 'start 8.4'], $cgi->log);
});

test('brew: PHP startup warnings do not end up in the recorded version or paths', function () {
    macBase();
    $out = "PHP Warning:  Module \"redis\" is already loaded in Unknown on line 0\n"
         . "NMV=8.2.29\nNMI=/opt/homebrew/etc/php/8.2/php.ini\nNME=/opt/homebrew/lib/php/pecl/20220829\n";
    $sh = (new ScriptedShell())->on(['/opt/homebrew/opt/php@8.2/bin/php', '-r'], 0, $out);
    $e = (new Brew($sh, '/opt/homebrew'))->describe('php@8.2');
    assertSame(['8.2.29', '/opt/homebrew/etc/php/8.2/php.ini', '/opt/homebrew/lib/php/pecl/20220829'], [$e['version'], $e['ini'], $e['extDir']]);
});

test('nginx on macOS: running means OUR pid file + a live process, not any nginx', function () {
    // Final review 3, I5: a Homebrew-service nginx must not count as ours.
    $base = macBase();
    $sh = (new ScriptedShell())->on(['pgrep'], 0, "999\n")->on(['kill', '-0', '4242'], 0);
    $nginx = new NginxManager\Nginx\NginxControl($sh);
    assertTrue(!$nginx->isRunning());                 // no logs/nginx.pid
    assertTrue(!$sh->ran(['pgrep']));
    @mkdir("$base/logs", 0777, true);
    file_put_contents("$base/logs/nginx.pid", "4242\n");
    assertTrue($nginx->isRunning());
    file_put_contents("$base/logs/nginx.pid", "5151\n");    // stale pid: kill -0 fails (not scripted)
    assertTrue(!$nginx->isRunning());
});

test('nginx on macOS: stop waits until nginx has really exited', function () {
    // Final review 3, I7: "-s quit" returns at once; restart must not see the dying master.
    $base = macBase();
    @mkdir("$base/logs", 0777, true);
    file_put_contents("$base/logs/nginx.pid", "4242\n");
    $alive = 3;
    $sh = new class($alive) extends Shell {
        public array $calls = [];
        public function __construct(public int $alive) {}
        public function run(array $argv, ?string $cwd = null, int $timeout = 60): NginxManager\Support\ShellResult
        {
            $this->calls[] = $argv;
            if ($argv[0] === 'kill') {
                return new NginxManager\Support\ShellResult($this->alive-- > 0 ? 0 : 1, '');
            }
            return new NginxManager\Support\ShellResult(0, '');   // nginx -s quit
        }
    };
    $nginx = new NginxManager\Nginx\NginxControl($sh);
    assertTrue($nginx->stopAndWait(fn() => null));
    assertTrue(!$nginx->isRunning());
});
