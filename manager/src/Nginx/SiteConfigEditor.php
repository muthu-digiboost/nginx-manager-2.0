<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : SiteConfigEditor.php
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
 * File: manager/src/Nginx/SiteConfigEditor.php
 * Purpose: Structured edits from the site details form, applied surgically.
 * Responsibilities - apply($conf, $changes) where $changes may contain:
 *   root         string    main block's top-level "root"
 *   upstream     string    "fastcgi_pass" lines that use the site's current PHP
 *   serverNames  string[]  "server_name" in the main block and its HTTPS redirect block
 *   ssl          bool      on: listen 443 ssl + cert lines + redirect block
 *                          off: listen 80, cert lines and redirect block removed
 *   certPath/keyPath       certificate files used when ssl is (or stays) on
 *   phpLabel     string    PHP shown in the generated header comment
 * Every edit replaces only the value bytes of the targeted statement, so hand-written
 * comments, includes and custom locations survive. When the expected directive is
 * missing or ambiguous it throws UnsupportedConfigException instead of guessing.
 * Values are validated so no input can inject extra directives (";", "{", "}", "#").
 * Used by: SitesController, SslController
 */

declare(strict_types=1);

namespace NginxManager\Nginx;

use InvalidArgumentException;

final class SiteConfigEditor
{
    public static function apply(string $conf, array $changes): string
    {
        foreach (['root', 'upstream', 'certPath', 'keyPath', 'phpLabel'] as $key) {
            if (isset($changes[$key])) {
                self::assertSafe((string) $changes[$key], $key);
            }
        }
        foreach ($changes['serverNames'] ?? [] as $name) {
            self::assertSafe((string) $name, 'serverNames');
        }

        if (array_key_exists('root', $changes)) {
            $conf = self::setRoot($conf, (string) $changes['root']);
        }
        if (array_key_exists('upstream', $changes)) {
            $conf = self::setUpstream($conf, (string) $changes['upstream']);
        }
        if (array_key_exists('serverNames', $changes)) {
            $names = implode(' ', array_values(array_filter($changes['serverNames'], 'strlen')));
            if ($names === '') {
                throw new InvalidArgumentException('At least one domain is required.');
            }
            $conf = self::setServerNames($conf, $names);
        }
        if (array_key_exists('ssl', $changes)) {
            $conf = $changes['ssl']
                ? self::sslOn($conf, $changes['certPath'] ?? null, $changes['keyPath'] ?? null)
                : self::sslOff($conf);
        }
        if (array_key_exists('phpLabel', $changes)) {
            $conf = self::setHeader($conf, (string) $changes['phpLabel']);
        }
        return $conf;
    }

    // -- individual edits --------------------------------------------------------

    private static function setRoot(string $conf, string $root): string
    {
        $doc = new ConfigDocument($conf);
        $main = self::mainOrFail($doc);
        foreach ($doc->servers as $i => $server) {
            if ($i !== $main && !$doc->isRedirectBlock($server) && $doc->statements($i, 'root')) {
                throw new UnsupportedConfigException('This file has more than one site block with a root.');
            }
        }
        $roots = $doc->statements($main, 'root');
        if (count($roots) !== 1) {
            throw new UnsupportedConfigException('Expected exactly one "root" in the site block, found ' . count($roots) . '.');
        }
        return self::replaceValue($conf, $roots[0], $root);
    }

    /**
     * server_name: the main block and the HTTP->HTTPS redirect blocks this manager writes.
     * Other server blocks (e.g. a hand-written www -> bare-domain redirect) are left alone.
     */
    private static function setServerNames(string $conf, string $names): string
    {
        $doc = new ConfigDocument($conf);
        $main = self::mainOrFail($doc);
        $targets = [];
        foreach ($doc->servers as $i => $server) {
            if ($i === $main || self::isOwnRedirect($doc, $i, $main)) {
                array_push($targets, ...$doc->statements($i, 'server_name'));
            }
        }
        if (!$targets) {
            throw new UnsupportedConfigException('No "server_name" directive found to change.');
        }
        return self::replaceAll($conf, $targets, $names);
    }

