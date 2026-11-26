<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : PhpVersionRepository.php
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
 * File: manager/src/Php/PhpVersionRepository.php
 * Purpose: The installed PHP versions as the UI sees them.
 * Responsibilities:
 *   - installed(): settings entries + branch (8.5 for "8.5.12"), running (port probe),
 *     autostart ("Auto-start"), dashboard (pinned to the dashboard), isManager, folder exists
 *   - setAutostart(id, bool): only versions you choose start with start-all.ps1; the
 *     manager's own PHP always starts (it serves this UI)
 *   - validateSelection(ids, action): sanitises a bulk start/stop/restart selection and
 *     refuses to STOP the manager's own PHP (restart is fine - it comes back)
 * Used by: DashboardController, PhpController, ExtensionsController
 * Notes: the port probe is injectable for tests.
 */

declare(strict_types=1);

namespace NginxManager\Php;

use InvalidArgumentException;
use NginxManager\Support\ManagerRequirements;
use NginxManager\Support\Paths;
use NginxManager\Support\Settings;

final class PhpVersionRepository
{
    /** @var callable(int): bool */
    private $probe;

    public function __construct(private readonly Settings $settings, ?callable $portProbe = null)
    {
        $this->probe = $portProbe ?? [self::class, 'isListening'];
    }

    /**
     * Is something LISTENING on 127.0.0.1:<port>? Reads "netstat -an -p TCP" once per
     * request. (Connecting to a closed port costs ~0.5 s on Windows because the TCP stack
     * retries a refused loopback connection - with several stopped PHP versions that made
     * every page slow.)
     */
    public static function isListening(int $port): bool
    {
        static $ports = null;
        if ($ports === null) {
            $ports = [];
            $out = (new \NginxManager\Support\Shell())->run(\NginxManager\Support\Platform::netstatCommand(), null, 10)->output;
            $ports = array_fill_keys(\NginxManager\Support\Platform::parseListeningPorts($out), true);
        }
        return isset($ports[$port]);
    }

    /** @return list<array{id:string, branch:string, port:int, upstream:string, version:string, threadSafety:string, autostart:bool, running:bool, isManager:bool, path:string, exists:bool}> */
    public function installed(): array
    {
        $manager = $this->settings->managerVersion();
        $list = [];
        foreach ($this->settings->phpVersions() as $v) {
            $id = (string) $v['id'];
            $paths = PhpPaths::forEntry($v);
            $path = $paths->dir;
            $list[] = [
                'id'           => $id,
                'branch'       => self::branchOf($id),
                'port'         => (int) $v['port'],
                'upstream'     => (string) $v['upstream'],
                'version'      => (string) ($v['version'] ?? ''),
                // TS/NTS only exists for Windows builds; Homebrew PHP has no such label.
                'threadSafety' => (string) ($v['threadSafety'] ?? (\NginxManager\Support\Platform::isWindows() ? 'ts' : '')),
                'formula'      => (string) ($v['formula'] ?? ''),   // macOS: Homebrew formula (php@8.4 / php)
                'autostart'    => $id === $manager || !empty($v['autostart']),
                'dashboard'    => !empty($v['dashboard']),
                'running'      => ($this->probe)((int) $v['port']),
                'isManager'    => $id === $manager,
                'path'         => $path,
                'exists'       => is_file($paths->cgi),
            ];
        }
        // Always oldest -> newest by version number (7.4, 8.2, ... 8.10), whatever order the
        // versions were installed in; the folder name decides when the versions are equal.
        usort($list, static function (array $a, array $b): int {
            $va = $a['version'] !== '' ? $a['version'] : $a['id'];
            $vb = $b['version'] !== '' ? $b['version'] : $b['id'];
            return version_compare($va, $vb) ?: strnatcmp($a['id'], $b['id']);
        });
        return $list;
    }

    public function find(string $id): ?array
    {
        foreach ($this->installed() as $v) {
            if ($v['id'] === $id) {
                return $v;
            }
        }
        return null;
    }

