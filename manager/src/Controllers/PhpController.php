<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : PhpController.php
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
 * File: manager/src/Controllers/PhpController.php
 * Purpose: PHP Versions page - WordPress-style updates, installed versions, Add PHP Version.
 * Responsibilities:
 *   GET  /php                  updates available, installed versions (status, sites, start/stop,
 *                              autostart) and the Add PHP Version card (every branch 5.5 -> newest)
 *   POST /php/refresh          re-read the php.net catalogues now
 *   POST /php/install          branch + version + ts/nts -> install-php.ps1 job
 *   POST /php/{id}/update      newest patch of that branch -> install-php.ps1 job
 *   POST /php/service          start | stop | restart one (?only=<id>) or many (ids[]) as a job
 *   POST /php/{id}/autostart   Auto-start on/off
 *   POST /php/{id}/dashboard   show / hide the version on the dashboard
 * Used by: routes.php; the dashboard's PHP Services widget posts here too
 * Notes: all long work runs detached through JobRunner, so updating the manager's own PHP
 *        is safe - the job page reconnects when it is back.
 */

declare(strict_types=1);

namespace NginxManager\Controllers;

use InvalidArgumentException;
use NginxManager\App;
use NginxManager\Http\Flash;
use NginxManager\Http\Request;
use NginxManager\Http\Response;
use NginxManager\Mac\BrewCatalog;
use NginxManager\Support\Platform;
use NginxManager\Php\PhpCatalog;
use NginxManager\Php\PhpVersionRepository;
use NginxManager\Php\UpdateChecker;
use NginxManager\Support\Env;
use NginxManager\Support\View;

final class PhpController
{
    public function __construct(private readonly App $app) {}

    public function index(Request $request): Response
    {
        if (!Platform::isWindows()) {
            return $this->indexMac();
        }
        $installed = $this->app->phpVersions()->installed();
        $releases = $this->app->phpReleases()->releases();
        $archives = $this->app->phpArchives()->versions();

        $siteCounts = [];
        foreach ($this->app->sites()->all() as $site) {
            $up = $site->parsed->upstream ?? '';
            $siteCounts[$up] = ($siteCounts[$up] ?? 0) + 1;
        }

        $errors = array_filter([$this->app->phpReleases()->lastError(), $this->app->phpArchives()->lastError()]);
        return Response::html(View::render('php/index', [
            'title'      => 'PHP Versions',
            'actions'    => '<a class="btn btn-ghost" href="#add-php">Add PHP Version</a>'
                . '<form method="post" action="/php/refresh" style="display:inline">' . csrf_field()
                . '<button class="btn btn-ghost" type="submit">Check for updates</button></form>',
            'php'        => $installed,
            'siteCounts' => $siteCounts,
            'updates'    => UpdateChecker::compare($installed, $releases),
            'catalog'    => PhpCatalog::merge($releases, $archives),
            'checkedAt'  => $this->app->phpReleases()->checkedAt(),
            'feedErrors' => $errors,
            'defaultTs'  => Env::get('PHP_THREAD_SAFETY', 'ts') === 'nts' ? 'nts' : 'ts',
            'managerCandidates' => $this->app->phpVersions()->managerCandidates(),
        ]));
    }

    /** macOS: Homebrew catalogue (shivammathur/php tap) and "brew outdated" updates. */
    private function indexMac(): Response
    {
        $installed = $this->app->phpVersions()->installed();
        $siteCounts = [];
        foreach ($this->app->sites()->all() as $site) {
            $up = $site->parsed->upstream ?? '';
            $siteCounts[$up] = ($siteCounts[$up] ?? 0) + 1;
        }
        $catalog = $this->app->brewCatalog();
        $error = $catalog->lastError();
        return Response::html(View::render('php/index', [
            'title'      => 'PHP Versions',
            'actions'    => '<a class="btn btn-ghost" href="#add-php">Add PHP Version</a>'
                . '<form method="post" action="/php/refresh" style="display:inline">' . csrf_field()
                . '<button class="btn btn-ghost" type="submit">Check for updates</button></form>',
            'php'        => $installed,
            'siteCounts' => $siteCounts,
            'updates'    => $catalog->compare($installed),
            'catalog'    => [],
            'macBranches' => BrewCatalog::branches(),
            'checkedAt'  => $catalog->checkedAt(),
            'feedErrors' => $error !== null ? [$error] : [],
            'defaultTs'  => 'nts',
            'managerCandidates' => $this->app->phpVersions()->managerCandidates(),
        ]));
    }

