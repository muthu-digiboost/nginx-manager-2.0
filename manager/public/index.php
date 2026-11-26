<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : index.php
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
 * File: manager/public/index.php
 * Purpose: Front controller - every manager URL is served by this file
 *          (nginx: try_files $uri /index.php).
 * Responsibilities, in order:
 *   1. bootstrap (autoload, .env, session)
 *   2. Host header check  - blocks DNS-rebinding: a page on evil.example whose DNS is
 *                           switched to 127.0.0.1 would otherwise talk to this
 *                           Administrator-level tool
 *   3. setup page when .env is missing
 *   4. CSRF check for every POST (+ Origin check when the browser sends one)
 *   5. dispatch the route; 419 for an expired form, 500 page for unexpected errors
 * Used by: nginx (conf/manager/manager.conf)
 */

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/routes.php';

use NginxManager\App;
use NginxManager\Http\Csrf;
use NginxManager\Http\CsrfException;
use NginxManager\Http\Request;
use NginxManager\Http\Response;
use NginxManager\Support\Env;
use NginxManager\Support\View;

$request = Request::fromGlobals();
$GLOBALS['__requestPath'] = $request->path;

// -- 2. Host check --------------------------------------------------------------
$port = (string) Env::int('MANAGER_PORT', 8080);
$allowedHosts = array_unique([
    Env::get('MANAGER_HOST', '127.0.0.1') . ':' . $port,
    '127.0.0.1:' . $port,
    'localhost:' . $port,
]);
$host = strtolower((string) $request->server('HTTP_HOST', ''));
if (!in_array($host, $allowedHosts, true)) {
    (new Response('Forbidden host', 403, ['Content-Type' => 'text/plain']))->send();
    exit;
}

// -- 3. Setup page ----------------------------------------------------------------
if ($GLOBALS['__envError'] !== null) {
    Response::html(View::render('errors/setup', ['title' => 'Setup needed', 'message' => $GLOBALS['__envError']], null), 500)->send();
    exit;
}

try {
    // -- 4. CSRF ------------------------------------------------------------------
    if ($request->method === 'POST') {
        $origin = $request->server('HTTP_ORIGIN');
        if ($origin !== null && !in_array(strtolower((string) parse_url($origin, PHP_URL_HOST)) . ':' . (parse_url($origin, PHP_URL_PORT) ?? 80), $allowedHosts, true)) {
            throw new CsrfException('Request came from another website.');
        }
        Csrf::verify(is_string($request->post('_token')) ? $request->post('_token') : null);
    }

    // -- 5. Dispatch ----------------------------------------------------------------
    $app = new App();
    // Header status pills - computed lazily, only when a full page is rendered.
    View::share('statusBar', static function () use ($app): array {
        $php = $app->phpVersions()->installed();
        return [
            'nginx'      => $app->nginx()->isRunning(),
            'phpRunning' => count(array_filter($php, static fn($v) => $v['running'])),
            'phpTotal'   => count($php),
        ];
    });
    // Menu badge: PHP updates available (cached release feed only - never the network).
    View::share('updateCount', static fn(): int => $app->phpUpdateCount());
    $response = build_router($app)->dispatch($request->method, (string) parse_url((string) $request->server('REQUEST_URI', '/'), PHP_URL_PATH), $request);
} catch (CsrfException $e) {
    $response = Response::html(View::render('errors/error', ['title' => 'Page expired', 'message' => $e->getMessage(), 'code' => 419]), 419);
} catch (Throwable $e) {
    error_log((string) $e);
    $response = Response::html(View::render('errors/error', [
        'title'   => 'Something went wrong',
        'message' => $e->getMessage(),
        'detail'  => get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine(),
        'code'    => 500,
    ]), 500);
}

$response->send();
