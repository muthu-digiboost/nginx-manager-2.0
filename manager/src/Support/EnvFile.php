<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : EnvFile.php
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
 * File: manager/src/Support/EnvFile.php
 * Purpose: Writes the machine-specific .env file for the Settings page, so nobody has to
 *          open it by hand.
 * Responsibilities:
 *   - ensure(env, example): create .env from .env.example on first run
 *   - set(file, [KEY => value|null]): change values in place (comments and order kept),
 *     append new keys, remove keys whose value is null; quote values with spaces
 *   - refuse keys/values that could break the file format (newlines, quotes)
 * Used by: SettingsController, bootstrap.php (first run)
 * Notes: the same format is read by Env (PHP) and Import-DotEnv (PowerShell).
 */

declare(strict_types=1);

namespace NginxManager\Support;

use InvalidArgumentException;
use RuntimeException;

final class EnvFile
{
    /** @return bool true when .env was created now */
    public static function ensure(string $envFile, string $exampleFile): bool
    {
        if (is_file($envFile) || !is_file($exampleFile)) {
            return false;
        }
        if (!@copy($exampleFile, $envFile)) {
            throw new RuntimeException('Could not create the settings file (.env).');
        }
        return true;
    }

    /** @param array<string, ?string> $changes */
    public static function set(string $file, array $changes): void
    {
        foreach ($changes as $key => $value) {
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', (string) $key)) {
                throw new InvalidArgumentException("Invalid setting name: $key");
            }
            if ($value !== null && preg_match('/["\r\n]/', $value)) {
                throw new InvalidArgumentException("Invalid characters in the value for $key.");
            }
        }

        $text = is_file($file) ? (string) file_get_contents($file) : '';
        $eol = str_contains($text, "\r\n") ? "\r\n" : "\n";
        $lines = $text === '' ? [] : preg_split('/\r?\n/', rtrim($text, "\r\n"));
        $done = [];
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=/', $line, $m) && array_key_exists($m[1], $changes)) {
                $done[$m[1]] = true;
                if ($changes[$m[1]] !== null) {
                    $out[] = $m[1] . '=' . self::format($changes[$m[1]]);
                }
                continue;
            }
            $out[] = $line;
        }
        foreach ($changes as $key => $value) {
            if (!isset($done[$key]) && $value !== null) {
                $out[] = $key . '=' . self::format($value);
            }
        }
        if (file_put_contents($file, implode($eol, $out) . $eol) === false) {
            throw new RuntimeException('Could not save the settings file (.env).');
        }
    }

    private static function format(string $value): string
    {
        $value = trim($value);
        return preg_match('/\s|#/', $value) ? '"' . $value . '"' : $value;
    }
}
