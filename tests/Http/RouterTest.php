<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : RouterTest.php
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
 * File: tests/Http/RouterTest.php
 * Purpose: Route matching, parameter decoding and 404 handling of the front controller router.
 */

declare(strict_types=1);

use NginxManager\Http\Request;
use NginxManager\Http\Response;
use NginxManager\Http\Router;

test('router matches params and decodes them', function () {
    $r = new Router();
    $r->add('GET', '/sites/{id}', fn(Request $req, array $p) => Response::html($p['id']));
    assertSame('api.example.test', $r->dispatch('GET', '/sites/api.example.test')->body);
    assertSame('a b', $r->dispatch('GET', '/sites/a%20b')->body);
});

test('router returns 404 for unknown path and wrong method', function () {
    $r = new Router();
    $r->add('POST', '/groups', fn() => Response::html('x'));
    assertSame(404, $r->dispatch('GET', '/groups')->status);
    assertSame(404, $r->dispatch('GET', '/nope')->status);
});

test('router params never span a slash, even when encoded', function () {
    $r = new Router();
    $r->add('GET', '/sites/{id}', fn(Request $req, array $p) => Response::html($p['id']));
    assertSame(404, $r->dispatch('GET', '/sites/a/b')->status);
    // %2F decodes to "/" - the param is still one segment, so a validator downstream rejects it.
    assertSame('../nginx', $r->dispatch('GET', '/sites/..%2Fnginx')->body);
});

test('request normalises path: query stripped, trailing slash trimmed, root kept', function () {
    assertSame('/sites', Request::create('GET', '/sites/?x=1')->path);
    assertSame('/', Request::create('GET', '/')->path);
    assertSame('/', Request::create('GET', '')->path);
});
