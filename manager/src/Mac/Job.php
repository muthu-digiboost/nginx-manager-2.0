<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : Job.php
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
 * File: manager/src/Mac/Job.php
 * Purpose: Base class of the macOS background jobs (PHP versions of the PowerShell scripts).
 * Responsibilities:
 *   - holds the App, the job id and the "--Name value" arguments
 *   - say(): one line to the job log (and to the console when run by hand)
 *   - arg(): a validated argument
 *   - run(): does the work; returns the final message or throws (JobCommand records state)
 * Used by: JobCommand, the *Job classes in this folder
 */

declare(strict_types=1);

namespace NginxManager\Mac;

use InvalidArgumentException;
use NginxManager\App;

abstract class Job
{
    /** @param array<string, string> $args */
    public function __construct(protected readonly App $app, protected readonly string $jobId, protected readonly array $args) {}

    abstract public function title(): string;

    /** @return string the message shown when the job is done */
    abstract public function run(): string;

    public function say(string $message, string $level = 'info'): void
    {
        if ($this->jobId !== '') {
            $this->app->jobs()->appendLog($this->jobId, $message);
        }
        if (PHP_SAPI === 'cli' && $this->jobId === '' && getenv('NM_QUIET') !== '1') {   // run by hand in a terminal
            echo ['ok' => '[+] ', 'warn' => '[!] ', 'error' => '[x] '][$level] ?? '[*] ', $message, "\n";
        }
    }

    /**
     * Runs brew, copies the last lines of its output into the job log, throws when it failed.
     * @param list<string> $args
     */
    protected function brewOrFail(Brew $brew, array $args, string $what): void
    {
        $r = $brew->run($args);
        foreach (array_slice(preg_split('/\r?\n/', trim($r->output)) ?: [], -15) as $line) {
            if ($line !== '') {
                $this->say('  ' . $line);
            }
        }
        if (!$r->ok()) {
            throw new \RuntimeException("$what failed (see the log above).");
        }
    }

    protected function arg(string $name, string $pattern, ?string $default = null): string
    {
        $value = $this->args[$name] ?? $default;
        if ($value === null || !preg_match($pattern, $value)) {
            throw new InvalidArgumentException("Missing or invalid --$name");
        }
        return $value;
    }
}
