<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : PhpPaths.php
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
 * File: manager/src/Php/PhpPaths.php
 * Purpose: Where the files of one installed PHP version are.
 * Responsibilities:
 *   - Windows: everything inside {base}/php/<id>/ (php.exe, php-cgi.exe, php.ini, ext/)
 *   - macOS:   the Homebrew prefix stored in the settings entry ("prefix", e.g.
 *              /opt/homebrew/opt/php@8.4): bin/php, bin/php-cgi; php.ini from "ini" or
 *              <brew root>/etc/php/<major.minor>/php.ini; extension dir from "extDir"
 *              (PHP's own extension_dir, recorded when the version is registered)
 *   - extension file naming: php_<name>.dll (Windows) / <name>.so (macOS)
 * Used by: PhpVersionRepository, ExtensionManager, Mac services, bin/manager.php
 * Notes: settings.json is machine data (gitignored), so absolute paths are fine there.
 */

declare(strict_types=1);

namespace NginxManager\Php;

use NginxManager\Support\Paths;
use NginxManager\Support\Platform;

final class PhpPaths
{
    public function __construct(
        public readonly string $dir,
        public readonly string $php,
        public readonly string $cgi,
        public readonly string $ini,
        public readonly string $extDir,
    ) {}

    /** @param array<string, mixed> $entry a settings.json php.versions entry */
    public static function forEntry(array $entry): self
    {
        $id = (string) ($entry['id'] ?? '');
        if (Platform::isWindows()) {
            $dir = Paths::in('php', $id);
            return new self($dir, "$dir/php.exe", "$dir/php-cgi.exe", "$dir/php.ini", "$dir/ext");
        }
        $prefix = rtrim((string) ($entry['prefix'] ?? ''), '/');
        if ($prefix === '') {
            $prefix = Paths::in('php', $id);   // a php/<id> folder or symlink also works
        }
        $ini = (string) ($entry['ini'] ?? '');
        if ($ini === '') {
            $brewRoot = dirname($prefix, 2);   // /opt/homebrew/opt/php@8.4 -> /opt/homebrew
            $ini = $brewRoot . '/etc/php/' . PhpVersionRepository::branchOf($id) . '/php.ini';
        }
        return new self($prefix, "$prefix/bin/php", "$prefix/bin/php-cgi", $ini, (string) ($entry['extDir'] ?? ''));
    }

    /** glob() pattern of extension files inside extDir */
    public static function extensionGlob(): string
    {
        return Platform::isWindows() ? 'php_*.dll' : '*.so';
    }
}
