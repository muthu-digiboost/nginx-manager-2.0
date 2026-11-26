<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : Server.php
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
 * File: manager/src/Mac/Server.php
 * Purpose: start-all / stop-all / reload-nginx on macOS (the .ps1 scripts on Windows).
 * Responsibilities:
 *   - initSettings(): first run - data/settings.json from the PHP versions Homebrew has;
 *     the manager runs on the newest PHP 8.1+, which always starts
 *   - start(): folders, .env, settings, localhost certificate, nginx config, the PHP
 *     versions with Auto-start (plus the manager's), nginx (started or reloaded)
 *   - stop(): nginx quit, then every php-cgi of the configured versions
 *   - reload(): nginx -t, then reload
 * Used by: bin/manager.php server:* (called by start-all.sh, stop-all.sh, restart-all.sh,
 *          reload-nginx.sh)
 */

declare(strict_types=1);

namespace NginxManager\Mac;

use NginxManager\App;
use NginxManager\Nginx\NginxConfigWriter;
use NginxManager\Php\PhpVersionRepository;
use NginxManager\Support\Env;
use NginxManager\Support\EnvFile;
use NginxManager\Support\JsonStore;
use NginxManager\Support\ManagerRequirements;
use NginxManager\Support\Paths;
use NginxManager\Support\Settings;
use RuntimeException;

final class Server
{
    /** @var callable(string, string): void */
    private $out;

    public function __construct(private readonly App $app, private readonly Brew $brew, private readonly PhpCgi $cgi, ?callable $out = null)
    {
        $this->out = $out ?? static function (string $msg, string $level = 'info'): void {
            echo ['ok' => '[+] ', 'warn' => '[!] ', 'error' => '[x] '][$level] ?? '[*] ', $msg, "\n";
        };
    }

    /** First run on this Mac: settings.json from Homebrew's PHP versions. @return bool created */
    public static function initSettings(Brew $brew): bool
    {
        $file = Paths::in('data', 'settings.json');
        if (is_file($file)) {
            return false;
        }
        $versions = [];
        $used = [];
        $manager = '';
        foreach ($brew->installedPhp() as $branch => $formula) {
            $d = $brew->describe($formula);
            $port = self::defaultPort($branch, $used);
            $used[] = $port;
            $versions[] = [
                'id' => $branch, 'port' => $port, 'upstream' => 'php' . str_replace('.', '', $branch),
                'version' => $d['version'] ?: $branch, 'formula' => $formula,
                'prefix' => $d['prefix'], 'ini' => $d['ini'], 'extDir' => $d['extDir'], 'autostart' => false,
            ];
            if (ManagerRequirements::versionSupported($d['version'] ?: $branch)) {
                $manager = $branch;   // ascending order: ends with the newest supported
            }
        }
        foreach ($versions as &$v) {
            $v['autostart'] = $v['id'] === $manager;
        }
        unset($v);
        JsonStore::write($file, ['php' => ['managerVersion' => $manager, 'versions' => $versions]]);
        return true;
    }

    /** 9000 + major.minor digits (8.4 -> 9084); when taken, the first free port from 9100. */
    public static function defaultPort(string $id, array $used): int
    {
        if (preg_match('/^(\d+)\.(\d+)/', $id, $m)) {
            $port = 9000 + (int) ($m[1] . $m[2]);
            if (!in_array($port, $used, true)) {
                return $port;
            }
        }
        $port = 9100;
        while (in_array($port, $used, true)) {
            $port++;
        }
        return $port;
    }

    public function start(): string
    {
        foreach (['logs', 'temp', 'data/jobs', 'data/cache', 'backups', 'conf/generated', 'conf/manager'] as $dir) {
            if (!is_dir(Paths::in($dir))) {
                mkdir(Paths::in($dir), 0777, true);
            }
        }
        EnvFile::ensure(Paths::in('.env'), Paths::in('.env.example'));
        Env::load(Paths::in('.env'));

        if (self::initSettings($this->brew)) {
            $this->say('Created data/settings.json from the PHP versions installed with Homebrew', 'ok');
        }
        $settings = Settings::load();
        if (!$settings->phpVersions()) {
            $this->say('No PHP found. Install one: brew tap ' . Brew::PHP_TAP . ' && brew install ' . Brew::PHP_TAP . '/php@8.5', 'warn');
        }

        if (!is_file(Paths::in('ssl', 'localhost', 'ssl.crt', 'server.crt'))) {
            $log = $this->app->certificates()->generate('localhost', [], false);
            $this->say(end($log)[0] === 'error' ? 'Could not create the localhost certificate: ' . end($log)[1] : 'Created the localhost certificate',
                end($log)[0] === 'error' ? 'warn' : 'ok');
        }

        NginxConfigWriter::writeAll($settings);
        $this->say('Generated nginx config from settings + .env', 'ok');

        $repo = new PhpVersionRepository($settings);
        foreach ($repo->installed() as $v) {
            $entry = (array) $settings->phpVersion($v['id']);
            if (!$v['autostart']) {
                $this->say(sprintf('PHP %-7s 127.0.0.1:%d  skipped (start it from the PHP Versions page when needed)', $v['id'], $v['port']));
                continue;
            }
            $state = $this->cgi->start($entry);
            $this->say(sprintf('PHP %-7s 127.0.0.1:%d  %s', $v['id'], $v['port'], $state),
                in_array($state, ['running', 'already-running'], true) ? 'ok' : 'warn');
        }

        $nginx = $this->app->nginx();
        $test = $nginx->test();
        if (!$test->ok()) {
            throw new RuntimeException("nginx config test failed:\n" . $test->output);
        }
        if ($nginx->isRunning()) {
            $r = $nginx->reload();
            $this->say($r->ok() ? 'nginx reloaded' : 'nginx reload failed: ' . $r->output, $r->ok() ? 'ok' : 'warn');
        } else {
            $r = $nginx->start();
            if (!$r->ok()) {
                throw new RuntimeException("nginx did not start:\n" . $r->output . "\n(Ports 80/443 in use? See logs/error.log)");
            }
            $this->say('nginx started', 'ok');
        }
        return 'http://' . Env::get('MANAGER_HOST', '127.0.0.1') . ':' . Env::int('MANAGER_PORT', 8080);
    }

    public function stop(): void
    {
        $nginx = $this->app->nginx();
        if ($nginx->isRunning()) {
            $stopped = $nginx->stopAndWait();
            $this->say($stopped ? 'nginx stopped' : 'nginx is still running after 10 s - see logs/error.log', $stopped ? 'ok' : 'warn');
        } else {
            $this->say('nginx was not running');
        }
        foreach ($this->app->settings()->phpVersions() as $v) {
            $this->say(sprintf('PHP %-7s %s', $v['id'], $this->cgi->stop($v)));
        }
    }

    public function reload(): void
    {
        $r = $this->app->nginx()->reload();
        if (!$r->ok()) {
            throw new RuntimeException("nginx was not reloaded:\n" . $r->output);
        }
        $this->say('nginx reloaded', 'ok');
    }

    private function say(string $msg, string $level = 'info'): void
    {
        ($this->out)($msg, $level);
    }
}
