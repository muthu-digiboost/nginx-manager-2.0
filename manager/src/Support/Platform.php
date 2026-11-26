<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : Platform.php
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
 * File: manager/src/Support/Platform.php
 * Purpose: Every Windows / macOS difference that is not about PHP versions, in one place.
 * Responsibilities:
 *   - os(): "Windows" or "Darwin" (PHP_OS_FAMILY; override() for tests)
 *   - listening ports: netstat command + parser for both output formats
 *       Windows: "TCP  127.0.0.1:9083  0.0.0.0:0  LISTENING"
 *       macOS:   "tcp4  0  0  127.0.0.1.9084  *.*  LISTEN"
 *   - nginxBinary(): {base}/nginx.exe on Windows; NGINX_BIN, Homebrew or PATH on macOS
 *   - nginxRunning(): tasklist (Windows) / pgrep (macOS)
 *   - opensslCandidates(): where to look for openssl
 *   - label(): "Windows" / "macOS" for UI texts
 * Used by: Paths, Timezone, Validator, NginxControl, PhpVersionRepository, CertificateService,
 *          SettingsController, JobRunner
 * Notes: Linux is treated like macOS where the tools are the same, but is not supported.
 */

declare(strict_types=1);

namespace NginxManager\Support;

final class Platform
{
    private static ?string $override = null;

    /** Tests only: pretend to run on "Windows" or "Darwin"; null = the real OS. */
    public static function override(?string $os): void
    {
        self::$override = $os;
    }

    public static function os(): string
    {
        return self::$override ?? PHP_OS_FAMILY;
    }

    public static function isWindows(): bool
    {
        return self::os() === 'Windows';
    }

    public static function isMac(): bool
    {
        return self::os() === 'Darwin';
    }

    public static function label(): string
    {
        return self::isWindows() ? 'Windows' : 'macOS';
    }

    /** The null device: stdin for commands that must not wait for input. */
    public static function nullDevice(): string
    {
        return self::isWindows() ? 'NUL' : '/dev/null';
    }

    // -- ports -------------------------------------------------------------------------

    /** @return list<string> */
    public static function netstatCommand(): array
    {
        return self::isWindows() ? ['netstat', '-an', '-p', 'TCP'] : ['netstat', '-an', '-p', 'tcp'];
    }

    /** @return list<int> sorted local ports in LISTEN state on 127.0.0.1 or all interfaces */
    public static function parseListeningPorts(string $netstat): array
    {
        $re = self::isWindows()
            ? '/^\s*TCP\s+(?:127\.0\.0\.1|0\.0\.0\.0|\[::\]):(\d+)\s+\S+\s+LISTENING/mi'
            : '/^\s*tcp(?:4|6|46)?\s+\d+\s+\d+\s+(?:127\.0\.0\.1|\*|::1|::)\.(\d+)\s+\S+\s+LISTEN\b/mi';
        $ports = [];
        if (preg_match_all($re, $netstat, $m)) {
            $ports = array_values(array_unique(array_map('intval', $m[1])));
        }
        sort($ports);
        return $ports;
    }

    // -- nginx ---------------------------------------------------------------------------

    /** @param callable(string):bool|null $isExecutable  tests inject it */
    public static function nginxBinary(?callable $isExecutable = null): string
    {
        if (self::isWindows()) {
            return Paths::in('nginx.exe');
        }
        $set = (string) Env::get('NGINX_BIN', '');
        if ($set !== '') {
            return $set;
        }
        $isExecutable ??= static fn(string $p): bool => is_file($p) && is_executable($p);
        foreach (['/opt/homebrew/bin/nginx', '/usr/local/bin/nginx'] as $candidate) {   // Apple silicon, Intel
            if ($isExecutable($candidate)) {
                return $candidate;
            }
        }
        return 'nginx';   // on PATH
    }

    /** @return list<string> command that prints something only when nginx runs */
    public static function nginxProcessCommand(): array
    {
        return self::isWindows()
            ? ['tasklist', '/FI', 'IMAGENAME eq nginx.exe', '/NH', '/FO', 'CSV']
            : ['pgrep', '-x', 'nginx'];
    }

    public static function nginxRunningFrom(string $output): bool
    {
        return self::isWindows()
            ? stripos($output, '"nginx.exe"') !== false
            : preg_match('/^\s*\d+\s*$/m', $output) === 1;
    }

    // -- openssl -----------------------------------------------------------------------------

    /** @return list<string> candidate paths, most specific first (OPENSSL_BIN is checked by callers) */
    public static function opensslCandidates(): array
    {
        if (self::isWindows()) {
            return [
                getenv('ProgramFiles') . '/Git/usr/bin/openssl.exe',
                getenv('ProgramFiles(x86)') . '/Git/usr/bin/openssl.exe',
            ];
        }
        return ['/opt/homebrew/opt/openssl@3/bin/openssl', '/usr/local/opt/openssl@3/bin/openssl', '/usr/bin/openssl'];
    }

    /** @return list<string> "which openssl" */
    public static function whichCommand(string $program): array
    {
        return self::isWindows() ? ['where', $program] : ['/usr/bin/which', $program];
    }
}
