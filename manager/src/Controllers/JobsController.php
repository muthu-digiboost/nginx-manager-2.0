<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : JobsController.php
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
 * File: manager/src/Controllers/JobsController.php
 * Purpose: Progress pages for background jobs.
 * Responsibilities:
 *   GET /jobs             activity history (search + pagination)
 *   GET /jobs/{id}        live log page (views/jobs/show.php), ?back=<path> to return to
 *   GET /jobs/{id}/log    JSON {text, offset, state, message} - polled by the page
 * Used by: routes.php, every action that starts a JobRunner job
 */

declare(strict_types=1);

namespace NginxManager\Controllers;

use NginxManager\App;
use NginxManager\Http\Request;
use NginxManager\Http\Response;
use NginxManager\Support\View;

final class JobsController
{
    public function __construct(private readonly App $app) {}

    /** GET /jobs - full activity history with search and pagination. */
    public function index(Request $request): Response
    {
        return Response::html(View::render('jobs/index', [
            'title' => 'Activity',
            'jobs'  => $this->app->jobs()->recent(500),
        ]));
    }

    public function show(Request $request, array $params): Response
    {
        $job = $this->app->jobs()->status($params['id']);
        if ($job === null) {
            return Response::html(View::render('errors/404', ['title' => 'Job not found']), 404);
        }
        $back = (string) $request->query('back', '/');
        if (!str_starts_with($back, '/') || str_starts_with($back, '//')) {
            $back = '/';
        }
        return Response::html(View::render('jobs/show', ['title' => 'Background job', 'job' => $job, 'back' => $back]));
    }

    public function log(Request $request, array $params): Response
    {
        $offset = (int) $request->query('offset', 0);
        $data = $this->app->jobs()->log($params['id'], $offset);
        return Response::json($data, $data['state'] === 'unknown' ? 404 : 200);
    }
}
