<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : helpers.php
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
 * File: manager/src/helpers.php
 * Purpose: Small global functions used by views and controllers.
 * Responsibilities:
 *   - e()          HTML-escape every value printed in a view (ENT_QUOTES, UTF-8)
 *   - url()        build an app URL; path segments are rawurlencoded by the caller
 *   - csrf_field() hidden input carrying the session CSRF token
 *   - active()     "active" CSS class for the sidebar link of the current section
 *   - tail_file()  last N lines of a (possibly huge) log file without reading it all
 * Used by: views/*, controllers
 */

declare(strict_types=1);

use NginxManager\Http\Csrf;

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

function url(string $path = '/', array $query = []): string
{
    $path = '/' . ltrim($path, '/');
    return $query ? $path . '?' . http_build_query($query) : $path;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">';
}

/** Returns "active" when the current request path is $prefix or below it. */
function active(string $prefix): string
{
    $path = $GLOBALS['__requestPath'] ?? '/';
    if ($prefix === '/') {
        return $path === '/' ? 'active' : '';
    }
    return ($path === $prefix || str_starts_with($path, $prefix . '/')) ? 'active' : '';
}

/**
 * Last $lines lines of a text file, reading backwards in 8 KB chunks so a 2 GB
 * access.log costs the same as a small one. Returns '' for missing/unreadable files.
 */
function tail_file(string $path, int $lines = 200): string
{
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return '';
    }
    $size = (int) fstat($fh)['size'];
    $chunk = 8192;
    $buffer = '';
    $pos = $size;
    while ($pos > 0 && substr_count($buffer, "\n") <= $lines) {
        $read = min($chunk, $pos);
        $pos -= $read;
        fseek($fh, $pos);
        $buffer = fread($fh, $read) . $buffer;
    }
    fclose($fh);
    $all = preg_split("/\r?\n/", rtrim($buffer, "\r\n")) ?: [];
    return implode("\n", array_slice($all, -$lines));
}

/**
 * URL of a file in manager/public with its modification time appended, so browsers
 * fetch the new CSS/JS right after an update instead of a stale cached copy.
 */
function asset(string $path): string
{
    $path = '/' . ltrim($path, '/');
    $file = \NginxManager\Support\Paths::in('manager', 'public', $path);
    return $path . (is_file($file) ? '?v=' . filemtime($file) : '');
}
