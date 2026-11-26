<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : NginxControl.php
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
 * File: manager/src/Nginx/NginxControl.php
 * Purpose: Talks to the nginx binary of this install.
 * Responsibilities:
 *   - test()      nginx -t  (always with -p {base}/ so relative paths resolve correctly)
 *   - reload()    test first; reload ONLY when the test passed, so a bad config never
 *                 takes running sites down
 *   - isRunning() is an nginx.exe process alive?
 * Used by: SiteRepository (validate every write), controllers (reload after changes)
 * Notes: not final - tests subclass it to simulate a failing "nginx -t".
 */

declare(strict_types=1);

namespace NginxManager\Nginx;

use NginxManager\Support\Paths;
use NginxManager\Support\Platform;
use NginxManager\Support\Shell;
use NginxManager\Support\ShellResult;

class NginxControl
{
    public function __construct(private readonly Shell $shell) {}

    public function test(): ShellResult
    {
        return $this->nginx(['-t']);
    }

    public function reload(): ShellResult
    {
        $test = $this->test();
        if (!$test->ok()) {
            return $test;
        }
        if (!$this->isRunning()) {
            return new ShellResult(1, 'nginx is not running.');
        }
        $r = $this->nginx(['-s', 'reload']);
        return new ShellResult($r->exitCode, trim($test->output . "\n" . $r->output));
    }

    /** macOS: nginx daemonizes itself, so this returns once it runs. (Windows: start-all.ps1.) */
    public function start(): ShellResult
    {
        return $this->nginx([]);
    }

    /** Graceful stop (macOS; Windows uses stop-all.ps1). */
    public function stop(): ShellResult
    {
        return $this->nginx(['-s', 'quit']);
    }

    /**
     * macOS: stop, then wait (up to ~10 s) until nginx has really exited - "-s quit" returns
     * at once, and a restart must not mistake the quitting master for a running nginx.
     * @param callable(): void|null $sleep  tests inject a no-op
     */
    public function stopAndWait(?callable $sleep = null): bool
    {
        $sleep ??= static fn() => usleep(200_000);
        $this->stop();
        for ($i = 0; $i < 50; $i++) {
            if (!$this->isRunning()) {
                return true;
            }
            $sleep();
        }
        return false;
    }

    public function isRunning(): bool
    {
        if (!Platform::isWindows()) {
            // OUR nginx only: its pid file (logs/nginx.pid, from conf/nginx.conf) and a live
            // process. "pgrep nginx" would also match a Homebrew-service nginx.
            $pid = trim((string) @file_get_contents(Paths::in('logs', 'nginx.pid')));
            return ctype_digit($pid) && $this->shell->run(['kill', '-0', $pid], null, 5)->ok();
        }
        $r = $this->shell->run(Platform::nginxProcessCommand(), null, 15);
        return Platform::nginxRunningFrom($r->output);
    }

    /**
     * Always "-p {base}/" (relative paths in every config) and, explicitly, the project's
     * conf/nginx.conf: Homebrew's nginx would otherwise read its own default config.
     * @param list<string> $args
     */
    private function nginx(array $args): ShellResult
    {
        $base = Paths::base();
        return $this->shell->run(array_merge(self::command(), $args), $base, 30);
    }

    /** @return list<string> nginx binary + prefix + config arguments */
    public static function command(): array
    {
        $base = Paths::base();
        return [Platform::nginxBinary(), '-p', $base . '/', '-c', 'conf/nginx.conf'];
    }
}
