<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : GroupsController.php
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
 * File: manager/src/Controllers/GroupsController.php
 * Purpose: Groups page - organise sites into groups (per client, product, team ...).
 * Responsibilities:
 *   GET  /groups                 one card per group (colour, sites) + "Ungrouped" + create form
 *   POST /groups                 create a group (name, colour)
 *   POST /groups/{id}            rename / recolour
 *   POST /groups/{id}/sites      set which sites belong to the group (ticked = member)
 *   POST /groups/{id}/delete     delete the group; its sites become ungrouped
 * Used by: routes.php, sidebar "Groups", Sites page ("Manage groups")
 */

declare(strict_types=1);

namespace NginxManager\Controllers;

use InvalidArgumentException;
use NginxManager\App;
use NginxManager\Groups\GroupRepository;
use NginxManager\Http\Flash;
use NginxManager\Http\Request;
use NginxManager\Http\Response;
use NginxManager\Nginx\Site;
use NginxManager\Support\View;

final class GroupsController
{
    public function __construct(private readonly App $app) {}

    public function index(Request $request): Response
    {
        $sites = $this->app->sites()->all();
        $groups = $this->app->groups();
        if (!$groups->exists() && $sites) {
            $groups->seedFromSites(array_map(static fn(Site $s) => $s->id, $sites));
        }
        return Response::html(View::render('groups/index', [
            'title'   => 'Groups',
            // Always next to the title: opens the create panel, or - when the empty state
            // already shows the form - puts the cursor in its name box.
            'actions' => $groups->all() || $sites
                ? '<button type="button" class="btn" data-toggle="#new-group">+ Add New Group</button>'
                : '<button type="button" class="btn" data-focus="#group-name">+ Add New Group</button>',
            'groups'  => $groups->all(),
            'assign'  => $groups->assignments(),
            'sites'   => $sites,
            'colors'  => GroupRepository::COLORS,
            'php'     => array_column($this->app->phpVersions()->installed(), null, 'upstream'),
        ]));
    }

    public function create(Request $request): Response
    {
        try {
            $g = $this->app->groups()->create($request->postString('name'), $request->postString('color') ?: null);
            Flash::add('success', "Group \"{$g['name']}\" created.");
        } catch (InvalidArgumentException $e) {
            Flash::add('error', $e->getMessage());
        }
        return Response::redirect('/groups');
    }

    public function update(Request $request, array $params): Response
    {
        try {
            $this->app->groups()->update($params['id'], $request->postString('name'), $request->postString('color'));
            Flash::add('success', 'Group saved.');
        } catch (InvalidArgumentException $e) {
            Flash::add('error', $e->getMessage());
        }
        return Response::redirect('/groups#group-' . rawurlencode($params['id']));
    }

    public function sites(Request $request, array $params): Response
    {
        $groups = $this->app->groups();
        $id = $params['id'];
        if ($groups->find($id) === null) {
            Flash::add('error', 'Group not found.');
            return Response::redirect('/groups');
        }
        $wanted = array_fill_keys($request->postList('sites'), true);
        $known = array_map(static fn(Site $s) => $s->id, $this->app->sites()->all());
        $added = $removed = 0;
        foreach ($known as $siteId) {
            $in = $groups->groupOf($siteId) === $id;
            if (isset($wanted[$siteId]) && !$in) {
                $groups->assign($siteId, $id);
                $added++;
            } elseif (!isset($wanted[$siteId]) && $in) {
                $groups->assign($siteId, null);
                $removed++;
            }
        }
        Flash::add('success', "Sites updated ($added added, $removed removed).");
        return Response::redirect('/groups#group-' . rawurlencode($id));
    }

    public function delete(Request $request, array $params): Response
    {
        $g = $this->app->groups()->find($params['id']);
        if ($g !== null) {
            $this->app->groups()->delete($g['id']);
            Flash::add('success', "Group \"{$g['name']}\" deleted - its sites are now ungrouped.");
        }
        return Response::redirect('/groups');
    }
}