    /**
     * Versions the manager itself could run on (Settings > Manager PHP).
     * @return list<array{id:string, version:string, eligible:bool, reason:string, isManager:bool}>
     */
    public function managerCandidates(): array
    {
        $out = [];
        foreach ($this->installed() as $v) {
            $version = $v['version'] !== '' ? $v['version'] : $v['id'];
            $reason = '';
            if ($v['isManager']) {
                $reason = 'The manager already runs on this version.';
            } elseif (!ManagerRequirements::versionSupported($version)) {
                $reason = 'Needs PHP ' . ManagerRequirements::MIN_PHP . ' or newer.';
            }
            $out[] = ['id' => $v['id'], 'version' => $version, 'eligible' => $reason === '',
                      'reason' => $reason, 'isManager' => $v['isManager']];
        }
        return $out;
    }

    /** @return string the validated id; throws when the manager cannot switch to it */
    public function validateManagerTarget(string $id): string
    {
        foreach ($this->managerCandidates() as $c) {
            if ($c['id'] === $id) {
                if (!$c['eligible']) {
                    throw new InvalidArgumentException("PHP $id: " . $c['reason']);
                }
                return $id;
            }
        }
        throw new InvalidArgumentException("Unknown PHP version: $id");
    }

    public function setAutostart(string $id, bool $on): void
    {
        if (!$on && $id === $this->settings->managerVersion()) {
            throw new InvalidArgumentException("PHP $id runs this manager and always starts with the server.");
        }
        $this->setFlag($id, 'autostart', $on);
    }

    /** "Dashboard" pin: only pinned versions get quick-action tiles on the dashboard. */
    public function setDashboard(string $id, bool $on): void
    {
        $this->setFlag($id, 'dashboard', $on);
    }

    private function setFlag(string $id, string $flag, bool $on): void
    {
        if ($this->settings->phpVersion($id) === null) {
            throw new InvalidArgumentException("Unknown PHP version: $id");
        }
        $this->settings->update(static function (array &$data) use ($id, $flag, $on): void {
            foreach ($data['php']['versions'] as &$v) {
                if (($v['id'] ?? null) === $id) {
                    $v[$flag] = $on;
                }
            }
        });
    }

    /**
     * Folder id for a new install: the branch ("5.6") when that branch is not installed
     * yet, otherwise the exact version ("8.3.37") so both can run side by side.
     * Refuses a version that is already installed under any id.
     */
    public function installTargetId(string $branch, string $version): string
    {
        if (self::branchOf($version) !== $branch || !preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            throw new InvalidArgumentException("Version $version does not belong to PHP $branch.");
        }
        $ids = [];
        foreach ($this->settings->phpVersions() as $v) {
            if (($v['version'] ?? '') === $version || $v['id'] === $version) {
                throw new InvalidArgumentException("PHP $version is already installed (folder php\\{$v['id']}).");
            }
            $ids[] = $v['id'];
        }
        if (is_dir(Paths::in('php', $branch)) && !in_array($branch, $ids, true)) {
            throw new InvalidArgumentException("Folder php\\$branch exists but is not registered - remove or rename it first.");
        }
        return in_array($branch, $ids, true) ? $version : $branch;
    }

    /**
     * @param list<string> $ids
     * @return list<string> unique, known ids in settings order
     */
    public function validateSelection(array $ids, string $action): array
    {
        if (!in_array($action, ['start', 'stop', 'restart'], true)) {
            throw new InvalidArgumentException("Unknown action: $action");
        }
        $known = array_column($this->settings->phpVersions(), 'id');
        $selected = array_values(array_filter($known, static fn($id) => in_array($id, $ids, true)));
        if ($selected === []) {
            throw new InvalidArgumentException('Select at least one PHP version.');
        }
        $manager = $this->settings->managerVersion();
        if ($action === 'stop' && in_array($manager, $selected, true)) {
            throw new InvalidArgumentException("PHP $manager runs this manager - it can be restarted but not stopped from here.");
        }
        return $selected;
    }

    /** "8.5.12" -> "8.5" */
    public static function branchOf(string $id): string
    {
        return preg_match('/^(\d+\.\d+)/', $id, $m) ? $m[1] : $id;
    }
}