    public function refresh(Request $request): Response
    {
        if (!Platform::isWindows()) {
            $this->app->brewCatalog()->outdated(true);
            $error = $this->app->brewCatalog()->lastError();
            $error ? Flash::add('error', 'Homebrew could not check for updates - showing the last saved list.', $error)
                   : Flash::add('success', 'Checked Homebrew for PHP updates.');
            return Response::redirect('/php');
        }
        $this->app->phpReleases()->releases(true);
        $this->app->phpArchives()->versions(true);
        $error = $this->app->phpReleases()->lastError() ?? $this->app->phpArchives()->lastError();
        $error ? Flash::add('error', 'Could not reach php.net - showing the last saved list.', $error)
               : Flash::add('success', 'PHP version list refreshed from php.net.');
        return Response::redirect('/php');
    }

    public function install(Request $request): Response
    {
        if (!Platform::isWindows()) {
            $branch = $request->postString('branch');
            if (!in_array($branch, BrewCatalog::BRANCHES, true) || $this->app->settings()->phpVersion($branch) !== null) {
                Flash::add('error', "PHP $branch cannot be installed (unknown or already installed).");
                return Response::redirect('/php#add-php');
            }
            $jobId = $this->app->jobs()->start('install-php.ps1', ['Mode' => 'install', 'Id' => $branch], "Install PHP $branch");
            return Response::redirect('/jobs/' . $jobId . '?back=' . rawurlencode('/php'));
        }
        $branch = $request->postString('branch');
        $version = $request->postString('version');
        $flavour = $request->postString('flavour') === 'nts' ? 'nts' : 'ts';
        try {
            $catalog = PhpCatalog::merge($this->app->phpReleases()->releases(), $this->app->phpArchives()->versions());
            $build = PhpCatalog::find($catalog, $version, $flavour);
            if ($build === null || PhpVersionRepository::branchOf($version) !== $branch) {
                throw new InvalidArgumentException("No " . strtoupper($flavour) . " x64 build of PHP $version was found on php.net.");
            }
            $id = $this->app->phpVersions()->installTargetId($branch, $version);
        } catch (InvalidArgumentException $e) {
            Flash::add('error', $e->getMessage());
            return Response::redirect('/php#add-php');
        }
        return $this->startInstallJob('install', $id, $build, $flavour, "Install PHP $version");
    }

    public function update(Request $request, array $params): Response
    {
        $id = $params['id'];
        $installed = $this->app->phpVersions()->installed();
        if (!Platform::isWindows()) {
            foreach ($this->app->brewCatalog()->compare($installed)['updates'] as $u) {
                if ($u['id'] === $id) {
                    $jobId = $this->app->jobs()->start('install-php.ps1', ['Mode' => 'update', 'Id' => $id], "Update PHP $id to {$u['latest']}");
                    return Response::redirect('/jobs/' . $jobId . '?back=' . rawurlencode('/php'));
                }
            }
            Flash::add('error', "No update is available for PHP $id.");
            return Response::redirect('/php');
        }
        $result = UpdateChecker::compare($installed, $this->app->phpReleases()->releases());
        foreach ($result['updates'] as $u) {
            if ($u['id'] === $id && $u['build'] !== null) {
                $build = ['version' => $u['latest'], 'location' => 'releases'] + $u['build'];
                return $this->startInstallJob('update', $id, $build, $u['threadSafety'] === 'nts' ? 'nts' : 'ts',
                    "Update PHP $id to {$u['latest']}");
            }
        }
        Flash::add('error', "No update is available for PHP $id.");
        return Response::redirect('/php');
    }

