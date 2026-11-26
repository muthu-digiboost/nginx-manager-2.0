<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : DashboardController.php
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
 * File: manager/src/Controllers/DashboardController.php
 * Purpose: The landing page ("/") - wp-admin style welcome panel and widgets.
 * Responsibilities: gather status data (nginx, sites, PHP services, recent jobs)
 *                   and render views/dashboard.php
 * Used by: routes.php  GET /
 * Notes: must stay fast and offline-safe - it never calls the internet.
 */

declare(strict_types=1);

namespace NginxManager\Controllers;

use NginxManager\App;
use NginxManager\Http\Request;
use NginxManager\Http\Response;
use NginxManager\Support\View;

final class DashboardController
{
    public function __construct(private readonly App $app) {}

    public function index(Request $request): Response
    {
        $sites = $this->app->sites()->all();
        $disabled = count(array_filter($sites, static fn($s) => !$s->enabled));

        return Response::html(View::render('dashboard', [
            'title'        => 'Dashboard',
            'nginxRunning' => $this->app->nginx()->isRunning(),
            'php'          => $php = $this->app->phpVersions()->installed(),
            'pinned'       => array_values(array_filter($php, static fn($v) => $v['dashboard'])),
            'updates'      => $this->app->phpUpdateCount(),
            'groupCount'   => count($this->app->groups()->all()),
            'certCount'    => count(glob(\NginxManager\Support\Paths::in('ssl') . '/*/ssl.crt/server.crt') ?: []),
            'siteCount'    => count($sites),
            'siteDisabled' => $disabled,
            'jobs'         => $this->app->jobs()->recent(6),
            'siteCounts'   => array_count_values(array_map(static fn($s) => (string) $s->parsed->upstream, $sites)),
        ]));
    }
}
