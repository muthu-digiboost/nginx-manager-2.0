<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : PhpServiceJob.php
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
 * File: manager/src/Mac/PhpServiceJob.php
 * Purpose: Start / stop / restart one or more PHP versions on macOS (php-service.ps1 on Windows).
 * Responsibilities:
 *   - --Action start|stop|restart, --Id "8.4" or "7.4,8.1,8.4"
 *   - every version is handled on its own; the job fails if any of them failed
 * Used by: JobCommand (UI: dashboard, PHP Versions, Extensions)
 */

declare(strict_types=1);

namespace NginxManager\Mac;

use InvalidArgumentException;
use NginxManager\App;
use RuntimeException;

final class PhpServiceJob extends Job
{
    private readonly object $cgi;

    /** @param array<string, string> $args */
    public function __construct(App $app, string $jobId, array $args, ?object $cgi = null)
    {
        parent::__construct($app, $jobId, $args);
        $this->cgi = $cgi ?? new PhpCgi($app->shell());
    }

    public function title(): string
    {
        return 'PHP ' . ($this->args['Id'] ?? '') . ' - ' . ($this->args['Action'] ?? '');
    }

    public function run(): string
    {
        $action = $this->arg('Action', '/^(start|stop|restart)$/');
        $ids = array_values(array_filter(array_map('trim', explode(',', $this->arg('Id', '/^[0-9.,]+$/')))));
        $failed = [];
        foreach ($ids as $id) {
            $entry = $this->app->settings()->phpVersion($id);
            if ($entry === null) {
                throw new InvalidArgumentException("Unknown PHP version: $id");
            }
            if ($action !== 'start') {
                $state = $this->cgi->stop($entry);
                $this->say("PHP $id on port {$entry['port']}: $state");
                if (in_array($state, ['foreign', 'still-listening'], true)) {
                    $failed[] = $id;
                    continue;
                }
            }
            if ($action !== 'stop') {
                $state = $this->cgi->start($entry);
                $this->say("PHP $id on port {$entry['port']}: $state", in_array($state, ['running', 'already-running'], true) ? 'ok' : 'warn');
                if (!in_array($state, ['running', 'already-running'], true)) {
                    $failed[] = $id;
                }
            }
        }
        if ($failed) {
            throw new RuntimeException('PHP ' . implode(', ', $failed) . ": $action failed - see the log above.");
        }
        return 'PHP ' . implode(', ', $ids) . " - $action done";
    }
}
