<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : PhpCgi.php
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
 * File: manager/src/Mac/PhpCgi.php
 * Purpose: Start / stop one PHP version's php-cgi on macOS (Start-PhpCgi / Stop-PhpCgi on Windows).
 * Responsibilities:
 *   - start(entry): php-cgi -b 127.0.0.1:<port> -c <php.ini>, detached (nohup), with
 *     PHP_FCGI_CHILDREN=4 (handles parallel requests) and PHP_FCGI_MAX_REQUESTS=0;
 *     waits up to 5 s for the port
 *   - stop(entry): kills the php-cgi listening on the port (lsof) - never another program
 *   - states: running | already-running | foreign | failed | stopped | not-running | still-listening
 * Used by: Mac\PhpServiceJob, Mac\Server, Mac\ManagerSwitchJob, Mac\PhpInstallJob
 * Notes: the port probe, the spawner and the sleep are injectable for tests.
 */

declare(strict_types=1);

namespace NginxManager\Mac;

use NginxManager\Php\JobRunner;
use NginxManager\Php\PhpPaths;
use NginxManager\Support\Paths;
use NginxManager\Support\Platform;
use NginxManager\Support\Shell;

final class PhpCgi
{
    /** @var callable(int): bool */
    private $isListening;
    /** @var callable(list<string>): void */
    private $spawn;
    /** @var callable(): void */
    private $sleep;

    public function __construct(private readonly Shell $shell, ?callable $isListening = null, ?callable $spawn = null, ?callable $sleep = null)
    {
        $this->isListening = $isListening ?? fn(int $port): bool => in_array($port,
            Platform::parseListeningPorts($this->shell->run(Platform::netstatCommand(), null, 10)->output), true);
        $this->spawn = $spawn ?? [self::class, 'spawnDetached'];
        $this->sleep = $sleep ?? static fn() => usleep(200_000);
    }

    /** @param array<string, mixed> $entry settings.json php.versions entry */
    public function start(array $entry): string
    {
        $port = (int) $entry['port'];
        if (($this->isListening)($port)) {
            $cmd = $this->ownerCommand($port);
            if ($cmd === null) {
                return 'stale';   // listening, but no process owns it: nothing would answer
            }
            return str_contains($cmd, 'php-cgi') ? 'already-running' : 'foreign';
        }
        $paths = PhpPaths::forEntry($entry);
        $argv = [$paths->cgi, '-b', "127.0.0.1:$port"];
        if ($paths->ini !== '') {
            array_push($argv, '-c', $paths->ini);
        }
        ($this->spawn)($argv);
        for ($i = 0; $i < 25; $i++) {
            if (($this->isListening)($port)) {
                return 'running';
            }
            ($this->sleep)();
        }
        return 'failed';
    }

    /** @param array<string, mixed> $entry */
    public function stop(array $entry): string
    {
        $port = (int) $entry['port'];
        $pids = $this->pids($port);
        if (!$pids) {
            return 'not-running';
        }
        $cmd = $this->command($pids[0]);
        if ($cmd === null || !str_contains($cmd, 'php-cgi')) {
            return 'foreign';
        }
        $this->shell->run(array_merge(['kill', '-TERM'], $pids), null, 10);
        for ($i = 0; $i < 25; $i++) {
            if (!($this->isListening)($port)) {
                return 'stopped';
            }
            ($this->sleep)();
        }
        return 'still-listening';
    }

    /** @return list<string> pids listening on 127.0.0.1:<port> */
    private function pids(int $port): array
    {
        $out = $this->shell->run(['lsof', '-t', '-nP', "-iTCP:$port", '-sTCP:LISTEN'], null, 10)->output;
        return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $out) ?: []), 'ctype_digit'));
    }

    private function command(string $pid): ?string
    {
        $out = trim($this->shell->run(['ps', '-o', 'comm=', '-p', $pid], null, 10)->output);
        return $out === '' ? null : $out;
    }

    private function ownerCommand(int $port): ?string
    {
        $pids = $this->pids($port);
        return $pids ? $this->command($pids[0]) : null;
    }

    /** @param list<string> $argv */
    public static function spawnDetached(array $argv): void
    {
        $env = array_merge(getenv(), ['PHP_FCGI_CHILDREN' => '4', 'PHP_FCGI_MAX_REQUESTS' => '0']);
        $log = Paths::in('logs', 'php-cgi.log');   // why a start failed (port in use, bad php.ini) ends up here
        if (!is_dir(dirname($log))) {
            mkdir(dirname($log), 0777, true);
        }
        $proc = proc_open(JobRunner::macLauncherCommand($argv, $log),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes, Paths::base(), $env);
        if (is_resource($proc)) {
            proc_close($proc);
        }
    }
}
