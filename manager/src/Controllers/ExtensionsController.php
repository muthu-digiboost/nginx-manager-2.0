<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : ExtensionsController.php
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
 * File: manager/src/Controllers/ExtensionsController.php
 * Purpose: cPanel-style PHP Extensions page.
 * Responsibilities:
 *   GET  /extensions?php=<id>              checkbox grid of every extension of that PHP version
 *   POST /extensions/{php}                 save the changed checkboxes (php.ini checked; rolled
 *                                          back with PHP's message when an extension fails to load)
 *   GET  /extensions/{php}/search?ext=x    JSON: newest Windows build of a PECL extension
 *   POST /extensions/{php}/install         download + install (+ enable) a PECL extension as a job
 * Used by: routes.php, sidebar "PHP Extensions"
 */

declare(strict_types=1);

namespace NginxManager\Controllers;

use InvalidArgumentException;
use NginxManager\App;
use NginxManager\Http\Flash;
use NginxManager\Http\Request;
use NginxManager\Http\Response;
use NginxManager\Mac\Brew;
use NginxManager\Support\Platform;
use NginxManager\Support\View;
use RuntimeException;

final class ExtensionsController
{
    public function __construct(private readonly App $app) {}

    public function index(Request $request): Response
    {
        $installed = $this->app->phpVersions()->installed();
        $ids = array_column($installed, 'id');
        $phpId = (string) $request->query('php', $this->app->settings()->managerVersion());
        if (!in_array($phpId, $ids, true)) {
            $phpId = $ids[0] ?? '';
        }
        return Response::html(View::render('extensions/index', [
            'title'      => 'PHP Extensions',
            'installed'  => $installed,
            'phpId'      => $phpId,
            'php'        => $phpId !== '' ? $this->app->phpVersions()->find($phpId) : null,
            'extensions' => $phpId !== '' ? $this->app->extensions()->list($phpId) : [],
            'popular'    => $this->app->pecl()->popular(),
            'draft'      => $_SESSION['_extDraft'][$phpId] ?? null,
        ]));
    }

    public function save(Request $request, array $params): Response
    {
        $phpId = $params['php'];
        $back = '/extensions?php=' . rawurlencode($phpId);
        $wanted = array_fill_keys($request->postList('ext'), true);
        $changes = [];
        try {
            foreach ($this->app->extensions()->list($phpId) as $e) {
                if ($e['builtin'] ?? false) {
                    continue;   // compiled into PHP (macOS / Homebrew): cannot be switched
                }
                $on = isset($wanted[$e['name']]);
                if ($on !== $e['enabled']) {
                    $changes[$e['name']] = $on;
                }
            }
            if (!$changes) {
                Flash::add('info', 'Nothing changed.');
                return Response::redirect($back);
            }
            $res = $this->app->extensions()->setEnabled($phpId, $changes);
        } catch (InvalidArgumentException|RuntimeException $e) {
            Flash::add('error', $e->getMessage());
            return Response::redirect($back);
        }

        if (!$res['ok']) {
            $_SESSION['_extDraft'][$phpId] = array_keys($wanted);
            Flash::add('error', 'PHP could not load one of the selected extensions, so php.ini was left unchanged.', $res['output']);
            return Response::redirect($back);
        }
        unset($_SESSION['_extDraft'][$phpId]);
        $summary = implode(', ', array_map(static fn($n, $on) => ($on ? '+' : '−') . $n, array_keys($changes), $changes));
        Flash::add('success', "PHP $phpId extensions saved ($summary).");
        if ($res['jobId']) {
            return Response::redirect('/jobs/' . $res['jobId'] . '?back=' . rawurlencode($back));
        }
        return Response::redirect($back);
    }

    public function search(Request $request, array $params): Response
    {
        $php = $this->app->phpVersions()->find($params['php']);
        $ext = strtolower(trim((string) $request->query('ext', '')));
        if ($php === null) {
            return Response::json(['found' => false, 'message' => 'Unknown PHP version.'], 404);
        }
        if (!Platform::isWindows()) {
            try {
                $f = (new Brew($this->app->shell()))->findExtension($ext, $php['branch']);
            } catch (InvalidArgumentException $e) {
                return Response::json(['found' => false, 'message' => $e->getMessage()], 422);
            }
            return Response::json($f === null
                ? ['found' => false, 'message' => "Homebrew (" . Brew::EXT_TAP . ") has no $ext for PHP {$php['branch']}."]
                : ['found' => true, 'ext' => $f['ext'], 'version' => $f['version'], 'php' => $php['branch'],
                   'flavour' => 'Homebrew', 'zip' => $f['formula']]);
        }
        try {
            $build = $this->app->pecl()->findBuild($ext, $php['branch'], $php['threadSafety']);
        } catch (InvalidArgumentException $e) {
            return Response::json(['found' => false, 'message' => $e->getMessage()], 422);
        }
        if ($build === null) {
            return Response::json(['found' => false, 'message' => "No Windows build of $ext for PHP {$php['branch']} " . strtoupper($php['threadSafety']) . ' x64.']);
        }
        return Response::json(['found' => true] + $build + ['php' => $php['branch'], 'flavour' => strtoupper($php['threadSafety'])]);
    }

    public function install(Request $request, array $params): Response
    {
        $php = $this->app->phpVersions()->find($params['php']);
        $back = '/extensions?php=' . rawurlencode($params['php']);
        $ext = strtolower($request->postString('ext'));
        if (!Platform::isWindows()) {
            if ($php === null || !preg_match('/^[a-z0-9_]{2,40}$/', $ext)) {
                Flash::add('error', 'Unknown PHP version or invalid extension name.');
                return Response::redirect($back);
            }
            $jobId = $this->app->jobs()->start('install-extension.ps1', [
                'Id' => $php['id'], 'Ext' => $ext, 'Enable' => $request->postString('enable') === '1' ? '1' : '0',
            ], "Install $ext for PHP {$php['id']}");
            return Response::redirect('/jobs/' . $jobId . '?back=' . rawurlencode($back));
        }
        try {
            if ($php === null) {
                throw new InvalidArgumentException('Unknown PHP version.');
            }
            $build = $this->app->pecl()->findBuild($ext, $php['branch'], $php['threadSafety']);
            if ($build === null) {
                throw new InvalidArgumentException("No Windows build of $ext for PHP {$php['branch']}.");
            }
        } catch (InvalidArgumentException $e) {
            Flash::add('error', $e->getMessage());
            return Response::redirect($back);
        }
        $jobId = $this->app->jobs()->start('install-extension.ps1', [
            'Id'      => $php['id'],
            'Ext'     => $build['ext'],
            'Version' => $build['version'],
            'ZipName' => $build['zip'],
            'Enable'  => $request->postString('enable') === '1' ? '1' : '0',
        ], "Install {$build['ext']} {$build['version']} for PHP {$php['id']}");
        return Response::redirect('/jobs/' . $jobId . '?back=' . rawurlencode($back));
    }
}
