<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : Router.php
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
 * File: manager/src/Http/Router.php
 * Purpose: Maps "METHOD /path/{param}" to controller callables.
 * Responsibilities:
 *   - {name} matches exactly one path segment ([^/]+) - matching happens on the RAW
 *     path, decoding happens afterwards, so "%2F" can never create an extra segment
 *   - unknown path or method -> 404 response
 * Used by: manager/src/routes.php, public/index.php
 * Notes: handler signature  fn(Request $request, array $params): Response
 */

declare(strict_types=1);

namespace NginxManager\Http;

final class Router
{
    /** @var list<array{method:string, regex:string, names:list<string>, handler:callable}> */
    private array $routes = [];

    /** @var callable|null fn(Request): Response  used for 404s */
    private $notFound = null;

    public function add(string $method, string $pattern, callable $handler): void
    {
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $pattern, $m);
        $regex = '#^' . preg_replace('/\\\\\{[a-zA-Z_][a-zA-Z0-9_]*\\\\\}/', '([^/]+)', preg_quote($pattern, '#')) . '$#';
        $this->routes[] = ['method' => strtoupper($method), 'regex' => $regex, 'names' => $m[1], 'handler' => $handler];
    }

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function setNotFound(callable $handler): void
    {
        $this->notFound = $handler;
    }

    public function dispatch(string $method, string $path, ?Request $request = null): Response
    {
        $request ??= Request::create($method, $path);
        $rawPath = '/' . trim($path, '/');
        foreach ($this->routes as $route) {
            if ($route['method'] !== strtoupper($method) || !preg_match($route['regex'], $rawPath, $m)) {
                continue;
            }
            $params = [];
            foreach ($route['names'] as $i => $name) {
                $params[$name] = rawurldecode($m[$i + 1]);
            }
            return ($route['handler'])($request, $params);
        }
        return $this->notFound !== null ? ($this->notFound)($request) : Response::html('Not found', 404);
    }
}
