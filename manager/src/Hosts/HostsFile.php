<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : HostsFile.php
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
 * File: manager/src/Hosts/HostsFile.php
 * Purpose: Adds / removes "127.0.0.1 <domain>" lines in the Windows hosts file so new
 *          local domains resolve without manual editing.
 * Responsibilities:
 *   - has(domain): any active (non-comment) line maps the domain
 *   - add(domain): appends "127.0.0.1<TAB>domain<TAB># nginx-manager" (idempotent)
 *   - remove(domain): removes ONLY lines carrying the "# nginx-manager" marker -
 *     entries written by the user or other tools are never touched
 *   - keeps the file's existing line endings
 * Used by: SitesController (create / delete)
 * Notes: writing needs Administrator (start-all.ps1 elevates the manager). Wildcard
 *        domains cannot be expressed in a hosts file and are rejected.
 */

declare(strict_types=1);

namespace NginxManager\Hosts;

use InvalidArgumentException;
use NginxManager\Support\Paths;
use NginxManager\Support\Platform;
use NginxManager\Support\Shell;
use NginxManager\Support\Validator;
use RuntimeException;

final class HostsFile
{
    public const MARKER = '# nginx-manager';

    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? Paths::hostsFile();
    }

    public function has(string $domain): bool
    {
        foreach ($this->lines() as $line) {
            $line = trim(preg_replace('/#.*/', '', $line) ?? '');
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            if (in_array(strtolower($domain), array_map('strtolower', array_slice($parts, 1)), true)) {
                return true;
            }
        }
        return false;
    }

    public function add(string $domain): void
    {
        $this->assertDomain($domain);
        if ($this->has($domain)) {
            return;
        }
        $text = $this->read();
        $eol = str_contains($text, "\r\n") || $text === '' ? "\r\n" : "\n";
        if ($text !== '' && !str_ends_with($text, "\n")) {
            $text .= $eol;
        }
        $this->write($text . "127.0.0.1\t{$domain}\t" . self::MARKER . $eol);
    }

    public function remove(string $domain): void
    {
        $text = $this->read();
        $out = preg_replace(
            '/^[ \t]*\S+[ \t]+' . preg_quote($domain, '/') . '[ \t]+' . preg_quote(self::MARKER, '/') . '[ \t]*\r?\n?/mi',
            '',
            $text,
        );
        if ($out !== null && $out !== $text) {
            $this->write($out);
        }
    }

    private function assertDomain(string $domain): void
    {
        if (str_contains($domain, '*') || !Validator::domain($domain)) {
            throw new InvalidArgumentException("\"$domain\" cannot be added to the hosts file (wildcards and invalid names are not supported).");
        }
    }

    /** @return list<string> */
    private function lines(): array
    {
        return preg_split("/\r?\n/", $this->read()) ?: [];
    }

    private function read(): string
    {
        return is_file($this->path) ? (string) file_get_contents($this->path) : '';
    }

    private function write(string $text): void
    {
        if (@file_put_contents($this->path, $text) !== false) {
            return;
        }
        if (Platform::isWindows()) {
            throw new RuntimeException('Could not edit the hosts file - the server is not running as Administrator.');
        }
        // macOS: /etc/hosts belongs to root. Write a copy, then let macOS ask for the admin
        // password (its own dialog) to put it in place.
        $tmp = Paths::in('temp', 'hosts-' . bin2hex(random_bytes(4)) . '.tmp');
        if (!is_dir(dirname($tmp))) {
            mkdir(dirname($tmp), 0777, true);
        }
        file_put_contents($tmp, $text);
        try {
            $r = (new Shell())->run(self::adminCopyCommand($tmp, $this->path), null, 300);
        } finally {
            @unlink($tmp);
        }
        if (!$r->ok()) {
            throw new RuntimeException('Could not edit the hosts file (was the password dialog cancelled?): ' . trim($r->output));
        }
    }

    /**
     * osascript command that copies $from over $to with administrator rights, after macOS's
     * password dialog. Paths go through AppleScript's "quoted form of", so spaces and quotes
     * are safe; only \ and " need escaping inside the AppleScript string literals.
     * @return list<string>
     */
    public static function adminCopyCommand(string $from, string $to): array
    {
        $lit = static fn(string $s): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
        return ['osascript', '-e', 'do shell script "cp " & quoted form of ' . $lit($from)
            . ' & " " & quoted form of ' . $lit($to)
            . ' with prompt "Nginx Manager needs to update /etc/hosts." with administrator privileges'];
    }
}
