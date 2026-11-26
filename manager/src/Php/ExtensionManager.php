<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : ExtensionManager.php
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
 * File: manager/src/Php/ExtensionManager.php
 * Purpose: Lists and switches the extensions of one installed PHP version.
 * Responsibilities:
 *   - list(phpId): every extension that has a DLL in php\<id>\ext or a line in php.ini:
 *       {name, available (DLL present), enabled (active ini line), zend, loaded (php -m)}
 *     "enabled but not loaded" means PHP could not load it - shown as broken in the UI
 *   - setEnabled(phpId, [name => bool]):
 *       backup php.ini -> write -> check "php -c php.ini -v" -> on a load warning restore
 *       the previous php.ini and return PHP's message; otherwise restart that PHP (if it
 *       is running) as a background job so the change takes effect
 * Used by: ExtensionsController, bin/manager.php ext:enable (called by install-extension.ps1)
 */

declare(strict_types=1);

namespace NginxManager\Php;

use InvalidArgumentException;
use NginxManager\Support\Paths;
use NginxManager\Support\Platform;
use NginxManager\Support\Settings;
use NginxManager\Support\Shell;
use RuntimeException;

final class ExtensionManager
{
    /** Zend extensions (loaded with zend_extension=) shipped with PHP or common on Windows. */
    private const ZEND = ['opcache', 'xdebug'];

    /** Extensions PHP ships only for its own test suite. */
    private const HIDDEN = ['dl_test', 'zend_test'];

    /** Parts of PHP itself that "php -m" lists but can never be switched off (macOS list). */
    private const CORE = ['core', 'date', 'hash', 'json', 'pcre', 'random', 'reflection', 'spl', 'standard'];

    /** @var callable(int): bool */
    private $isRunning;

    public function __construct(
        private readonly Settings $settings,
        private readonly Shell $shell,
        private readonly JobRunner $jobs,
        ?callable $isRunning = null,
    ) {
        $this->isRunning = $isRunning ?? [PhpVersionRepository::class, 'isListening'];
    }