    /**
     * fastcgi_pass: only the lines that use the site's CURRENT PHP (the first fastcgi_pass of
     * the main block). A nested location deliberately pointing at another backend keeps it.
     */
    private static function setUpstream(string $conf, string $upstream): string
    {
        $doc = new ConfigDocument($conf);
        $main = self::mainOrFail($doc);
        $current = $doc->statements($main, 'fastcgi_pass', false)[0]['value'] ?? null;
        if ($current === null) {
            throw new UnsupportedConfigException('This site has no "fastcgi_pass" (it is not a PHP site).');
        }
        $targets = [];
        foreach ($doc->servers as $i => $server) {
            foreach ($doc->statements($i, 'fastcgi_pass', false) as $s) {
                if ($s['value'] === $current) {
                    $targets[] = $s;
                }
            }
        }
        return self::replaceAll($conf, $targets, $upstream);
    }

    private static function replaceAll(string $conf, array $targets, string $value): string
    {
        usort($targets, static fn($a, $b) => $b['valueStart'] <=> $a['valueStart']);   // back to front keeps offsets valid
        foreach ($targets as $s) {
            $conf = self::replaceValue($conf, $s, $value);
        }
        return $conf;
    }

    private static function sslOn(string $conf, ?string $cert, ?string $key): string
    {
        $doc = new ConfigDocument($conf);
        $main = self::mainOrFail($doc);
        $listens = $doc->statements($main, 'listen');
        if (count($listens) !== 1) {
            throw new UnsupportedConfigException('Expected exactly one "listen" in the site block to switch SSL.');
        }

        // 1. certificate lines (replace existing, or insert after server_name)
        if ($cert !== null && $key !== null) {
            $existingCert = $doc->statements($main, 'ssl_certificate');
            $existingKey = $doc->statements($main, 'ssl_certificate_key');
            if ($existingCert && $existingKey) {
                $edits = [[$existingKey[0], $key], [$existingCert[0], $cert]];
                usort($edits, static fn($a, $b) => $b[0]['valueStart'] <=> $a[0]['valueStart']);
                foreach ($edits as [$stmt, $value]) {
                    $conf = self::replaceValue($conf, $stmt, $value);
                }
            } else {
                $names = $doc->statements($main, 'server_name');
                $anchor = $names[0] ?? $listens[0];
                $insert = "\n\n    ssl_certificate      {$cert};\n    ssl_certificate_key  {$key};";
                $conf = substr($conf, 0, $anchor['end']) . $insert . substr($conf, $anchor['end']);
            }
            $doc = new ConfigDocument($conf);
            $listens = $doc->statements($main, 'listen');
        }

        // 2. listen 443 ssl
        if (!preg_match('/(^|\s)ssl(\s|$)/', $listens[0]['value'])) {
            $conf = self::replaceValue($conf, $listens[0], '443 ssl');
        }

        // 3. redirect block
        $doc = new ConfigDocument($conf);
        $hasRedirect = false;
        foreach ($doc->servers as $server) {
            $hasRedirect = $hasRedirect || $doc->isRedirectBlock($server);
        }
        if (!$hasRedirect) {
            $names = $doc->statements((int) $doc->mainIndex(), 'server_name');
            $serverNames = $names ? $names[0]['value'] : '_';
            $conf = rtrim($conf) . "\n\n" . SiteConfigBuilder::redirectBlock($serverNames);
        }
        return self::setHeaderMode($conf, 'SSL enabled');
    }

