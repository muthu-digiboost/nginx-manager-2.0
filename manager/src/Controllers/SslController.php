<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : SslController.php
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
 * File: manager/src/Controllers/SslController.php
 * Purpose: SSL Certificates page.
 * Responsibilities:
 *   GET  /ssl[?domain=x]             certificates (expiry, trusted, used by) + create form
 *   POST /ssl/generate               create / renew a certificate, optionally trust it and
 *                                    switch a site to HTTPS with it (safe save + reload)
 *   POST /ssl/{folder}/trust         add to the Windows trust store
 *   POST /ssl/{folder}/untrust       remove from the Windows trust store
 *   POST /ssl/{folder}/delete        delete (refused while a site uses it)
 * Used by: routes.php, sidebar "SSL Certificates", site details ("Create a certificate")
 */

declare(strict_types=1);

namespace NginxManager\Controllers;

use InvalidArgumentException;
use NginxManager\App;
use NginxManager\Http\Flash;
use NginxManager\Http\Request;
use NginxManager\Http\Response;
use NginxManager\Nginx\SiteConfigEditor;
use NginxManager\Nginx\UnsupportedConfigException;
use NginxManager\Ssl\CertificateService;
use NginxManager\Support\View;
use RuntimeException;

final class SslController
{
    public function __construct(private readonly App $app) {}

    public function index(Request $request): Response
    {
        $sites = $this->app->sites()->all();
        return Response::html(View::render('ssl/index', [
            'title'   => 'SSL Certificates',
            'actions' => '<button type="button" class="btn" data-toggle="#create-panel">+ Create Certificate</button>',
            'certs'   => $this->app->certificates()->list($this->siteCertMap()),
            'sites'   => $sites,
            'prefill' => strtolower((string) $request->query('domain', '')),
            'openssl' => $this->app->certificates()->opensslPath(),
        ]));
    }

    public function generate(Request $request): Response
    {
        $domain = strtolower($request->postString('domain'));
        $sans = array_values(array_filter(preg_split('/[\s,]+/', strtolower($request->postString('sans'))) ?: []));
        $applyTo = $request->postString('apply_site');
        try {
            $log = $this->app->certificates()->generate($domain, $sans, $request->postString('trust') === '1');
        } catch (InvalidArgumentException|RuntimeException $e) {
            Flash::add('error', $e->getMessage());
            return Response::redirect('/ssl?domain=' . rawurlencode($domain));
        }
        $failed = in_array('error', array_column($log, 0), true);
        $details = implode("\n", array_map(static fn($l) => strtoupper($l[0]) . '  ' . $l[1], $log));
        Flash::add($failed ? 'error' : (in_array('warn', array_column($log, 0), true) ? 'warn' : 'success'),
            $failed ? "The certificate for $domain could not be created." : "Certificate for $domain is ready.", $details);

        if (!$failed && $applyTo !== '') {
            $this->applyToSite($applyTo, CertificateService::folderFor($domain));
        } elseif (!$failed && $this->folderInUse(CertificateService::folderFor($domain))) {
            // A renewed certificate is only served after nginx reloads.
            $r = $this->app->nginx()->reload();
            if (!$r->ok()) {
                Flash::add('warn', 'Certificate renewed, but nginx could not reload - use Reload nginx.', trim($r->output));
            }
        }
        return Response::redirect('/ssl');
    }

    private function folderInUse(string $folder): bool
    {
        $needle = '/' . $folder . '/';
        foreach ($this->app->sites()->all() as $site) {
            if ($site->parsed->certPath !== null && str_contains($site->parsed->certPath, $needle)) {
                return true;
            }
        }
        return false;
    }

    public function trust(Request $request, array $params): Response
    {
        [$level, $msg] = $this->app->certificates()->trustFolder($this->folder($params['folder']));
        Flash::add($level, $msg);
        return Response::redirect('/ssl');
    }

    public function untrust(Request $request, array $params): Response
    {
        [$level, $msg] = $this->app->certificates()->untrustFolder($this->folder($params['folder']));
        Flash::add($level, $msg);
        return Response::redirect('/ssl');
    }

    public function delete(Request $request, array $params): Response
    {
        try {
            $this->app->certificates()->delete($params['folder'], $this->siteCertMap());
            Flash::add('success', "Certificate {$params['folder']} deleted.");
        } catch (RuntimeException $e) {
            Flash::add('error', $e->getMessage());
        }
        return Response::redirect('/ssl');
    }

    // -------------------------------------------------------------------------

    /** Switches a site to HTTPS with the given certificate (safe save + reload). */
    private function applyToSite(string $siteId, string $folder): void
    {
        $site = $this->app->sites()->find($siteId);
        if ($site === null) {
            Flash::add('warn', 'The selected site no longer exists - certificate created but not applied.');
            return;
        }
        [$cert, $key] = CertificateService::configPaths($folder);
        try {
            $content = SiteConfigEditor::apply($this->app->sites()->read($site->id), ['ssl' => true, 'certPath' => $cert, 'keyPath' => $key]);
        } catch (UnsupportedConfigException $e) {
            Flash::add('warn', "{$site->domain()} could not be switched automatically ({$e->getMessage()}). Edit its Config tab.");
            return;
        }
        $result = $this->app->sites()->save($site->id, $content);
        if (!$result->ok()) {
            Flash::add('error', "nginx rejected HTTPS for {$site->domain()} - the site was left unchanged.", $result->output);
            return;
        }
        $reload = $this->app->nginx()->reload();
        $reload->ok() ? Flash::add('success', "{$site->domain()} now uses HTTPS.")
                      : Flash::add('warn', "{$site->domain()} switched to HTTPS, but nginx was not reloaded.", $reload->output);
    }

    /** @return array<string, string> site id => certificate path from its config */
    private function siteCertMap(): array
    {
        $map = [];
        foreach ($this->app->sites()->all() as $site) {
            if ($site->parsed->certPath) {
                $map[$site->id] = $site->parsed->certPath;
            }
        }
        return $map;
    }

    private function folder(string $folder): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9.-]*$/', $folder) || str_contains($folder, '..')) {
            throw new InvalidArgumentException('Invalid certificate name.');
        }
        return $folder;
    }
}
