<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : HttpFetcher.php
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
 * File: manager/src/Php/HttpFetcher.php
 * Purpose: Plain HTTPS GET used for the php.net catalogues (no Composer / curl needed).
 * Responsibilities: follow redirects, time out, throw on HTTP errors with a clear message
 * Used by: PhpReleaseFeed, PhpArchiveCatalog, PeclCatalog (as their default fetcher)
 * Notes: sends a generic User-Agent; nothing about the user or machine is sent.
 */

declare(strict_types=1);

namespace NginxManager\Php;

use RuntimeException;

final class HttpFetcher
{
    public function __invoke(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => 20,
                'follow_location' => 1,
                'max_redirects'   => 5,
                'header'          => "User-Agent: NginxManager/2.0\r\nAccept: */*\r\n",
                'ignore_errors'   => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];   // the last status line wins (after redirects)
            }
        }
        if ($body === false || $status >= 400 || $status === 0) {
            $err = error_get_last()['message'] ?? '';
            throw new RuntimeException("Could not download $url" . ($status ? " (HTTP $status)" : '') . ($err ? ": $err" : ''));
        }
        return $body;
    }
}
