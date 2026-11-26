<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : NginxController.php
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
 * File: manager/src/Controllers/NginxController.php
 * Purpose: Global nginx actions available from every page (sidebar "Reload Nginx").
 * Responsibilities: POST /nginx/reload -> test + reload, flash the result, go back
 * Used by: routes.php, views/partials/sidebar.php
 */

declare(strict_types=1);

namespace NginxManager\Controllers;

use NginxManager\App;
use NginxManager\Http\Flash;
use NginxManager\Http\Request;
use NginxManager\Http\Response;

final class NginxController
{
    public function __construct(private readonly App $app) {}

    public function reload(Request $request): Response
    {
        $result = $this->app->nginx()->reload();
        if ($result->ok()) {
            Flash::add('success', 'nginx configuration is valid and was reloaded.');
        } else {
            Flash::add('error', 'nginx was NOT reloaded - the running configuration is unchanged.', $result->output);
        }
        return Response::redirect(self::back($request));
    }

    /** Same-origin path to return to (never an external URL). */
    public static function back(Request $request, string $fallback = '/'): string
    {
        $ref = (string) $request->server('HTTP_REFERER', '');
        $path = (string) parse_url($ref, PHP_URL_PATH);
        $query = (string) parse_url($ref, PHP_URL_QUERY);
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $fallback;
        }
        return $path . ($query !== '' ? '?' . $query : '');
    }
}
