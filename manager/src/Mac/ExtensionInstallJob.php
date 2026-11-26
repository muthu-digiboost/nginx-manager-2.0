<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : ExtensionInstallJob.php
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
 * File: manager/src/Mac/ExtensionInstallJob.php
 * Purpose: Install a PECL extension on macOS (install-extension.ps1 on Windows).
 * Responsibilities:
 *   - --Id 8.4 --Ext redis --Enable 1|0
 *   - brew tap shivammathur/extensions; brew install shivammathur/extensions/<ext>@<branch>
 *     (the formula builds the .so and switches it on in conf.d/ext-<ext>.ini)
 *   - Enable 0: switched off again right away (ExtensionManager, php.ini checked)
 *   - restarts that PHP if it was running, so the extension loads
 * Used by: JobCommand (UI: PHP Extensions > Install a new extension)
 */

declare(strict_types=1);

namespace NginxManager\Mac;

use InvalidArgumentException;
use NginxManager\App;
use NginxManager\Php\ExtensionManager;
use NginxManager\Php\PhpVersionRepository;
use RuntimeException;

final class ExtensionInstallJob extends Job
{
    private readonly Brew $brew;
    private readonly object $cgi;

    /** @param array<string, string> $args */
    public function __construct(App $app, string $jobId, array $args, ?Brew $brew = null, ?object $cgi = null)
    {
        parent::__construct($app, $jobId, $args);
        $this->brew = $brew ?? new Brew($app->shell());
        $this->cgi = $cgi ?? new PhpCgi($app->shell());
    }

    public function title(): string
    {
        return 'Install ' . ($this->args['Ext'] ?? '') . ' for PHP ' . ($this->args['Id'] ?? '');
    }

    public function run(): string
    {
        $id = $this->arg('Id', '/^\d+\.\d+(\.\d+)?$/');
        $ext = $this->arg('Ext', '/^[a-z0-9_]{2,40}$/');
        $enable = $this->arg('Enable', '/^[01]$/', '1') === '1';
        $entry = $this->app->settings()->phpVersion($id);
        if ($entry === null) {
            throw new InvalidArgumentException("Unknown PHP version: $id");
        }
        $formula = Brew::EXT_TAP . "/$ext@" . PhpVersionRepository::branchOf($id);

        $this->say('Adding the ' . Brew::EXT_TAP . ' tap...');
        $this->brewOrFail($this->brew, ['tap', Brew::EXT_TAP], 'brew tap');
        $this->say("Installing $formula with Homebrew - this can take a few minutes...");
        $this->brewOrFail($this->brew, ['install', $formula], 'brew install');

        if (!$enable) {
            $m = new ExtensionManager($this->app->settings(), $this->app->shell(), $this->app->jobs(), static fn(): bool => false);
            $res = $m->setEnabled($id, [$ext => false]);
            $this->say($res['ok'] ? "$ext installed but left switched off" : "Could not switch $ext off: " . $res['output'], $res['ok'] ? 'info' : 'warn');
        }

        if ($this->cgi->stop($entry) === 'stopped') {
            $state = $this->cgi->start($entry);
            $this->say("PHP $id restarted: $state", $state === 'running' ? 'ok' : 'warn');
        }
        return "$ext installed for PHP $id" . ($enable ? ' and switched on' : '');
    }
}
