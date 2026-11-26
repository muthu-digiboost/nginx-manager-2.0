<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : ManagerSwitchJob.php
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
 * File: manager/src/Mac/ManagerSwitchJob.php
 * Purpose: Run the manager UI on another installed PHP version on macOS
 *          (scripts/switch-manager-php.ps1 on Windows - same steps, same switch-back).
 * Responsibilities, in order:
 *   1. target must be PHP 8.1+ and not the current one
 *   2. missing required extensions (mbstring, openssl) are switched on
 *   3. compatibility check with the target php (lint, tests, deprecations) - nothing has
 *      changed yet when it fails
 *   4. target php-cgi started
 *   5. settings: managerVersion = target; the old version keeps starting with the server
 *   6. manager.conf rewritten, nginx -t + reload
 *   7. /health must answer from the target PHP; otherwise steps 5-6 are undone
 * Used by: JobCommand (UI: PHP Versions > Manager PHP)
 * Notes: every outside effect is injectable (named constructor arguments) for tests.
 */

declare(strict_types=1);

namespace NginxManager\Mac;

use InvalidArgumentException;
use NginxManager\App;
use NginxManager\Nginx\NginxConfigWriter;
use NginxManager\Php\CompatCheck;
use NginxManager\Php\ExtensionManager;
use NginxManager\Php\PhpPaths;
use NginxManager\Support\Env;
use NginxManager\Support\ManagerRequirements;
use NginxManager\Support\Paths;
use NginxManager\Support\Settings;
use RuntimeException;
use Throwable;

final class ManagerSwitchJob extends Job
{
    private readonly object $cgi;
    /** @var callable(string): list<string> */
    private $compat;
    /** @var callable(string): list<string> */
    private $modules;
    /** @var callable(): bool */
    private $reload;
    /** @var callable(): ?string  PHP version reported by /health, null = no answer */
    private $health;

    /** @param array<string, string> $args */
    public function __construct(App $app, string $jobId, array $args, ?object $cgi = null, ?callable $compat = null,
                                ?callable $modules = null, ?callable $reload = null, ?callable $health = null)
    {
        parent::__construct($app, $jobId, $args);
        $this->cgi = $cgi ?? new PhpCgi($app->shell());
        $this->compat = $compat ?? fn(string $php): array => CompatCheck::run($app->shell(), $php, Paths::base());
        $this->modules = $modules ?? fn(string $php): array => array_map('strtolower', array_map('trim',
            preg_split('/\r?\n/', $app->shell()->run([$php, '-m'], null, 30)->output) ?: []));
        $this->reload = $reload ?? fn(): bool => $app->nginx()->reload()->ok();
        $this->health = $health ?? [self::class, 'askHealth'];
    }

    public function title(): string
    {
        return 'Run the manager on PHP ' . ($this->args['Id'] ?? '');
    }

    public function run(): string
    {
        $id = $this->arg('Id', '/^\d+\.\d+(\.\d+)?$/');
        $settings = Settings::load();
        $oldId = $settings->managerVersion();
        $target = $settings->phpVersion($id);
        if ($target === null) {
            throw new InvalidArgumentException("Unknown PHP version: $id");
        }
        if ($id === $oldId) {
            throw new InvalidArgumentException("The manager already runs on PHP $id.");
        }
        $version = (string) ($target['version'] ?? $id);
        if (!ManagerRequirements::versionSupported($version)) {
            throw new InvalidArgumentException('The manager needs PHP ' . ManagerRequirements::MIN_PHP . " or newer - PHP $id is $version.");
        }
        $php = PhpPaths::forEntry($target)->php;
        $this->say("Switching the manager from PHP $oldId to PHP $version");

        // 2. required extensions
        foreach (ManagerRequirements::missingExtensions(($this->modules)($php)) as $ext) {
            $this->say("Enabling $ext in PHP $id (the manager needs it)...");
            $res = (new ExtensionManager($this->app->settings(), $this->app->shell(), $this->app->jobs(), static fn(): bool => false))
                ->setEnabled($id, [$ext => true]);
            if (!$res['ok']) {
                throw new RuntimeException("Could not enable $ext in PHP $id: " . $res['output']);
            }
        }

        // 3. compatibility (nothing switched yet)
        $this->say("Checking the manager's code with PHP $version (syntax, tests, deprecations)...");
        $problems = ($this->compat)($php);
        if ($problems) {
            foreach (array_slice($problems, 0, 30) as $p) {
                $this->say('  ' . $p, 'warn');
            }
            throw new RuntimeException(sprintf('PHP %s cannot run the manager cleanly yet (%d problem(s)). The manager stays on PHP %s.',
                $id, count($problems), $oldId));
        }
        $this->say('Compatible: no errors, warnings or deprecations.', 'ok');

        // 4. start the target
        $state = $this->cgi->start($target);
        if (!in_array($state, ['running', 'already-running'], true)) {
            throw new RuntimeException("PHP $id did not start ($state).");
        }

        // 5-7. switch, reload, confirm - or switch back
        $this->setManager($id, $oldId);
        try {
            NginxConfigWriter::writeAll(Settings::load());
            $this->say('This page reconnects in a few seconds...', 'warn');
            if (!($this->reload)()) {
                throw new RuntimeException('nginx did not reload.');
            }
            // Same major.minor is enough: Homebrew may have upgraded the patch level meanwhile.
            $answer = ($this->health)();
            if ($answer === null || \NginxManager\Php\PhpVersionRepository::branchOf($answer)
                    !== \NginxManager\Php\PhpVersionRepository::branchOf($version)) {
                throw new RuntimeException("The manager did not answer on PHP $version.");
            }
        } catch (Throwable $e) {
            $this->say("Switching back to PHP $oldId...", 'warn');
            $this->setManager($oldId, null);
            NginxConfigWriter::writeAll(Settings::load());
            ($this->reload)();
            throw $e;
        }
        return "The manager now runs on PHP $version";
    }

    /** managerVersion = $id; the previous manager PHP keeps starting with the server. */
    private function setManager(string $id, ?string $previous): void
    {
        $this->app->settings()->update(static function (array &$s) use ($id, $previous) {
            $s['php']['managerVersion'] = $id;
            foreach ($s['php']['versions'] as &$v) {
                if ($previous !== null && $v['id'] === $previous) {
                    $v['autostart'] = true;
                }
            }
        });
    }

    /** Polls http://<manager>/health for up to ~20 s; returns the PHP version that answered. */
    public static function askHealth(): ?string
    {
        $url = 'http://' . Env::get('MANAGER_HOST', '127.0.0.1') . ':' . Env::int('MANAGER_PORT', 8080) . '/health';
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        for ($i = 0; $i < 20; $i++) {
            sleep(1);
            $json = json_decode((string) @file_get_contents($url, false, $ctx), true);
            if (is_array($json) && !empty($json['ok'])) {
                return (string) ($json['php'] ?? '');
            }
        }
        return null;
    }
}
