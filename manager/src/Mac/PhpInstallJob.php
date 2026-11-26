<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : PhpInstallJob.php
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
 * File: manager/src/Mac/PhpInstallJob.php
 * Purpose: Install or update a PHP version on macOS with Homebrew (install-php.ps1 on Windows).
 * Responsibilities:
 *   - --Mode install --Id 8.2: brew tap shivammathur/php, brew install shivammathur/php/php@8.2,
 *     register it in settings.json (port, upstream, formula, prefix, php.ini, extension dir),
 *     rewrite the nginx upstreams, reload nginx, start its php-cgi
 *   - --Mode update --Id 8.2: brew upgrade <formula>, record the new version, restart its
 *     php-cgi if it was running (or it is the manager's PHP)
 *   - nothing is registered when brew fails; brew's output is in the job log
 * Used by: JobCommand (UI: PHP Versions > Add PHP Version / Update)
 */

declare(strict_types=1);

namespace NginxManager\Mac;

use InvalidArgumentException;
use NginxManager\App;
use NginxManager\Nginx\NginxConfigWriter;
use NginxManager\Support\Settings;
use RuntimeException;

final class PhpInstallJob extends Job
{
    private readonly Brew $brew;
    private readonly object $cgi;
    /** @var callable(): bool */
    private $reloadNginx;

    /** @param array<string, string> $args */
    public function __construct(App $app, string $jobId, array $args, ?Brew $brew = null, ?object $cgi = null, ?callable $reloadNginx = null)
    {
        parent::__construct($app, $jobId, $args);
        $this->brew = $brew ?? new Brew($app->shell());
        $this->cgi = $cgi ?? new PhpCgi($app->shell());
        $this->reloadNginx = $reloadNginx ?? fn(): bool => $app->nginx()->reload()->ok();
    }

    public function title(): string
    {
        return ucfirst($this->args['Mode'] ?? 'install') . ' PHP ' . ($this->args['Id'] ?? '');
    }

    public function run(): string
    {
        $mode = $this->arg('Mode', '/^(install|update)$/');
        $id = $this->arg('Id', '/^\d+\.\d+$/');
        return $mode === 'install' ? $this->install($id) : $this->update($id);
    }

    private function install(string $id): string
    {
        if (!in_array($id, BrewCatalog::BRANCHES, true)) {
            throw new InvalidArgumentException("PHP $id is not offered by " . Brew::PHP_TAP . '.');
        }
        if (Settings::load()->phpVersion($id) !== null) {
            throw new InvalidArgumentException("PHP $id is already installed.");
        }
        $formula = "php@$id";
        $this->say('Adding the ' . Brew::PHP_TAP . ' tap...');
        $this->brewOrFail($this->brew, ['tap', Brew::PHP_TAP], 'brew tap');
        $this->say("Installing $formula with Homebrew - this can take several minutes...");
        $this->brewOrFail($this->brew, ['install', Brew::PHP_TAP . '/' . $formula], 'brew install');

        $d = $this->brew->describe($formula);
        if ($d['version'] === '') {
            throw new RuntimeException("$formula was installed but its php does not run.");
        }
        $entry = null;
        $this->app->settings()->update(static function (array &$s) use ($id, $formula, $d, &$entry) {
            $used = array_map(static fn($v) => (int) $v['port'], $s['php']['versions'] ?? []);
            $entry = [
                'id' => $id, 'port' => Server::defaultPort($id, $used), 'upstream' => 'php' . str_replace('.', '', $id),
                'version' => $d['version'], 'formula' => $formula, 'prefix' => $d['prefix'],
                'ini' => $d['ini'], 'extDir' => $d['extDir'], 'autostart' => false,
            ];
            $s['php']['versions'][] = $entry;
        });
        $this->say("Registered PHP $id: port {$entry['port']}, upstream {$entry['upstream']}", 'ok');

        NginxConfigWriter::writeAll(Settings::load());
        $this->say(($this->reloadNginx)() ? 'nginx reloaded' : 'nginx was not reloaded - use Reload nginx', 'info');

        $state = $this->cgi->start($entry);
        $this->say("PHP $id on port {$entry['port']}: $state", $state === 'running' ? 'ok' : 'warn');
        return "PHP {$d['version']} installed";
    }

    private function update(string $id): string
    {
        $entry = Settings::load()->phpVersion($id);
        if ($entry === null) {
            throw new InvalidArgumentException("Unknown PHP version: $id");
        }
        $formula = (string) ($entry['formula'] ?? '') ?: "php@$id";
        $this->say("Updating $formula with Homebrew...");
        // The bare installed name: Homebrew upgrades it from the tap it came from (core or shivammathur).
        $this->brewOrFail($this->brew, ['upgrade', $formula], 'brew upgrade');

        $d = $this->brew->describe($formula);
        $this->app->settings()->update(static function (array &$s) use ($id, $d) {
            foreach ($s['php']['versions'] as &$v) {
                if ($v['id'] === $id) {
                    $v['version'] = $d['version'] ?: $v['version'];
                    $v['ini'] = $d['ini'] ?: ($v['ini'] ?? '');
                    $v['extDir'] = $d['extDir'] ?: ($v['extDir'] ?? '');
                }
            }
        });
        $entry = (array) Settings::load()->phpVersion($id);

        // Restart only what was running (and always the manager's own PHP).
        $stopped = $this->cgi->stop($entry);
        if ($stopped === 'stopped' || $id === Settings::load()->managerVersion()) {
            $state = $this->cgi->start($entry);
            $this->say("PHP $id restarted: $state", $state === 'running' ? 'ok' : 'warn');
        }
        return "PHP $id updated to {$entry['version']}";
    }
}