    /** @return list<array{name:string, available:bool, enabled:bool, zend:bool, loaded:bool, builtin:bool}> */
    public function list(string $phpId): array
    {
        $paths = $this->paths($phpId);
        $ini = [];   // every ini file PHP reads (macOS: php.ini + conf.d/*.ini); "enabled" anywhere wins
        foreach (self::iniFiles($paths) as $file) {
            foreach (PhpIni::fromFile($file)->extensions() as $name => $x) {
                $ini[$name] = [
                    'enabled' => ($ini[$name]['enabled'] ?? false) || $x['enabled'],
                    'zend'    => ($ini[$name]['zend'] ?? false) || $x['zend'],
                    'file'    => ($ini[$name]['file'] ?? '') ?: $x['file'],
                ];
            }
        }

        $dlls = [];   // extension files: php_<name>.dll (Windows) / <name>.so (macOS)
        foreach (($paths->extDir !== '' ? glob($paths->extDir . '/' . PhpPaths::extensionGlob()) : []) ?: [] as $file) {
            $dlls[PhpIni::normalise(basename($file))] = true;
        }
        if (!Platform::isWindows()) {
            // Homebrew points some ini lines at a full path outside extension_dir
            // (opcache, shivammathur/extensions): the file exists = the extension is available.
            foreach ($ini as $name => $x) {
                if (str_contains($x['file'], '/') && is_file($x['file'])) {
                    $dlls[$name] = true;
                }
            }
        }
        $loaded = $this->loadedModules($paths);

        $out = [];
        foreach (array_unique(array_merge(array_keys($dlls), array_keys($ini))) as $name) {
            $enabled = $ini[$name]['enabled'] ?? false;
            // Hidden: PHP's own test extensions, and commented sample lines of php.ini
            // ("extension=modulename", "mysqli.so") that have no DLL behind them.
            if (in_array($name, self::HIDDEN, true) || (!isset($dlls[$name]) && !$enabled)) {
                continue;
            }
            $out[] = [
                'name'      => $name,
                'available' => isset($dlls[$name]),
                'enabled'   => $enabled,
                'zend'      => ($ini[$name]['zend'] ?? false) || in_array($name, self::ZEND, true),
                'loaded'    => isset($loaded[$name]) || isset($loaded[(string) preg_replace('/_\d+[a-z]*$/', '', $name)]),
                'builtin'   => false,
            ];
        }
        if (!Platform::isWindows()) {
            // Homebrew compiles most extensions into PHP itself: always on, nothing to switch.
            $listed = array_column($out, 'name');
            foreach (array_keys($loaded) as $name) {
                if (!in_array($name, $listed, true) && !in_array($name, self::CORE, true)) {
                    $out[] = ['name' => $name, 'available' => false, 'enabled' => true,
                              'zend' => false, 'loaded' => true, 'builtin' => true];
                }
            }
        }
        usort($out, static fn($a, $b) => strcmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * @param array<string, bool> $changes
     * @return array{ok:bool, output:string, jobId:?string}
     */
    public function setEnabled(string $phpId, array $changes): array
    {
        $paths = $this->paths($phpId);
        $iniFile = $paths->ini;
        $zendNames = [];
        foreach ($this->list($phpId) as $e) {
            if ($e['zend']) {
                $zendNames[$e['name']] = true;
            }
        }

        // One PhpIni per file PHP reads; each change goes to the file that already mentions the
        // extension (macOS: Homebrew's conf.d/ext-<name>.ini), otherwise to php.ini.
        $version = isset($this->settings->phpVersion($phpId)['version']) ? (string) $this->settings->phpVersion($phpId)['version'] : null;
        $previous = [];
        $inis = [];
        foreach (self::iniFiles($paths) as $file) {
            $previous[$file] = is_file($file) ? (string) file_get_contents($file) : '';
            $inis[$file] = new PhpIni($previous[$file], $version);
        }
        foreach ($changes as $name => $on) {
            if (!is_string($name) || !preg_match('/^[a-z0-9_]{2,40}$/', $name)) {
                throw new InvalidArgumentException('Invalid extension name.');
            }
            $target = $iniFile;
            foreach ($inis as $file => $ini) {
                if ($file !== $iniFile && isset($ini->extensions()[$name])) {
                    $target = $file;
                    break;
                }
            }
            $inis[$target]->setEnabled($name, (bool) $on, isset($zendNames[$name]) || in_array($name, self::ZEND, true));
        }
        $changed = array_filter(array_keys($inis), static fn($f) => $inis[$f]->text() !== $previous[$f]);
        if (!$changed) {
            return ['ok' => true, 'output' => 'No changes.', 'jobId' => null];
        }

        foreach ($changed as $file) {
            $this->backup($phpId, $previous[$file]);
            if (file_put_contents($file, $inis[$file]->text()) === false) {
                foreach ($changed as $undo) {
                    file_put_contents($undo, $previous[$undo]);
                }
                throw new RuntimeException('Could not write ' . $file . (Platform::isWindows() ? ' - is the manager running as Administrator?' : ''));
            }
        }

        $check = $this->shell->run([$paths->php, '-c', $iniFile, '-v'], $paths->dir, 30);
        if (!$check->ok() || preg_match('/Warning|Unable to load|Fatal error/i', $check->output)) {
            foreach ($changed as $file) {
                file_put_contents($file, $previous[$file]);
            }
            return ['ok' => false, 'output' => $check->output, 'jobId' => null];
        }

        $version = $this->settings->phpVersion($phpId);
        $jobId = null;
        if (($this->isRunning)((int) $version['port'])) {
            $jobId = $this->jobs->start('php-service.ps1', ['Action' => 'restart', 'Id' => $phpId], "Restart PHP $phpId (extensions changed)");
        }
        return ['ok' => true, 'output' => $check->output, 'jobId' => $jobId];
    }

    // -------------------------------------------------------------------------

    /**
     * The ini files PHP reads: php.ini, plus on macOS the conf.d folder next to it where
     * Homebrew's extension formulas put ext-<name>.ini.
     * @return list<string>
     */
    private static function iniFiles(PhpPaths $paths): array
    {
        $files = [$paths->ini];
        if (!Platform::isWindows()) {
            $extra = glob(dirname($paths->ini) . '/conf.d/*.ini') ?: [];
            sort($extra);
            array_push($files, ...$extra);
        }
        return $files;
    }

    private function paths(string $phpId): PhpPaths
    {
        $entry = $this->settings->phpVersion($phpId);
        if ($entry === null) {
            throw new InvalidArgumentException("Unknown PHP version: $phpId");
        }
        return PhpPaths::forEntry($entry);
    }

    /** @return array<string, true> lower-case module names reported by "php -m" */
    private function loadedModules(PhpPaths $paths): array
    {
        $out = $this->shell->run([$paths->php, '-c', $paths->ini, '-m'], $paths->dir, 30)->output;
        $mods = [];
        foreach (preg_split('/\r?\n/', $out) ?: [] as $line) {
            $line = strtolower(trim($line));
            if ($line === '' || $line[0] === '[') {
                continue;
            }
            $mods[(string) preg_replace('/^zend /', '', $line)] = true;
        }
        return $mods;
    }

    private function backup(string $phpId, string $content): void
    {
        $dir = Paths::in('backups', 'php-ini', $phpId);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4) . '.ini', $content);
    }
}
