<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : ManagerRequirements.php
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
 * File: manager/src/Support/ManagerRequirements.php
 * Purpose: What Nginx Manager itself needs from the PHP version it runs on.
 * Responsibilities:
 *   - MIN_PHP: readonly properties and other 8.1 syntax are used throughout
 *   - EXTENSIONS: mbstring (text helpers), openssl (HTTPS downloads, certificate parsing)
 *   - problems(): blocking problems for a version + its loaded modules (missing extensions
 *     are reported too, but the switch job enables them first - see missingExtensions())
 * Used by: PhpVersionRepository::managerCandidates, scripts/switch-manager-php.ps1 (mirrors
 *          these values: keep them in sync)
 */

declare(strict_types=1);

namespace NginxManager\Support;

final class ManagerRequirements
{
    public const MIN_PHP = '8.1';
    public const EXTENSIONS = ['mbstring', 'openssl'];

    /** @param list<string> $modules loaded module names (any case) */
    public static function missingExtensions(array $modules): array
    {
        $have = array_map('strtolower', $modules);
        return array_values(array_diff(self::EXTENSIONS, $have));
    }

    /**
     * @param list<string> $modules
     * @return list<string> human-readable problems; [] = fine
     */
    public static function problems(string $version, array $modules): array
    {
        if (version_compare($version, self::MIN_PHP, '<')) {
            return ['PHP ' . self::MIN_PHP . " or newer is required (this is $version)."];
        }
        $out = [];
        foreach (self::missingExtensions($modules) as $ext) {
            $out[] = "The $ext extension is not enabled.";
        }
        return $out;
    }

    public static function versionSupported(string $version): bool
    {
        return version_compare($version, self::MIN_PHP, '>=');
    }
}
