<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : SiteRepository.php
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
 * File: manager/src/Nginx/SiteRepository.php
 * Purpose: All file operations on conf/sites, with the SAFE WRITE contract:
 *            backup -> write -> nginx -t -> restore the previous bytes if the test fails
 *          so a mistake in the UI can never leave nginx with a broken configuration.
 * Responsibilities:
 *   - all() / find(id) / read(id)           list & read (ids validated, never paths)
 *   - create(SiteSpec)                      new file; removed again if nginx -t fails
 *   - save(id, content)                     safe write
 *   - setEnabled(id, bool)                  rename .conf <-> .conf.disabled (enable is tested)
 *   - delete(id)                            moves the file to backups/deleted/
 *   - backups(id) / backupContent(id, name) history kept in backups/sites/<id>/
 * Used by: SitesController, SslController, DashboardController, GroupsController
 * Notes: reload is NOT done here - controllers reload once after all changes.
 */

declare(strict_types=1);

namespace NginxManager\Nginx;

use NginxManager\Support\Paths;
use NginxManager\Support\ShellResult;
use NginxManager\Support\Validator;
use RuntimeException;

final class SiteRepository
{
    public function __construct(private readonly NginxControl $nginx) {}

    /** "*.example.test" -> "wildcard.example.test" ('*' is not allowed in Windows file names). */
    public static function idFor(string $domain): string
    {
        return SiteConfigBuilder::logLabel(strtolower($domain));
    }

    /** @return list<Site> sorted by id */
    public function all(): array
    {
        $sites = [];
        foreach (glob($this->dir() . '/*') ?: [] as $file) {
            $name = basename($file);
            if (str_starts_with($name, '_') || !is_file($file)) {
                continue;
            }
            if (preg_match('/^(.+)\.conf(\.disabled)?$/', $name, $m) && Validator::siteId($m[1])) {
                $sites[] = $this->load($m[1], $file, !isset($m[2]));
            }
        }
        usort($sites, static fn(Site $a, Site $b) => strcmp($a->id, $b->id));
        return $sites;
    }

    public function find(string $id): ?Site
    {
        $file = $this->fileFor($id);
        return $file === null ? null : $this->load($id, $file, !str_ends_with($file, '.disabled'));
    }

    public function read(string $id): string
    {
        return (string) file_get_contents($this->existing($id));
    }

    public function create(SiteSpec $spec): ShellResult
    {
        $id = self::idFor($spec->domain);
        if (!Validator::siteId($id)) {
            throw new RuntimeException("Invalid domain: {$spec->domain}");
        }
        if ($this->fileFor($id) !== null) {
            throw new RuntimeException("A site config for {$spec->domain} already exists.");
        }
        $file = $this->dir() . '/' . $id . '.conf';
        $this->ensureDir($this->dir());
        $this->writeFile($file, SiteConfigBuilder::build($spec));

        $test = $this->nginx->test();
        if (!$test->ok()) {
            @unlink($file);
        }
        return $test;
    }

    public function save(string $id, string $content): ShellResult
    {
        $file = $this->existing($id);
        $previous = (string) file_get_contents($file);
        $this->backup($id, $previous);
        $this->writeFile($file, $content);

        $test = $this->nginx->test();
        if (!$test->ok()) {
            $this->writeFile($file, $previous);
        }
        return $test;
    }

    public function setEnabled(string $id, bool $enabled): ShellResult
    {
        $file = $this->existing($id);
        $target = $this->dir() . '/' . $id . ($enabled ? '.conf' : '.conf.disabled');
        if ($file === $target) {
            return new ShellResult(0, 'unchanged');
        }
        if (!rename($file, $target)) {
            throw new RuntimeException("Could not rename $id");
        }
        if ($enabled) {
            $test = $this->nginx->test();
            if (!$test->ok()) {
                rename($target, $file);
            }
            return $test;
        }
        return new ShellResult(0, 'disabled');
    }

    public function delete(string $id): void
    {
        $file = $this->existing($id);
        $dir = Paths::in('backups', 'deleted');
        $this->ensureDir($dir);
        if (!rename($file, $dir . '/' . $id . '-' . date('Ymd-His') . '.conf')) {
            throw new RuntimeException("Could not delete $id");
        }
    }

    /** @return list<string> backup file names, newest first */
    public function backups(string $id): array
    {
        if (!Validator::siteId($id)) {
            return [];
        }
        $files = array_map('basename', glob(Paths::in('backups', 'sites', $id) . '/*.conf') ?: []);
        rsort($files);
        return $files;
    }

    public function backupContent(string $id, string $name): string
    {
        if (!Validator::siteId($id) || !preg_match('/^\d{8}-\d{6}(-\d+)?\.conf$/', $name)) {
            throw new RuntimeException('Invalid backup name.');
        }
        $file = Paths::in('backups', 'sites', $id, $name);
        if (!is_file($file)) {
            throw new RuntimeException('Backup not found.');
        }
        return (string) file_get_contents($file);
    }

    // -------------------------------------------------------------------------

    private function dir(): string
    {
        return Paths::in('conf', 'sites');
    }

    private function fileFor(string $id): ?string
    {
        if (!Validator::siteId($id)) {
            return null;
        }
        foreach (['.conf', '.conf.disabled'] as $ext) {
            $file = $this->dir() . '/' . $id . $ext;
            if (is_file($file)) {
                return $file;
            }
        }
        return null;
    }

    private function existing(string $id): string
    {
        $file = $this->fileFor($id);
        if ($file === null) {
            throw new RuntimeException("Site not found: $id");
        }
        return $file;
    }

    private function load(string $id, string $file, bool $enabled): Site
    {
        return new Site($id, $file, $enabled, SiteConfigParser::parse((string) file_get_contents($file)));
    }

    private function backup(string $id, string $content): void
    {
        $dir = Paths::in('backups', 'sites', $id);
        $this->ensureDir($dir);
        $name = date('Ymd-His');
        $n = 0;
        while (is_file("$dir/$name" . ($n ? "-$n" : '') . '.conf')) {
            $n++;
        }
        $this->writeFile("$dir/$name" . ($n ? "-$n" : '') . '.conf', $content);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create $dir");
        }
    }

    private function writeFile(string $file, string $content): void
    {
        if (file_put_contents($file, $content) === false) {
            throw new RuntimeException('Cannot write ' . basename($file) . ' - is the manager running as Administrator?');
        }
    }
}
