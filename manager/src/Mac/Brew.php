<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : Brew.php
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
 * File: manager/src/Mac/Brew.php
 * Purpose: Homebrew, the source of nginx, PHP and PHP extensions on macOS.
 * Responsibilities:
 *   - root(): /opt/homebrew (Apple silicon) or /usr/local (Intel), or BREW_PREFIX from .env
 *   - run(): brew with its full path (background jobs may not have it on PATH)
 *   - installedPhp(): branch => formula for every installed php / php@X.Y
 *     (shivammathur/php tap: php@5.6 ... php@8.5; the plain "php" formula is the newest)
 *   - describe(formula): prefix, exact version, loaded php.ini, extension_dir
 * Used by: Mac\Server, Mac\PhpInstallJob, Mac\ExtensionInstallJob, Mac\PhpCatalog
 */

declare(strict_types=1);

namespace NginxManager\Mac;

use NginxManager\Support\Env;
use NginxManager\Support\Shell;
use NginxManager\Support\ShellResult;

final class Brew
{
    public const PHP_TAP = 'shivammathur/php';
    public const EXT_TAP = 'shivammathur/extensions';

    private readonly string $root;

    public function __construct(private readonly Shell $shell, ?string $root = null)
    {
        $this->root = rtrim($root ?? self::detectRoot(), '/');
    }

    public static function detectRoot(): string
    {
        $set = (string) Env::get('BREW_PREFIX', '');
        if ($set !== '') {
            return $set;
        }
        return is_dir('/opt/homebrew') ? '/opt/homebrew' : '/usr/local';
    }

    public function root(): string
    {
        return $this->root;
    }

    public function binary(): string
    {
        return $this->root . '/bin/brew';
    }

    /** @param list<string> $args */
    public function run(array $args, int $timeout = 1800): ShellResult
    {
        return $this->shell->run(array_merge([$this->binary()], $args), null, $timeout);
    }

    /** "/opt/homebrew/opt/php@8.4" - the stable path of a formula (survives upgrades). */
    public function optPrefix(string $formula): string
    {
        return $this->root . '/opt/' . $formula;
    }

    /** @return array<string, string> branch ("8.4") => formula ("php@8.4"), oldest first */
    public function installedPhp(): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $this->run(['list', '--formula', '-1'], 60)->output) ?: [] as $line) {
            $formula = basename(trim($line));   // "shivammathur/php/php@5.6" -> "php@5.6"
            if (preg_match('/^php@(\d+\.\d+)$/', $formula, $m)) {
                $out[$m[1]] = $formula;
            } elseif ($formula === 'php') {
                $branch = self::markers($this->shell->run([$this->optPrefix('php') . '/bin/php', '-r',
                    'echo "NMB=", PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION, "\n";'], null, 30)->output)['NMB'] ?? '';
                if (preg_match('/^\d+\.\d+$/', $branch) && !isset($out[$branch])) {
                    $out[$branch] = 'php';
                }
            }
        }
        uksort($out, 'version_compare');
        return $out;
    }

    /**
     * The shivammathur/extensions formula of an extension for a PHP branch (e.g. redis@8.4).
     * @return array{ext:string, version:string, formula:string}|null  null = not offered
     */
    public function findExtension(string $ext, string $branch): ?array
    {
        if (!preg_match('/^[a-z0-9_]{2,40}$/', $ext) || !preg_match('/^\d+\.\d+$/', $branch)) {
            throw new \InvalidArgumentException('Invalid extension name.');
        }
        $formula = self::EXT_TAP . "/$ext@$branch";
        $r = $this->run(['info', '--json=v2', $formula], 120);
        $json = $r->ok() ? self::json($r->output) : null;
        $f = is_array($json) ? ($json['formulae'][0] ?? null) : null;
        if (!is_array($f)) {
            return null;
        }
        return ['ext' => $ext, 'version' => (string) ($f['versions']['stable'] ?? ''), 'formula' => $formula];
    }

    /** @return array{prefix:string, version:string, ini:string, extDir:string} */
    public function describe(string $formula): array
    {
        $prefix = $this->optPrefix($formula);
        $r = $this->shell->run([$prefix . '/bin/php', '-r',
            'echo "NMV=", PHP_VERSION, "\nNMI=", (string) php_ini_loaded_file(), "\nNME=", ini_get("extension_dir"), "\n";'], null, 30);
        $v = self::markers($r->output);
        return [
            'prefix'  => $prefix,
            'version' => $v['NMV'] ?? '',
            'ini'     => $v['NMI'] ?? '',
            'extDir'  => $v['NME'] ?? '',
        ];
    }

    /**
     * "NMX=value" lines only: PHP startup warnings (Homebrew's php.ini shows errors) may be
     * printed before them.
     * @return array<string, string>
     */
    public static function markers(string $output): array
    {
        $out = [];
        if (preg_match_all('/^(NM[A-Z])=(.*)$/m', $output, $m, PREG_SET_ORDER)) {
            foreach ($m as [, $key, $value]) {
                $out[$key] = trim($value);
            }
        }
        return $out;
    }

    /**
     * The JSON document in brew's output. Shell merges stderr, so warnings or "==> Tapping"
     * lines may come first.
     */
    public static function json(string $output): ?array
    {
        $start = strpos($output, '{');
        $end = strrpos($output, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $json = json_decode(substr($output, $start, $end - $start + 1), true);
        return is_array($json) ? $json : null;
    }
}