    /**
     * The site's OWN HTTP->HTTPS redirect: a redirect-only block serving the same names as the
     * main block. A hand-written "www -> bare domain" redirect (other names) is not touched.
     */
    private static function isOwnRedirect(ConfigDocument $doc, int $i, int $main): bool
    {
        if ($i === $main || !$doc->isRedirectBlock($doc->servers[$i])) {
            return false;
        }
        $names = static fn(int $k): string => implode(' ', array_map(
            static fn($s) => (string) preg_replace('/\s+/', ' ', trim($s['value'])), $doc->statements($k, 'server_name')));
        return $names($i) === $names($main);
    }

    private static function sslOff(string $conf): string
    {
        $doc = new ConfigDocument($conf);
        $main = self::mainOrFail($doc);
        $listens = $doc->statements($main, 'listen');
        if (count($listens) !== 1) {
            throw new UnsupportedConfigException('Expected exactly one "listen" in the site block to switch SSL.');
        }

        // Remove, back to front: redirect blocks, cert lines; then fix listen.
        $cuts = [];
        foreach ($doc->servers as $i => $server) {
            if (self::isOwnRedirect($doc, $i, $main)) {
                $cuts[] = [self::lineStart($conf, $server['start']), self::lineEnd($conf, $server['end'])];
            }
        }
        foreach (['ssl_certificate', 'ssl_certificate_key'] as $name) {
            foreach ($doc->statements($main, $name) as $s) {
                $cuts[] = [self::lineStart($conf, $s['start']), self::lineEnd($conf, $s['end'])];
            }
        }
        usort($cuts, static fn($a, $b) => $b[0] <=> $a[0]);
        foreach ($cuts as [$from, $to]) {
            $conf = substr($conf, 0, $from) . substr($conf, $to);
        }
        // A "# HTTP → HTTPS redirect" comment left behind on its own is removed too.
        $conf = preg_replace('/^#\s*HTTP\s*(→|->)\s*HTTPS redirect\s*\R(?!server)/mu', '', $conf) ?? $conf;
        $conf = preg_replace("/\n{3,}/", "\n\n", $conf) ?? $conf;

        $doc = new ConfigDocument($conf);
        $listen = $doc->statements((int) $doc->mainIndex(), 'listen')[0];
        $conf = self::replaceValue($conf, $listen, '80');
        return self::setHeaderMode(rtrim($conf) . "\n", 'HTTP only');
    }

    private static function setHeader(string $conf, string $php): string
    {
        return preg_replace('/^(#\s+\S+\s+(?:→|->)\s+PHP\s+)\S+/mu', '${1}' . $php, $conf, 1) ?? $conf;
    }

    private static function setHeaderMode(string $conf, string $mode): string
    {
        return preg_replace('/^(#\s+\S+\s+(?:→|->)\s+PHP\s+\S+\s+)\([^)]*\)/mu', '${1}(' . $mode . ')', $conf, 1) ?? $conf;
    }

    // -- helpers -------------------------------------------------------------------

    private static function mainOrFail(ConfigDocument $doc): int
    {
        $main = $doc->mainIndex();
        if ($main === null) {
            throw new UnsupportedConfigException('No site server block found.');
        }
        return $main;
    }

    private static function replaceValue(string $conf, array $stmt, string $value): string
    {
        return substr($conf, 0, $stmt['valueStart']) . $value . substr($conf, $stmt['valueEnd']);
    }

    private static function lineStart(string $text, int $offset): int
    {
        $nl = strrpos(substr($text, 0, $offset), "\n");
        return $nl === false ? 0 : $nl + 1;
    }

    private static function lineEnd(string $text, int $offset): int
    {
        $nl = strpos($text, "\n", $offset);
        return $nl === false ? strlen($text) : $nl + 1;
    }

    private static function assertSafe(string $value, string $field): void
    {
        if ($value === '' || preg_match('/[;{}#"\'\r\n]/', $value) || preg_match('/\s/', $value)) {
            throw new InvalidArgumentException("Invalid value for $field: \"$value\"");
        }
    }
}
