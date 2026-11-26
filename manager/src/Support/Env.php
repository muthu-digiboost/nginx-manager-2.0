<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : Env.php
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
 * File: manager/src/Support/Env.php
 * Purpose: Reads the machine-specific .env file (never committed).
 * Responsibilities:
 *   - parse KEY=value lines (# comments, optional quotes, no ${VAR} expansion) -
 *     the same rules as Import-DotEnv in scripts/lib/Common.psm1
 *   - give typed access with defaults
 * Used by: Paths (project roots), NginxConfigWriter (ports), PHP/SSL services
 * Notes: state is static so the whole request sees one .env; tests reset it with fromArray().
 */

declare(strict_types=1);

namespace NginxManager\Support;

use RuntimeException;

final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    /** Loads a .env file; throws a friendly message when it does not exist yet. */
    public static function load(string $file): void
    {
        if (!is_file($file)) {
            throw new RuntimeException('No .env found. Copy .env.example to .env and fill it in.');
        }
        self::$values = self::parse((string) file_get_contents($file));
    }

    /** @return array<string, string> */
    public static function parse(string $text): array
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        $values = [];
        foreach (preg_split("/\r?\n/", $text) ?: [] as $raw) {
            $line = trim($raw);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false || $eq < 1) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $values[$key] = $value;
        }
        return $values;
    }

    /** Replaces all values (tests). */
    public static function fromArray(array $values): void
    {
        self::$values = array_map('strval', $values);
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $v = self::$values[$key] ?? null;
        return ($v === null || $v === '') ? $default : $v;
    }

    public static function int(string $key, int $default): int
    {
        $v = self::get($key);
        return ($v !== null && ctype_digit($v)) ? (int) $v : $default;
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        return self::$values;
    }
}
