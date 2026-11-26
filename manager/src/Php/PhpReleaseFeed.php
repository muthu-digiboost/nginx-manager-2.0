<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : PhpReleaseFeed.php
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
 * File: manager/src/Php/PhpReleaseFeed.php
 * Purpose: The official PHP for Windows release feed (releases.json): the newest patch of
 *          every SUPPORTED branch, with its zip name and SHA-256 per build.
 * Responsibilities:
 *   - releases(refresh): branch => {branch, version, builds: {ts|nts: {zip, sha256, compiler}}}
 *     newest branch first; only x64 builds (ts-vs16-x64, nts-vc15-x64, ...)
 *   - cache in data/cache/php-releases.json (default 6 h); on a network error the stale
 *     cache is returned and lastError() says why
 *   - cachedOnly(): never touches the network (sidebar badge, dashboard)
 * Used by: PhpController, UpdateChecker, PhpCatalog, layout badge
 */

declare(strict_types=1);

namespace NginxManager\Php;

use NginxManager\Support\JsonStore;
use NginxManager\Support\Paths;
use Throwable;

final class PhpReleaseFeed
{
    public const DEFAULT_URL = 'https://downloads.php.net/~windows/releases/releases.json';

    /** @var callable(string): string */
    private $fetch;
    private ?string $error = null;

    public function __construct(
        private readonly string $url = self::DEFAULT_URL,
        ?callable $fetch = null,
        private readonly int $ttl = 21600,
    ) {
        $this->fetch = $fetch ?? new HttpFetcher();
    }

    public function releases(bool $refresh = false): array
    {
        $cache = JsonStore::read($this->cacheFile(), []);
        $fresh = isset($cache['fetchedAt']) && (time() - (int) $cache['fetchedAt']) < $this->ttl;
        if (!$refresh && $fresh && isset($cache['releases'])) {
            return $cache['releases'];
        }
        try {
            $releases = self::parse((string) ($this->fetch)($this->url));
            JsonStore::write($this->cacheFile(), ['fetchedAt' => time(), 'releases' => $releases]);
            return $releases;
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            return $cache['releases'] ?? [];
        }
    }

    public function cachedOnly(): array
    {
        return JsonStore::read($this->cacheFile(), [])['releases'] ?? [];
    }

    public function checkedAt(): ?int
    {
        $at = JsonStore::read($this->cacheFile(), [])['fetchedAt'] ?? null;
        return $at === null ? null : (int) $at;
    }

    public function lastError(): ?string
    {
        return $this->error;
    }

    public static function parse(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException('The PHP release feed is not valid JSON.');
        }
        $out = [];
        foreach ($data as $branch => $info) {
            if (!preg_match('/^\d+\.\d+$/', (string) $branch) || !is_array($info) || empty($info['version'])) {
                continue;
            }
            $builds = [];
            foreach ($info as $key => $build) {
                if (preg_match('/^(n?ts)-(v[cs]\d+)-x64$/', (string) $key, $m) && isset($build['zip']['path'])) {
                    $builds[$m[1]] = [
                        'zip'      => (string) $build['zip']['path'],
                        'sha256'   => isset($build['zip']['sha256']) ? strtolower((string) $build['zip']['sha256']) : null,
                        'compiler' => strtolower($m[2]),
                    ];
                }
            }
            if ($builds) {
                $out[(string) $branch] = ['branch' => (string) $branch, 'version' => (string) $info['version'], 'builds' => $builds];
            }
        }
        uksort($out, static fn($a, $b) => version_compare($b, $a));
        return $out;
    }

    private function cacheFile(): string
    {
        return Paths::in('data', 'cache', 'php-releases.json');
    }
}
