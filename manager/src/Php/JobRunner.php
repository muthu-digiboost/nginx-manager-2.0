<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : JobRunner.php
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
 * File: manager/src/Php/JobRunner.php
 * Purpose: Runs long or self-affecting work (start/stop/restart PHP, install/update PHP,
 *          install extensions) as DETACHED PowerShell jobs the UI can watch.
 * Responsibilities:
 *   - start(script, args, title): validate, write data/jobs/<id>.json (queued), launch
 *     "powershell -File scripts\<script> -JobId <id> -Key value ..." without waiting
 *   - status(id), log(id, offset) (incremental, for polling), recent(n)
 * Why detached: restarting or updating the PHP version the manager itself runs on
 *   kills the request that asked for it; the job keeps running and the job page
 *   reconnects when the manager is back.
 * Security: the manager is elevated, so only allow-listed scripts run, argument names
 *   and values are validated, and the command is an argv array (no shell parsing of
 *   user input). Job ids are validated on every read.
 * Used by: PhpController, ExtensionsController, JobsController
 * Notes: launch() is protected so tests can record instead of spawning processes.
 *        The scripts write progress with Write-JobLog / Set-JobState (Common.psm1).
 */

declare(strict_types=1);

namespace NginxManager\Php;

use InvalidArgumentException;
use NginxManager\Support\JsonStore;
use NginxManager\Support\Paths;
use NginxManager\Support\Platform;

class JobRunner
{
    public const SCRIPTS = ['php-service.ps1', 'install-php.ps1', 'install-extension.ps1', 'switch-manager-php.ps1'];

    private const ID_RE = '/^\d{8}-\d{6}-[0-9a-f]{6}$/';

    /** @param array<string, string> $args */
    public function start(string $script, array $args, string $title): string
    {
        if (!in_array($script, self::SCRIPTS, true)) {
            throw new InvalidArgumentException("Script not allowed: $script");
        }
        foreach ($args as $key => $value) {
            if (!is_string($key) || !preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $key)) {
                throw new InvalidArgumentException("Invalid argument name: $key");
            }
            if (!preg_match('~^[A-Za-z0-9._:/,+=-]*$~', (string) $value)) {
                throw new InvalidArgumentException("Invalid value for -$key");
            }
        }

        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        JsonStore::write($this->file($id, 'json'), [
            'id'        => $id,
            'title'     => $title,
            'script'    => $script,
            'state'     => 'queued',
            'message'   => '',
            'createdAt' => date('c'),
        ]);
        file_put_contents($this->file($id, 'log'), '');