    public function service(Request $request): Response
    {
        $back = NginxController::back($request, '/php');
        $action = $request->postString('action');
        $only = $request->query('only');
        $ids = is_string($only) && $only !== '' ? [$only] : $request->postList('ids');

        try {
            $ids = $this->app->phpVersions()->validateSelection($ids, $action);
        } catch (InvalidArgumentException $e) {
            Flash::add('error', $e->getMessage());
            return Response::redirect($back);
        }

        $title = ucfirst($action) . ' PHP ' . implode(', ', $ids);
        $jobId = $this->app->jobs()->start('php-service.ps1', ['Action' => $action, 'Id' => implode(',', $ids)], $title);
        return Response::redirect('/jobs/' . $jobId . '?back=' . rawurlencode($back));
    }

    /**
     * POST /php/{id}/manager - run the manager itself on another PHP version.
     * The job checks compatibility with that PHP first (lint, tests, deprecations), enables
     * missing required extensions, switches, and switches back if the UI does not come up.
     */
    public function manager(Request $request, array $params): Response
    {
        try {
            $id = $this->app->phpVersions()->validateManagerTarget($params['id']);
        } catch (InvalidArgumentException $e) {
            Flash::add('error', $e->getMessage());
            return Response::redirect('/php#manager-php');
        }
        $jobId = $this->app->jobs()->start('switch-manager-php.ps1', ['Id' => $id], "Run the manager on PHP $id");
        return Response::redirect('/jobs/' . $jobId . '?back=' . rawurlencode('/php'));
    }

    /**
     * GET /health - the switch job checks that the manager answers on the new PHP; the
     * desktop app also reads "base" to find the install without asking. Only reachable
     * from this computer (127.0.0.1 + Host allowlist).
     */
    public function health(Request $request): Response
    {
        return Response::json(['ok' => true, 'php' => PHP_VERSION, 'base' => \NginxManager\Support\Paths::base()]);
    }

    public function autostart(Request $request, array $params): Response
    {
        $on = $request->postString('on') === '1';
        try {
            $this->app->phpVersions()->setAutostart($params['id'], $on);
            Flash::add('success', "PHP {$params['id']}: Auto-start " . ($on ? 'on.' : 'off.'));
        } catch (InvalidArgumentException $e) {
            Flash::add('error', $e->getMessage());
        }
        return Response::redirect(NginxController::back($request, '/php'));
    }

    /** POST /php/{id}/dashboard - pin / unpin a version on the dashboard's PHP Services card. */
    public function dashboard(Request $request, array $params): Response
    {
        $on = $request->postString('on') === '1';
        try {
            $this->app->phpVersions()->setDashboard($params['id'], $on);
            Flash::add('success', "PHP {$params['id']} " . ($on ? 'now shows on the dashboard.' : 'removed from the dashboard.'));
        } catch (InvalidArgumentException $e) {
            Flash::add('error', $e->getMessage());
        }
        return Response::redirect(NginxController::back($request, '/php'));
    }

    /** @param array{version:string, location:string, zip:string, sha256:?string} $build */
    private function startInstallJob(string $mode, string $id, array $build, string $flavour, string $title): Response
    {
        $args = [
            'Mode'         => $mode,
            'Id'           => $id,
            'Version'      => $build['version'],
            'ZipName'      => $build['zip'],
            'Location'     => $build['location'],
            'ThreadSafety' => $flavour,
        ];
        if (!empty($build['sha256'])) {
            $args['Sha256'] = $build['sha256'];
        }
        $jobId = $this->app->jobs()->start('install-php.ps1', $args, $title);
        return Response::redirect('/jobs/' . $jobId . '?back=' . rawurlencode('/php'));
    }
}
