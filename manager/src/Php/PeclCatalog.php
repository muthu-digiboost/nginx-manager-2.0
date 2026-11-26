<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : PeclCatalog.php
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
 * File: manager/src/Php/PeclCatalog.php
 * Purpose: Finds Windows builds of PECL extensions (redis, xdebug, apcu, imagick, ...) on
 *          https://downloads.php.net/~windows/pecl/releases/<ext>/<version>/
 * Responsibilities:
 *   - versions(ext): stable versions (x.y.z), newest first, from the directory listing
 *   - findBuild(ext, branch, ts|nts): walks versions newest -> oldest (max 15) and returns
 *     the first with a matching x64 zip: {ext, version, zip, url} - or null
 *   - popular(): a short list offered in the UI
 *   - listings are cached in data/cache/pecl-<ext>.json (default 1 day)
 * Used by: ExtensionsController
 * Notes: php.net publishes no checksums for PECL builds; the download relies on HTTPS.
 */

declare(strict_types=1);

namespace NginxManager\Php;

use InvalidArgumentException;
use NginxManager\Support\JsonStore;
use NginxManager\Support\Paths;
use Throwable;

final class PeclCatalog
{
    public const DEFAULT_URL = 'https://downloads.php.net/~windows/pecl/releases/';
    public const POPULAR = ['apcu', 'igbinary', 'imagick', 'mailparse', 'memcache', 'mongodb', 'oci8',
        'pdo_sqlsrv', 'redis', 'sqlsrv', 'ssh2', 'xdebug', 'yaml'];

    /** @var callable(string): string */
    private $fetch;

    public function __construct(
        private readonly string $baseUrl = self::DEFAULT_URL,
        ?callable $fetch = null,
        private readonly int $ttl = 86400,
    ) {
        $this->fetch = $fetch ?? new HttpFetcher();
    }

    /** @return list<string> */
    public function versions(string $ext): array
    {
        $html = $this->listing($ext, '');
        preg_match_all('#href="(?:[^"]*/)?(\d+\.\d+\.\d+)/"#', $html, $m);
        $versions = array_values(array_unique($m[1]));
        usort($versions, static fn($a, $b) => version_compare($b, $a));
        return $versions;
    }

    /** @return array{ext:string, version:string, zip:string, url:string}|null */
    public function findBuild(string $ext, string $branch, string $ts): ?array
    {
        $this->assertName($ext);
        if (!preg_match('/^\d+\.\d+$/', $branch)) {
            throw new InvalidArgumentException('Invalid PHP branch.');
        }
        $ts = $ts === 'nts' ? 'nts' : 'ts';
        foreach (array_slice($this->versions($ext), 0, 15) as $version) {
            $re = '/(php_' . preg_quote($ext, '/') . '-' . preg_quote($version, '/') . '-' . preg_quote($branch, '/')
                . '-' . $ts . '-(?:vc|vs)\d+-x64\.zip)/i';
            if (preg_match($re, $this->listing($ext, $version), $m)) {
                return [
                    'ext'     => $ext,
                    'version' => $version,
                    'zip'     => $m[1],
                    'url'     => rtrim($this->baseUrl, '/') . "/$ext/$version/" . $m[1],
                ];
            }
        }
        return null;
    }

    /** @return list<string> */
    public function popular(): array
    {
        return self::POPULAR;
    }

    private function listing(string $ext, string $version): string
    {
        $this->assertName($ext);
        $cacheFile = Paths::in('data', 'cache', 'pecl-' . $ext . '.json');
        $cache = JsonStore::read($cacheFile, []);
        $key = $version === '' ? '_index' : $version;
        if (isset($cache[$key]) && (time() - (int) $cache[$key]['at']) < $this->ttl) {
            return (string) $cache[$key]['html'];
        }
        try {
            $html = (string) ($this->fetch)(rtrim($this->baseUrl, '/') . "/$ext/" . ($version === '' ? '' : "$version/"));
        } catch (Throwable) {
            return (string) ($cache[$key]['html'] ?? '');
        }
        if ($this->ttl > 0) {
            $cache[$key] = ['at' => time(), 'html' => $html];
            JsonStore::write($cacheFile, $cache);
        }
        return $html;
    }

    private function assertName(string $ext): void
    {
        if (!preg_match('/^[a-z0-9_]{2,40}$/', $ext)) {
            throw new InvalidArgumentException('Extension names use lower-case letters, digits and "_" only.');
        }
    }
}
