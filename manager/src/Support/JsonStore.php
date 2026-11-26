<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : JsonStore.php
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
 * File: manager/src/Support/JsonStore.php
 * Purpose: Safe read/write of the JSON state files in data/ (settings, groups, jobs, cache).
 * Responsibilities:
 *   - read(): strips a UTF-8 BOM (Windows PowerShell 5.1 can write one), returns a
 *     default for missing files, THROWS for corrupt files (silently returning [] would
 *     let the next write wipe the user's data)
 *   - write(): pretty JSON, no BOM, temp file + rename (atomic on NTFS), serialised
 *     with flock() on "<file>.lock" because the web UI and PowerShell jobs share files
 * Used by: Settings, GroupRepository, JobRunner, PhpReleaseFeed, PeclCatalog
 */

declare(strict_types=1);

namespace NginxManager\Support;

use RuntimeException;

final class JsonStore
{
    public static function read(string $path, array $default = []): array
    {
        if (!is_file($path)) {
            return $default;
        }
        $text = (string) file_get_contents($path);
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        if (trim($text) === '') {
            return $default;
        }
        $data = json_decode($text, true);
        if (!is_array($data)) {
            throw new RuntimeException('Corrupt JSON in ' . basename($path) . ': ' . json_last_error_msg());
        }
        return $data;
    }

    public static function write(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create folder for $path");
        }
        self::locked($path, static fn() => self::replace($path, $data));
    }

    /**
     * Read-modify-write under ONE lock: the mutator gets the file's current contents
     * (by reference), so a PowerShell job's save in between is never lost.
     */
    public static function update(string $path, callable $mutator, array $default = []): array
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create folder for $path");
        }
        return self::locked($path, static function () use ($path, $mutator, $default) {
            $data = self::read($path, $default);
            $mutator($data);
            self::replace($path, $data);
            return $data;
        });
    }

    /** Runs $fn while holding "<file>.lock" (the lock PowerShell's Invoke-WithJsonLock takes too). */
    private static function locked(string $path, callable $fn): mixed
    {
        $lock = fopen($path . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException("Cannot lock $path");
        }
        try {
            flock($lock, LOCK_EX);
            return $fn();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Temp file + rename: atomic on NTFS. Caller holds the lock. */
    private static function replace(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $json . "\n") === false) {
            throw new RuntimeException("Cannot write $path");
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot replace $path");
        }
    }
}
