<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : PhpCatalog.php
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
 * File: manager/src/Php/PhpCatalog.php
 * Purpose: The "Add PHP Version" list - every installable PHP for Windows version.
 * Responsibilities:
 *   - merge(releases, archives): branch => {
 *         branch, eol (not in the supported release feed), latest,
 *         versions: "x.y.z" => {version, location: releases|archives, builds: {ts|nts: {zip, sha256, compiler}}}
 *     }  - branches and versions newest first
 *   - find(catalog, version, flavour): one installable build or null
 *   - runtimeFor(compiler): which Microsoft Visual C++ runtime that build needs
 * Used by: PhpController (Add PHP Version card + install/update actions)
 */

declare(strict_types=1);

namespace NginxManager\Php;

final class PhpCatalog
{
    public static function merge(array $releases, array $archives): array
    {
        $out = [];
        foreach ($archives as $version => $builds) {
            $branch = PhpVersionRepository::branchOf((string) $version);
            $out[$branch]['versions'][(string) $version] = ['version' => (string) $version, 'location' => 'archives', 'builds' => $builds];
        }
        foreach ($releases as $branch => $r) {
            $out[$branch]['versions'][$r['version']] = ['version' => $r['version'], 'location' => 'releases', 'builds' => $r['builds']];
        }
        foreach ($out as $branch => &$b) {
            uksort($b['versions'], static fn($x, $y) => version_compare($y, $x));
            $b['branch'] = (string) $branch;
            $b['eol'] = !isset($releases[$branch]);
            $b['latest'] = (string) array_key_first($b['versions']);
        }
        unset($b);
        uksort($out, static fn($x, $y) => version_compare($y, $x));
        return $out;
    }

    /** @return array{version:string, location:string, zip:string, sha256:?string, compiler:string}|null */
    public static function find(array $catalog, string $version, string $flavour): ?array
    {
        $branch = PhpVersionRepository::branchOf($version);
        $v = $catalog[$branch]['versions'][$version] ?? null;
        $build = $v['builds'][$flavour] ?? null;
        if ($v === null || $build === null) {
            return null;
        }
        return ['version' => $version, 'location' => $v['location']] + $build;
    }

    /** Human name of the Visual C++ runtime a build needs. */
    public static function runtimeFor(string $compiler): string
    {
        return match (strtolower($compiler)) {
            'vc9'  => 'Visual C++ 2008 (x64)',
            'vc11' => 'Visual C++ 2012 (x64)',
            'vc14' => 'Visual C++ 2015-2022 (x64)',
            default => 'Visual C++ 2015-2022 (x64), latest',
        };
    }
}
