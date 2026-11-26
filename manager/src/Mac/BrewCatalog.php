<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : BrewCatalog.php
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
 * File: manager/src/Mac/BrewCatalog.php
 * Purpose: PHP versions you can install, and updates, on macOS (php.net feeds on Windows).
 * Responsibilities:
 *   - branches(): the php@X.Y formulas of the shivammathur/php tap, with an end-of-life flag
 *   - outdated(refresh): formula => newest version, from "brew outdated --json=v2",
 *     cached in data/cache/brew-outdated.json (pages never wait for brew; "Check for
 *     updates" refreshes it)
 *   - compare(installed): {updates, current, eol, pinned} - the shape of UpdateChecker::compare
 * Used by: PhpController (macOS), App::phpUpdateCount
 * Notes: the end-of-life list is fixed here (8.1 and older); update it as branches retire.
 */

declare(strict_types=1);

namespace NginxManager\Mac;

use NginxManager\Php\PhpVersionRepository;
use NginxManager\Support\JsonStore;
use NginxManager\Support\Paths;

final class BrewCatalog
{
    /** Branches published by the shivammathur/php tap, oldest first. */
    public const BRANCHES = ['5.6', '7.0', '7.1', '7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];

    /** Newest branch that no longer gets security fixes. */
    public const LAST_EOL = '8.1';

    public function __construct(private readonly Brew $brew) {}

    /** @return list<array{branch:string, eol:bool, formula:string}> newest first */
    public static function branches(): array
    {
        $out = [];
        foreach (array_reverse(self::BRANCHES) as $b) {
            $out[] = ['branch' => $b, 'eol' => version_compare($b, self::LAST_EOL, '<='), 'formula' => "php@$b"];
        }
        return $out;
    }

    public static function isEol(string $version): bool
    {
        return version_compare(implode('.', array_slice(explode('.', $version), 0, 2)), self::LAST_EOL, '<=');
    }

    /** @return array<string, string> formula (without tap) => newest version */
    public function outdated(bool $refresh = false): array
    {
        $cache = $this->cacheFile();
        if (!$refresh) {
            return JsonStore::read($cache)['outdated'] ?? [];
        }
        // Taps (shivammathur/php) only learn about new releases on "brew update". A failure
        // here (offline) still lets "outdated" answer from what Homebrew knew before.
        $this->brew->run(['update', '--quiet'], 180);
        $r = $this->brew->run(['outdated', '--json=v2'], 120);
        $json = Brew::json($r->output);
        if (!is_array($json)) {
            JsonStore::update($cache, static function (array &$c) use ($r) {
                $c['error'] = trim($r->output) ?: 'brew outdated failed';
            });
            return JsonStore::read($cache)['outdated'] ?? [];
        }
        $out = [];
        foreach ($json['formulae'] ?? [] as $f) {
            $out[basename((string) ($f['name'] ?? ''))] = (string) ($f['current_version'] ?? '');
        }
        JsonStore::write($cache, ['checkedAt' => time(), 'outdated' => $out, 'error' => null]);
        return $out;
    }

    public function checkedAt(): ?int
    {
        $at = JsonStore::read($this->cacheFile())['checkedAt'] ?? null;
        return is_int($at) ? $at : null;
    }

    public function lastError(): ?string
    {
        $e = JsonStore::read($this->cacheFile())['error'] ?? null;
        return is_string($e) && $e !== '' ? $e : null;
    }

    /**
     * @param list<array<string, mixed>> $installed PhpVersionRepository::installed() rows (+ formula)
     * @return array{updates: list<array>, current: list<array>, eol: list<array>, pinned: list<array>}
     */
    public function compare(array $installed): array
    {
        $outdated = $this->outdated();
        $res = ['updates' => [], 'current' => [], 'eol' => [], 'pinned' => []];
        foreach ($installed as $v) {
            $formula = (string) ($v['formula'] ?? '') ?: 'php@' . $v['id'];
            // Same branch only: the plain "php" formula moves to the next branch (8.5 -> 8.6),
            // which must be installed as a new version, not "updated" under the old one.
            if (isset($outdated[$formula]) && version_compare($outdated[$formula], (string) $v['version'], '>')
                && PhpVersionRepository::branchOf($outdated[$formula]) === PhpVersionRepository::branchOf((string) $v['version'])) {
                $res['updates'][] = $v + ['latest' => $outdated[$formula], 'build' => null];
            } elseif (self::isEol((string) $v['version'])) {
                $res['eol'][] = $v;
            } else {
                $res['current'][] = $v;
            }
        }
        return $res;
    }

    private function cacheFile(): string
    {
        return Paths::in('data', 'cache', 'brew-outdated.json');
    }
}
