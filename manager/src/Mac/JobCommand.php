<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : JobCommand.php
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
 * File: manager/src/Mac/JobCommand.php
 * Purpose: "manager.php job:run <job> --JobId <id> --Name value ..." on macOS - the PHP
 *          counterpart of the PowerShell job scripts on Windows.
 * Responsibilities:
 *   - parseArgs(): "--Name value" pairs -> [Name => value]
 *   - run(): maps the job name (the Windows script name the UI uses, e.g.
 *     "php-service.ps1") to its macOS class, runs it, and records running / done / failed
 *     in data/jobs/<id>.json so the job page works the same on both systems
 * Used by: bin/manager.php (job:run), started by JobRunner::launch on macOS
 */

declare(strict_types=1);

namespace NginxManager\Mac;

use InvalidArgumentException;
use NginxManager\App;
use Throwable;

final class JobCommand
{
    /** @var array<string, class-string<Job>> UI job name => macOS implementation */
    public const JOBS = [
        'php-service.ps1'        => PhpServiceJob::class,
        'install-php.ps1'        => PhpInstallJob::class,
        'install-extension.ps1'  => ExtensionInstallJob::class,
        'switch-manager-php.ps1' => ManagerSwitchJob::class,
    ];

    /**
     * @param list<string> $argv
     * @return array<string, string>
     */
    public static function parseArgs(array $argv): array
    {
        $out = [];
        for ($i = 0, $n = count($argv); $i < $n; $i += 2) {
            if (!preg_match('/^--([A-Za-z][A-Za-z0-9]*)$/', $argv[$i]) || !array_key_exists($i + 1, $argv)) {
                throw new InvalidArgumentException('Expected "--Name value" pairs, got: ' . $argv[$i]);
            }
            $out[substr($argv[$i], 2)] = $argv[$i + 1];
        }
        return $out;
    }

    /**
     * Leaves the process group of the php-cgi that started the job. php-cgi's master
     * (PHP_FCGI_CHILDREN set) signals its WHOLE process group on SIGTERM, so a job that
     * restarts that PHP would otherwise kill itself before starting it again.
     * @var (callable(): void)|null  replaced in tests
     */
    public static $detach = null;

    /** @param list<string> $argv  job name followed by --Name value pairs */
    public static function run(App $app, array $argv): int
    {
        (self::$detach ?? static function (): void {
            if (function_exists('posix_setsid')) {
                @posix_setsid();
            }
        })();
        $name = (string) array_shift($argv);
        $args = self::parseArgs($argv);
        $jobId = (string) ($args['JobId'] ?? '');
        $class = self::JOBS[$name] ?? null;
        if ($class === null) {
            throw new InvalidArgumentException("Unknown job: $name");
        }
        $job = new $class($app, $jobId, $args);
        try {
            $jobId !== '' && $app->jobs()->setState($jobId, 'running', $job->title());
            $message = $job->run();
            $jobId !== '' && $app->jobs()->setState($jobId, 'done', $message);
            $job->say($message, 'ok');
            return 0;
        } catch (Throwable $e) {
            $job->say($e->getMessage(), 'error');
            $jobId !== '' && $app->jobs()->setState($jobId, 'failed', $e->getMessage());
            return 1;
        }
    }
}