        if (Platform::isWindows()) {
            $argv = [
                'powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-WindowStyle', 'Hidden',
                '-File', Paths::in('scripts', $script),
                '-JobId', $id,
            ];
            $prefix = '-';
        } else {
            // macOS: the same job, implemented in PHP (manager/src/Mac), run by the CLI php.
            $argv = [self::cliPhpFor(PHP_BINARY), Paths::in('manager', 'bin', 'manager.php'), 'job:run', $script, '--JobId', $id];
            $prefix = '--';
        }
        foreach ($args as $key => $value) {
            $argv[] = $prefix . $key;
            $argv[] = (string) $value;
        }
        try {
            $this->launch($argv);
        } catch (\Throwable $e) {
            // Never leave a job "queued" forever: the job page shows this message.
            $this->markFailed($id, 'The job could not be started: ' . $e->getMessage());
        }
        return $id;
    }

    /** A job still "queued" after this many seconds never started (its script did not run). */
    private const START_TIMEOUT = 60;

    public function status(string $id): ?array
    {
        if (!preg_match(self::ID_RE, $id) || !is_file($this->file($id, 'json'))) {
            return null;
        }
        $job = JsonStore::read($this->file($id, 'json'));
        if (($job['state'] ?? '') === 'queued'
            && time() - (int) strtotime((string) ($job['createdAt'] ?? 'now')) > self::START_TIMEOUT) {
            $job = $this->markFailed($id, Platform::isWindows()
                ? 'The job did not start (PowerShell did not run the script). Try again, or run the script from a console to see the error.'
                : 'The job did not start. Try again, or run it in Terminal to see the error: php manager/bin/manager.php job:run '
                  . (string) ($job['script'] ?? '') . ' ...');
        }
        return $job;
    }

    private function markFailed(string $id, string $message): array
    {
        return JsonStore::update($this->file($id, 'json'), static function (array &$job) use ($message) {
            if (($job['state'] ?? '') === 'queued') {
                $job['state'] = 'failed';
                $job['message'] = $message;
            }
        });
    }

    /** @return array{text:string, offset:int, state:string, message:string} */
    public function log(string $id, int $offset): array
    {
        $job = $this->status($id);
        if ($job === null) {
            return ['text' => '', 'offset' => 0, 'state' => 'unknown', 'message' => ''];
        }
        $file = $this->file($id, 'log');
        $size = is_file($file) ? (int) filesize($file) : 0;
        $offset = max(0, min($offset, $size));
        $text = $size > $offset ? (string) file_get_contents($file, false, null, $offset) : '';
        return ['text' => $text, 'offset' => $offset + strlen($text), 'state' => (string) ($job['state'] ?? 'unknown'), 'message' => (string) ($job['message'] ?? '')];
    }

    /** @return list<array> newest first */
    public function recent(int $n = 10): array
    {
        $files = glob(Paths::in('data', 'jobs') . '/*.json') ?: [];
        rsort($files);
        $jobs = [];
        foreach (array_slice($files, 0, $n) as $f) {
            $id = basename($f, '.json');
            if ($job = $this->status($id)) {
                $jobs[] = $job;
            }
        }
        return $jobs;
    }

    /**
     * Spawns the job detached and WITHOUT inherited handles.
     *
     * Why not "cmd /c start /B" directly from php-cgi: child processes created that way
     * inherit php-cgi's FastCGI listening socket. When the job then restarts the PHP
     * version the manager runs on, the old port stays "listening" (held by the job),
     * the new php-cgi is never started and the manager is down (final review #1).
     *
     * So a short-lived PowerShell runs Start-Process (ShellExecute - no handle
     * inheritance) to start the real job, and exits immediately. The script is passed
     * with -EncodedCommand, so no shell quoting is involved at all.
     */
    /** The PHP CLI binary next to the running php-cgi (Homebrew: <prefix>/bin/php), else "php". */
    public static function cliPhpFor(string $binary, ?callable $exists = null): string
    {
        $exists ??= static fn(string $p): bool => is_file($p);
        $cli = dirname($binary) . '/php';
        return $exists($cli) ? $cli : 'php';
    }

    /**
     * macOS: "sh -c 'nohup "$@" &'" returns at once; the job keeps running after this request
     * (and after a php-cgi restart), with no inherited terminal.
     * @param list<string> $argv
     * @return list<string>
     */
    public static function macLauncherCommand(array $argv, string $log = '/dev/null'): array
    {
        // Close every inherited descriptor above 2 first: php-cgi's FastCGI listening socket
        // must not live on in the job, or restarting that PHP leaves a dead port behind.
        $closeFds = 'i=3; while [ $i -lt 256 ]; do eval "exec $i>&-" 2>/dev/null; i=$((i+1)); done; ';
        // The output file is the first argument (never part of the script text), then the command.
        // /bin/bash, not /bin/sh: sh may be switched to dash, which cannot close fds above 9.
        return array_merge(['/bin/bash', '-c', 'log="$1"; shift; ' . $closeFds . 'nohup "$@" >>"$log" 2>&1 &', 'nm-job', $log], $argv);
    }

    /** Appends a timestamped line to the job log (macOS jobs; PowerShell uses Write-JobLog). */
    public function appendLog(string $id, string $message): void
    {
        if (preg_match(self::ID_RE, $id)) {
            file_put_contents($this->file($id, 'log'), '[' . date('H:i:s') . '] ' . $message . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    /** Sets state + message (macOS jobs; PowerShell uses Set-JobState). */
    public function setState(string $id, string $state, string $message = ''): void
    {
        if (!preg_match(self::ID_RE, $id) || !in_array($state, ['queued', 'running', 'done', 'failed'], true)) {
            throw new InvalidArgumentException('Invalid job id or state.');
        }
        JsonStore::update($this->file($id, 'json'), static function (array &$job) use ($id, $state, $message) {
            $job['id'] = $id;
            $job['state'] = $state;
            $job['message'] = $message;
            $job['updatedAt'] = date('c');
        });
    }

    protected function launch(array $argv): void
    {
        if (!Platform::isWindows()) {
            $proc = proc_open(self::macLauncherCommand($argv),
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes, Paths::base());
            if (!is_resource($proc) || proc_close($proc) !== 0) {
                throw new \RuntimeException('Could not start the job with /bin/bash.');
            }
            return;
        }
        $proc = proc_open(self::launcherCommand($argv),
            [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']],
            $pipes, Paths::base(), null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            throw new \RuntimeException("powershell.exe could not be started.");
        }
        // Returns as soon as Start-Process has started the job; non-zero = Start-Process failed.
        $exit = proc_close($proc);
        if ($exit !== 0) {
            throw new \RuntimeException("Start-Process failed (exit code $exit).");
        }
    }

    /**
     * argv for the short-lived launcher: powershell -EncodedCommand <Start-Process ...>.
     * Arguments with spaces (the install path) and empty values are wrapped in double quotes,
     * ' is doubled for the PowerShell literal; " % ` $ and line breaks are refused (values
     * were already validated in start()).
     *
     * @param list<string> $argv  the job command: powershell.exe followed by its arguments
     * @return list<string>
     */
    public static function launcherCommand(array $argv): array
    {
        $exe = array_shift($argv);
        $parts = [];
        foreach ($argv as $arg) {
            $arg = (string) $arg;
            if (preg_match('/["\r\n%`$]/', $arg)) {
                throw new InvalidArgumentException('Unsafe character in job command line.');
            }
            $arg = str_replace("'", "''", $arg);   // inside the single-quoted PowerShell literal below
            // Empty values must stay an argument ("") or the following -Name binds wrongly.
            $parts[] = ($arg === '' || str_contains($arg, ' ')) ? '"' . $arg . '"' : $arg;
        }
        $script = "Start-Process -FilePath '" . str_replace("'", "''", (string) $exe) . "' -WindowStyle Hidden"
            . " -ArgumentList '" . implode(' ', $parts) . "'";
        $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
        return ['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', $encoded];
    }

    private function file(string $id, string $ext): string
    {
        $dir = Paths::in('data', 'jobs');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir . '/' . $id . '.' . $ext;
    }
}
