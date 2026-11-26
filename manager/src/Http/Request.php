<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : Request.php
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
 * File: manager/src/Http/Request.php
 * Purpose: Immutable view of the current HTTP request.
 * Responsibilities:
 *   - normalised path: no query string, trailing slash trimmed, "/" kept
 *   - post()/query() accessors with defaults
 * Used by: public/index.php, Router, controllers
 */

declare(strict_types=1);

namespace NginxManager\Http;

final class Request
{
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $queryData,
        private readonly array $postData,
        private readonly array $server,
    ) {}

    public static function fromGlobals(): self
    {
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            self::normalisePath((string) ($_SERVER['REQUEST_URI'] ?? '/')),
            $_GET,
            $_POST,
            $_SERVER,
        );
    }

    public static function create(string $method, string $uri, array $post = [], array $server = []): self
    {
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        return new self(strtoupper($method), self::normalisePath($uri), $query, $post, $server);
    }

    private static function normalisePath(string $uri): string
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $path = '/' . trim($path, '/');
        return $path;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->postData[$key] ?? $default;
    }

    public function postString(string $key, string $default = ''): string
    {
        $v = $this->postData[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    /** @return list<string> */
    public function postList(string $key): array
    {
        $v = $this->postData[$key] ?? [];
        return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->queryData[$key] ?? $default;
    }

    public function server(string $key, ?string $default = null): ?string
    {
        $v = $this->server[$key] ?? null;
        return is_string($v) ? $v : $default;
    }

    public function wantsJson(): bool
    {
        return str_contains((string) $this->server('HTTP_ACCEPT', ''), 'application/json');
    }
}
