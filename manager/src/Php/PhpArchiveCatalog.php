<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : PhpArchiveCatalog.php
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
 * File: manager/src/Php/PhpArchiveCatalog.php
 * Purpose: Every OLDER PHP for Windows build, read from the archives/ directory listing
 *          (5.5, 5.6, 7.x, superseded 8.x patches) - needed for legacy projects.
 * Responsibilities:
 *   - versions(refresh): "5.6.40" => {ts: {zip, sha256:null, compiler}, nts: {...}}, newest first
 *     (x64 zips only; x86, .msi and debug/devel packs are ignored)
 *   - cache in data/cache/php-archives.json (default 1 day)
 * Used by: PhpCatalog (merged with PhpReleaseFeed)
 * Notes: php.net publishes no checksums for archived builds - the UI says so, and the
 *        download relies on HTTPS only.
 */

declare(strict_types=1);

namespace NginxManager\Php;

use NginxManager\Support\JsonStore;
use NginxManager\Support\Paths;
use Throwable;

final class PhpArchiveCatalog
{
    public const DEFAULT_URL = 'https://downloads.php.net/~windows/releases/archives/';

    /** @var callable(string): string */
    private $fetch;
    private ?string $error = null;

    public function __construct(
        private readonly string $url = self::DEFAULT_URL,
        ?callable $fetch = null,
        private readonly int $ttl = 86400,
    ) {
        $this->fetch = $fetch ?? new HttpFetcher();
    }

    public function versions(bool $refresh = false): array
    {
        $cache = JsonStore::read($this->cacheFile(), []);
        $fresh = isset($cache['fetchedAt']) && (time() - (int) $cache['fetchedAt']) < $this->ttl;
        if (!$refresh && $fresh && isset($cache['versions'])) {
            return $cache['versions'];
        }
        try {
            $versions = self::parse((string) ($this->fetch)($this->url));
            JsonStore::write($this->cacheFile(), ['fetchedAt' => time(), 'versions' => $versions]);
            return $versions;
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            return $cache['versions'] ?? [];
        }
    }

    public function lastError(): ?string
    {
        return $this->error;
    }

    public static function parse(string $html): array
    {
        preg_match_all('/href="(php-(\d+\.\d+\.\d+)-(nts-)?Win32-((?:vc|vs)\d+)-x64\.zip)"/i', $html, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [$_, $zip, $version, $nts, $compiler]) {
            $out[$version][$nts ? 'nts' : 'ts'] = ['zip' => $zip, 'sha256' => null, 'compiler' => strtolower($compiler)];
        }
        uksort($out, static fn($a, $b) => version_compare($b, $a));
        return $out;
    }

    private function cacheFile(): string
    {
        return Paths::in('data', 'cache', 'php-archives.json');
    }
}
