<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : routes.php
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
 * File: manager/src/routes.php
 * Purpose: The complete URL map of the manager UI in one place.
 * Responsibilities: build the Router with every route -> controller method
 * Used by: manager/public/index.php
 * Notes: every POST is CSRF-checked in index.php before it reaches a controller.
 */

declare(strict_types=1);

use NginxManager\App;
use NginxManager\Controllers\DashboardController;
use NginxManager\Controllers\ExtensionsController;
use NginxManager\Controllers\GroupsController;
use NginxManager\Controllers\JobsController;
use NginxManager\Controllers\NginxController;
use NginxManager\Controllers\PhpController;
use NginxManager\Controllers\SettingsController;
use NginxManager\Controllers\SitesController;
use NginxManager\Controllers\SslController;
use NginxManager\Http\Request;
use NginxManager\Http\Response;
use NginxManager\Http\Router;
use NginxManager\Support\View;

function build_router(App $app): Router
{
    $r = new Router();

    // Dashboard & global actions
    $dashboard = new DashboardController($app);
    $r->get('/', [$dashboard, 'index']);
    $nginx = new NginxController($app);
    $r->post('/nginx/reload', [$nginx, 'reload']);

    // PHP versions
    $php = new PhpController($app);
    $r->get('/php', [$php, 'index']);
    $r->post('/php/service', [$php, 'service']);
    $r->post('/php/refresh', [$php, 'refresh']);
    $r->post('/php/install', [$php, 'install']);
    $r->post('/php/{id}/update', [$php, 'update']);
    $r->post('/php/{id}/autostart', [$php, 'autostart']);
    $r->post('/php/{id}/dashboard', [$php, 'dashboard']);
    $r->post('/php/{id}/manager', [$php, 'manager']);
    $r->get('/health', [$php, 'health']);

    // Background jobs
    $jobs = new JobsController($app);
    $r->get('/jobs', [$jobs, 'index']);
    $r->get('/jobs/{id}', [$jobs, 'show']);
    $r->get('/jobs/{id}/log', [$jobs, 'log']);

    // Sites (the static /sites/new is registered before /sites/{id})
    $sites = new SitesController($app);
    $r->get('/sites', [$sites, 'index']);
    $r->get('/sites/new', [$sites, 'create']);
    $r->post('/sites', [$sites, 'store']);
    $r->get('/sites/{id}', [$sites, 'show']);
    $r->post('/sites/{id}', [$sites, 'update']);
    $r->post('/sites/{id}/raw', [$sites, 'saveRaw']);
    $r->post('/sites/{id}/restore', [$sites, 'restore']);
    $r->post('/sites/{id}/toggle', [$sites, 'toggle']);
    $r->post('/sites/{id}/delete', [$sites, 'delete']);

    // Settings
    $settings = new SettingsController($app);
    $r->get('/settings', [$settings, 'index']);
    $r->post('/settings', [$settings, 'save']);

    // PHP extensions
    $ext = new ExtensionsController($app);
    $r->get('/extensions', [$ext, 'index']);
    $r->post('/extensions/{php}', [$ext, 'save']);
    $r->get('/extensions/{php}/search', [$ext, 'search']);
    $r->post('/extensions/{php}/install', [$ext, 'install']);

    // SSL certificates
    $ssl = new SslController($app);
    $r->get('/ssl', [$ssl, 'index']);
    $r->post('/ssl/generate', [$ssl, 'generate']);
    $r->post('/ssl/{folder}/trust', [$ssl, 'trust']);
    $r->post('/ssl/{folder}/untrust', [$ssl, 'untrust']);
    $r->post('/ssl/{folder}/delete', [$ssl, 'delete']);

    // Groups
    $groups = new GroupsController($app);
    $r->get('/groups', [$groups, 'index']);
    $r->post('/groups', [$groups, 'create']);
    $r->post('/groups/{id}', [$groups, 'update']);
    $r->post('/groups/{id}/sites', [$groups, 'sites']);
    $r->post('/groups/{id}/delete', [$groups, 'delete']);

    $r->setNotFound(fn(Request $req) => Response::html(View::render('errors/404', ['title' => 'Not found']), 404));
    return $r;
}
