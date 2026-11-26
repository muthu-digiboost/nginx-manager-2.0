<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : ConfigDocument.php
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
 * File: manager/src/Nginx/ConfigDocument.php
 * Purpose: A minimal, position-preserving scanner for nginx config text.
 * Responsibilities:
 *   - find the top-level "server { ... }" blocks of a site file
 *   - list every statement inside a block with the EXACT byte offsets of its value,
 *     so the editor can replace one value and leave every other byte untouched
 *   - understand comments (# to end of line), single/double quotes and nested blocks,
 *     so braces or semicolons inside comments/quoted strings never confuse it
 * Used by: SiteConfigParser (read), SiteConfigEditor (write)
 * Notes: this is NOT a full nginx parser - it understands just enough of the grammar
 *        (statements end with ";" or open a "{ }" block) to edit site files safely.
 */

declare(strict_types=1);

namespace NginxManager\Nginx;

final class ConfigDocument
{
    /**
     * @var list<array{start:int, end:int, bodyStart:int, bodyEnd:int, statements:list<array{name:string, value:string, valueStart:int, valueEnd:int, start:int, end:int, depth:int}>}>
     */
    public readonly array $servers;

    public function __construct(public readonly string $text)
    {
        $this->servers = $this->scan();
    }

    /** Statement value with surrounding quotes removed. */
    public static function unquote(string $value): string
    {
        $v = trim($value);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
            return substr($v, 1, -1);
        }
        return $v;
    }

    /** A redirect-only block: no root, and a "return 301/302 https://..." statement. */
    public function isRedirectBlock(array $server): bool
    {
        $hasReturn = false;
        foreach ($server['statements'] as $s) {
            if ($s['name'] === 'root' || $s['name'] === 'location') {
                return false;
            }
            if ($s['name'] === 'return' && preg_match('/^30[12]\s+https:/', $s['value'])) {
                $hasReturn = true;
            }
        }
        return $hasReturn;
    }

    /**
     * Index of the main server block, or null: the first block that serves the site (has a
     * top-level root or a location); otherwise the first block that is not an HTTPS redirect.
     * A hand-written "www -> bare domain" redirect placed first is therefore not the main block.
     */
    public function mainIndex(): ?int
    {
        foreach ($this->servers as $i => $server) {
            foreach ($server['statements'] as $s) {
                if ($s['depth'] === 0 && in_array($s['name'], ['root', 'location'], true)) {
                    return $i;
                }
            }
        }
        foreach ($this->servers as $i => $server) {
            if (!$this->isRedirectBlock($server)) {
                return $i;
            }
        }
        return null;
    }

    /** @return list<array> statements named $name in a server block (optionally only depth 0) */
    public function statements(int $serverIndex, string $name, bool $topLevelOnly = true): array
    {
        return array_values(array_filter(
            $this->servers[$serverIndex]['statements'] ?? [],
            static fn($s) => $s['name'] === $name && (!$topLevelOnly || $s['depth'] === 0),
        ));
    }

    // -------------------------------------------------------------------------

    private function scan(): array
    {
        $servers = [];
        $text = $this->text;
        $len = strlen($text);
        $depth = 0;
        $i = 0;
        $current = null;           // server block being collected
        $stmtStart = null;         // offset where the current statement's first word starts

        while ($i < $len) {
            $c = $text[$i];

            if ($c === '#') {                                   // comment to end of line
                $nl = strpos($text, "\n", $i);
                $i = $nl === false ? $len : $nl + 1;
                continue;
            }
            if ($c === '"' || $c === "'") {                     // quoted string
                $stmtStart ??= $i;
                $i = $this->skipQuoted($i);
                continue;
            }
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if ($c === '{') {
                $header = $stmtStart !== null ? trim(substr($text, $stmtStart, $i - $stmtStart)) : '';
                if ($depth === 0 && $header === 'server') {
                    $current = ['start' => $stmtStart, 'end' => 0, 'bodyStart' => $i + 1, 'bodyEnd' => 0, 'statements' => []];
                } elseif ($current !== null) {
                    $this->addStatement($current, $stmtStart, $i, $depth - 1, true);
                }
                $depth++;
                $stmtStart = null;
                $i++;
                continue;
            }
            if ($c === '}') {
                $depth = max(0, $depth - 1);
                if ($depth === 0 && $current !== null) {
                    $current['bodyEnd'] = $i;
                    $current['end'] = $i + 1;
                    $servers[] = $current;
                    $current = null;
                }
                $stmtStart = null;
                $i++;
                continue;
            }
            if ($c === ';') {
                if ($current !== null && $stmtStart !== null) {
                    $this->addStatement($current, $stmtStart, $i, $depth - 1, false);
                }
                $stmtStart = null;
                $i++;
                continue;
            }
            $stmtStart ??= $i;
            $i++;
        }
        return $servers;
    }

    private function skipQuoted(int $i): int
    {
        $q = $this->text[$i];
        $len = strlen($this->text);
        for ($j = $i + 1; $j < $len; $j++) {
            if ($this->text[$j] === '\\') {
                $j++;
                continue;
            }
            if ($this->text[$j] === $q) {
                return $j + 1;
            }
        }
        return $len;
    }

    /** Records "name value" between $start and $stop (the ';' or '{'). */
    private function addStatement(array &$server, ?int $start, int $stop, int $depth, bool $isBlock): void
    {
        if ($start === null) {
            return;
        }
        $raw = substr($this->text, $start, $stop - $start);
        if (!preg_match('/^(\S+)(\s*)/', $raw, $m)) {
            return;
        }
        $valueStart = $start + strlen($m[0]);
        $valueEnd = $stop;
        while ($valueEnd > $valueStart && ctype_space($this->text[$valueEnd - 1])) {
            $valueEnd--;
        }
        $server['statements'][] = [
            'name'       => $m[1],
            'value'      => substr($this->text, $valueStart, $valueEnd - $valueStart),
            'valueStart' => $valueStart,
            'valueEnd'   => $valueEnd,
            'start'      => $start,
            'end'        => $stop + 1,
            'depth'      => max(0, $depth),
            'block'      => $isBlock,
        ];
    }
}
