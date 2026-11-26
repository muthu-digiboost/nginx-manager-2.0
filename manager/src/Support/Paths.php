<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : Paths.php
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
 * File: manager/src/Support/Paths.php
 * Purpose: The ONLY place file-system paths are built. Nothing in the project stores an
 *          absolute path: the install folder is computed at runtime and project folders
 *          come from .env.
 * Responsibilities:
 *   - base()               install folder ({base}), forward slashes
 *   - in(...)              path under {base}
 *   - projectRoots()       PROJECTS_DIR / PROJECTS_DIR_<X>  ->  nginx variable names
 *   - toProjectRelative()  absolute folder -> "$projects_dir[_x]/..." (what site configs store)
 *   - resolveRoot()        nginx root -> absolute (display, "folder exists?", log reading)
 *   - hostsFile()          %SystemRoot%\System32\drivers\etc\hosts
 * Used by: everything that touches files
 * Notes: overrideBase() exists for tests only.
 */

declare(strict_types=1);

namespace NginxManager\Support;

final class Paths
{
    private static ?string $base = null;

    /** Install folder: manager/src/Support -> three levels up. */
    public static function base(): string
    {
        return self::$base ?? self::normalise(dirname(__DIR__, 3));
    }

    /** Tests point the base at a temp folder; null restores the real one. */
    public static function overrideBase(?string $dir): void
    {
        self::$base = $dir === null ? null : self::normalise($dir);
    }

    public static function in(string ...$parts): string
    {
        $tail = implode('/', array_map(static fn($p) => trim(str_replace('\\', '/', $p), '/'), $parts));
        return self::base() . ($tail === '' ? '' : '/' . $tail);
    }

    /** @return array<string, string> nginx variable name (without $) => folder */
    public static function projectRoots(): array
    {
        $roots = [];
        foreach (Env::all() as $key => $value) {
            if (preg_match('/^PROJECTS_DIR(_[A-Z0-9]+)?$/', $key) && trim($value) !== '') {
                $roots[strtolower($key)] = self::normalise(trim($value));
            }
        }
        uksort($roots, static fn($a, $b) => [strlen($a), $a] <=> [strlen($b), $b]);
        return $roots;
    }

    /**
     * "Q:\src\app\public" -> "$projects_dir/app/public" using the longest matching
     * project root; null when the folder is outside every root.
     */
    public static function toProjectRelative(string $absolute): ?string
    {
        $path = self::normalise($absolute);
        $best = null;
        $bestLen = -1;
        foreach (self::projectRoots() as $var => $root) {
            $len = strlen($root);
            if ($len <= $bestLen) {
                continue;
            }
            if (strcasecmp($path, $root) === 0) {
                [$best, $bestLen] = ['$' . $var, $len];
            } elseif (stripos($path, $root . '/') === 0) {
                [$best, $bestLen] = ['$' . $var . substr($path, $len), $len];
            }
        }
        return $best;
    }

    /** nginx root value -> absolute folder (never written back into configs). */
    public static function resolveRoot(string $nginxRoot): string
    {
        $value = trim($nginxRoot, " \t\"'");
        if (preg_match('/^\$(projects_dir(?:_[a-z0-9]+)?)(\/.*)?$/', $value, $m)) {
            $roots = self::projectRoots();
            return ($roots[$m[1]] ?? '$' . $m[1]) . ($m[2] ?? '');
        }
        if (Validator::absolutePath($value)) {
            return self::normalise($value);
        }
        return self::in($value);   // prefix-relative, like nginx -p {base}/
    }

    public static function hostsFile(): string
    {
        if (!Platform::isWindows()) {
            return '/etc/hosts';
        }
        $system = getenv('SystemRoot') ?: (string) getenv('windir');   // always set on Windows
        return self::normalise($system) . '/System32/drivers/etc/hosts';
    }

    public static function normalise(string $path): string
    {
        $p = str_replace('\\', '/', $path);
        return strlen($p) > 3 ? rtrim($p, '/') : $p;
    }
}
