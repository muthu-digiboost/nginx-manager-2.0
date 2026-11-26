<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : SitesController.php
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
 * File: manager/src/Controllers/SitesController.php
 * Purpose: Everything on the Sites pages.
 * Responsibilities:
 *   GET  /sites                  list grouped by site group, with search
 *   GET  /sites/new              "Add New Site" form
 *   POST /sites                  create: config file -> nginx -t -> hosts entry -> group -> reload
 *   GET  /sites/{id}             details: Overview (editable), Config (raw editor), Logs, Backups
 *   POST /sites/{id}             save Overview fields (domains, root, PHP, group, HTTPS)
 *   POST /sites/{id}/raw         save the raw config (nginx -t first; rolled back on error)
 *   POST /sites/{id}/restore     put a backup back (same safe save)
 *   POST /sites/{id}/toggle      enable / disable (file renamed to .conf.disabled)
 *   POST /sites/{id}/delete      move to backups/deleted (+ optional hosts entry removal)
 * Used by: routes.php
 * Notes:
 *   - project folders are entered as <project root from .env> + relative path and stored
 *     as "$projects_dir[_x]/..." - never an absolute path
 *   - every change regenerates the derived nginx files and reloads nginx once
 */

declare(strict_types=1);

namespace NginxManager\Controllers;

use InvalidArgumentException;
use NginxManager\App;
use NginxManager\Http\Flash;
use NginxManager\Http\Request;
use NginxManager\Http\Response;
use NginxManager\Nginx\NginxConfigWriter;
use NginxManager\Nginx\Site;
use NginxManager\Nginx\SiteConfigBuilder;
use NginxManager\Nginx\SiteConfigEditor;
use NginxManager\Nginx\SiteRepository;
use NginxManager\Nginx\SiteSpec;
use NginxManager\Nginx\UnsupportedConfigException;
use NginxManager\Support\Paths;
use NginxManager\Support\Validator;
use NginxManager\Support\View;
use RuntimeException;
use Throwable;

final class SitesController
{
    public function __construct(private readonly App $app) {}

    // -- pages -----------------------------------------------------------------------

    public function index(Request $request): Response
    {
        $sites = $this->app->sites()->all();
        $groups = $this->app->groups();
        if (!$groups->exists() && $sites) {
            $groups->seedFromSites(array_map(static fn(Site $s) => $s->id, $sites));
        }
        $assign = $groups->assignments();
        $byGroup = [];
        foreach ($sites as $site) {
            $byGroup[$assign[$site->id] ?? ''][] = $site;
        }
        return Response::html(View::render('sites/index', [
            'title'    => 'Sites',
            'actions'  => '<a class="btn" href="/sites/new">Add New Site</a>',
            'groups'   => $groups->all(),
            'byGroup'  => $byGroup,
            'total'    => count($sites),
            'php'      => $this->phpByUpstream(),
            'hosts'    => $this->app->hosts(),
        ]));
    }

    public function create(Request $request): Response
    {
        return Response::html(View::render('sites/create', [
            'title'  => 'Add New Site',
            'crumbs' => [['Sites', '/sites']],
        ] + $this->formData()));
    }

    public function store(Request $request): Response
    {
        $old = $request;
        try {
            $domain = strtolower($request->postString('domain'));
            if (!Validator::domain($domain)) {
                throw new InvalidArgumentException('Enter a valid domain such as myapp.test (lowercase, at least one dot).');
            }
            $aliases = $this->aliases($request->postString('aliases'));
            [$root, $absolute] = $this->rootFromRequest($request);
            $php = $this->phpFromRequest($request);
            $framework = $request->postString('framework', 'general');
            if (!isset(SiteConfigBuilder::FRAMEWORKS[$framework])) {
                throw new InvalidArgumentException('Choose a framework.');
            }
            $id = SiteRepository::idFor($domain);
            $ssl = $request->postString('ssl') === '1';
            [$cert, $key] = $this->certPaths($id);
            if ($ssl && !$this->certExists($id)) {
                throw new InvalidArgumentException("No certificate for $domain yet - create it on the SSL Certificates page first, or add the site without HTTPS.");
            }
            $groupId = $this->groupFromRequest($request);

            $result = $this->app->sites()->create(new SiteSpec($domain, $aliases, $root, $php['id'], $php['upstream'], $framework, $ssl, $ssl ? $cert : null, $ssl ? $key : null));
            if (!$result->ok()) {
                Flash::add('error', 'nginx rejected the new configuration - nothing was saved.', $result->output);
                return $this->backToForm($old);
            }
        } catch (InvalidArgumentException|RuntimeException $e) {
            Flash::add('error', $e->getMessage());
            return $this->backToForm($old);
        }

        if ($groupId !== null) {
            $this->app->groups()->assign($id, $groupId);
        }
        if (!is_dir($absolute)) {
            Flash::add('warn', "The project folder $absolute does not exist yet - nginx will answer 404 until it does.");
        }
        if ($request->postString('hosts') === '1') {
            $this->hostsAdd(array_merge([$domain], $aliases));
        }
        $this->warnIfPhpStopped($php);
        $this->reload("Site $domain created.");
        return Response::redirect('/sites/' . rawurlencode($id));
    }

    public function show(Request $request, array $params): Response
    {
        $site = $this->app->sites()->find($params['id']);
        if ($site === null) {
            return $this->notFound();
        }
        $p = $site->parsed;
        $logPath = static fn(?string $rel) => $rel === null ? null : (Validator::absolutePath($rel) ? $rel : Paths::in($rel));
        $errorLog = $logPath($p->errorLog);
        $accessLog = $logPath($p->accessLog);

        return Response::html(View::render('sites/show', [
            'title'      => $site->domain(),
            'crumbs'     => [['Sites', '/sites']],
            'site'       => $site,
            'raw'        => $this->app->sites()->read($site->id),
            'rootAbs'    => $p->root ? Paths::resolveRoot($p->root) : null,
            'groupId'    => $this->app->groups()->groupOf($site->id),
            'certExists' => $this->certExists($site->id),
            'errorLog'   => $errorLog,
            'accessLog'  => $accessLog,
            'errorTail'  => $errorLog ? tail_file($errorLog, 200) : '',
            'accessTail' => $accessLog ? tail_file($accessLog, 100) : '',
            'backups'    => $this->app->sites()->backups($site->id),
            'inHosts'    => $this->app->hosts()->has($site->domain()),
        ] + $this->formData()));
    }

    // -- edits ---------------------------------------------------------------------------

    public function update(Request $request, array $params): Response
    {
        $site = $this->app->sites()->find($params['id']);
        if ($site === null) {
            return $this->notFound();
        }
        $back = '/sites/' . rawurlencode($site->id);
        try {
            $names = $this->aliases(strtolower($request->postString('domains')));
            if (!$names) {
                throw new InvalidArgumentException('At least one domain is required.');
            }
            // The project folder is only parsed when the user changed it, so a site whose
            // root is outside the project folders (or hand-written) can still be saved.
            $rootChanged = $request->postString('root_var') !== $request->postString('root_var_orig')
                || $request->postString('root_path') !== $request->postString('root_path_orig');
            $root = $rootChanged ? $this->rootFromRequest($request)[0] : null;
            // "" = keep (sites without PHP, e.g. static or proxied, show "Not a PHP site").
            $php = $request->postString('php') === '' ? null : $this->phpFromRequest($request);
            $ssl = $request->postString('ssl') === '1';
            [$cert, $key] = $this->certPaths($site->id);
            if ($ssl && !$site->parsed->ssl && !$this->certExists($site->id)) {
                throw new InvalidArgumentException('No certificate for this site yet - create it on the SSL Certificates page first.');
            }

            $changes = self::formChanges($site->parsed, $names, $root, $php);
            if ($ssl !== $site->parsed->ssl) {
                $changes['ssl'] = $ssl;
                if ($ssl) {
                    [$changes['certPath'], $changes['keyPath']] = [$cert, $key];
                }
            }
            $content = $changes ? SiteConfigEditor::apply($this->app->sites()->read($site->id), $changes) : null;
            $groupId = $this->groupFromRequest($request);
        } catch (UnsupportedConfigException $e) {
            Flash::add('error', $e->getMessage() . ' This file was edited by hand in a way the form cannot change safely - use the Config tab.');
            return Response::redirect($back);
        } catch (InvalidArgumentException $e) {
            Flash::add('error', $e->getMessage());
            return Response::redirect($back);
        }

        $this->app->groups()->assign($site->id, $groupId);
        if ($content === null) {
            Flash::add('success', 'Site settings saved (the nginx config did not need to change).');
            return Response::redirect($back);
        }
        return $this->safeSave($site->id, $content, $back, 'Site settings saved.', $php);
    }

    /**
     * Only the fields the user really changed (final review #2): unchanged fields are never
     * rewritten, so hand-edited parts of a config survive saving the form.
     *
     * @param list<string> $names  domains from the form
     * @param ?string      $root   new nginx root, or null when the folder fields were not changed
     * @param ?array       $php    chosen PHP entry (id, upstream), or null for "keep / not a PHP site"
     */
    public static function formChanges(\NginxManager\Nginx\ParsedSite $p, array $names, ?string $root, ?array $php): array
    {
        $changes = [];
        if ($names !== $p->serverNames) {
            $changes['serverNames'] = $names;
        }
        if ($root !== null && $root !== $p->root) {
            $changes['root'] = $root;
        }
        if ($php !== null && $php['upstream'] !== $p->upstream) {
            $changes['upstream'] = $php['upstream'];
            $changes['phpLabel'] = $php['id'];
        }
        return $changes;
    }

    public function saveRaw(Request $request, array $params): Response
    {
        $site = $this->app->sites()->find($params['id']);
        if ($site === null) {
            return $this->notFound();
        }
        $content = str_replace("\r\n", "\n", (string) $request->post('content', ''));
        if (trim($content) === '') {
            Flash::add('error', 'The configuration cannot be empty. To remove the site use Delete.');
            return Response::redirect('/sites/' . rawurlencode($site->id) . '#config');
        }
        $_SESSION['_draft'][$site->id] = $content;   // kept if nginx rejects it
        return $this->safeSave($site->id, $content, '/sites/' . rawurlencode($site->id) . '#config', 'Configuration saved.');
    }

    public function restore(Request $request, array $params): Response
    {
        $id = $params['id'];
        try {
            $content = $this->app->sites()->backupContent($id, $request->postString('name'));
        } catch (RuntimeException $e) {
            Flash::add('error', $e->getMessage());
            return Response::redirect('/sites/' . rawurlencode($id) . '#backups');
        }
        return $this->safeSave($id, $content, '/sites/' . rawurlencode($id), 'Backup restored (the previous version was backed up too).');
    }

    public function toggle(Request $request, array $params): Response
    {
        $site = $this->app->sites()->find($params['id']);
        if ($site === null) {
            return $this->notFound();
        }
        $result = $this->app->sites()->setEnabled($site->id, !$site->enabled);
        if (!$result->ok()) {
            Flash::add('error', 'The site could not be enabled - nginx rejected its configuration.', $result->output);
        } else {
            $this->reload($site->enabled ? "{$site->domain()} disabled." : "{$site->domain()} enabled.");
        }
        return Response::redirect(NginxController::back($request, '/sites'));
    }

    public function delete(Request $request, array $params): Response
    {
        $site = $this->app->sites()->find($params['id']);
        if ($site === null) {
            return $this->notFound();
        }
        $this->app->sites()->delete($site->id);
        $this->app->groups()->forgetSite($site->id);
        if ($request->postString('hosts') === '1') {
            foreach ($site->parsed->serverNames as $name) {
                try {
                    $this->app->hosts()->remove($name);
                } catch (RuntimeException $e) {
                    Flash::add('warn', $e->getMessage());
                    break;
                }
            }
        }
        $this->reload("{$site->domain()} deleted. Its config was moved to backups/deleted.");
        return Response::redirect('/sites');
    }

    // -- helpers ---------------------------------------------------------------------------

    private function safeSave(string $id, string $content, string $back, string $ok, ?array $php = null): Response
    {
        try {
            $result = $this->app->sites()->save($id, $content);
        } catch (RuntimeException $e) {
            Flash::add('error', $e->getMessage());
            return Response::redirect($back);
        }
        if (!$result->ok()) {
            Flash::add('error', 'nginx rejected the change, so the previous configuration was kept. Fix the error below and save again.', $result->output);
            return Response::redirect($back);
        }
        unset($_SESSION['_draft'][$id]);
        if ($php !== null) {
            $this->warnIfPhpStopped($php);
        }
        $this->reload($ok);
        return Response::redirect($back);
    }

    /** Regenerates derived nginx files (project roots may have changed in .env) and reloads. */
    private function reload(string $ok): void
    {
        try {
            NginxConfigWriter::writeAll($this->app->settings());
        } catch (Throwable $e) {
            Flash::add('warn', 'Could not refresh the generated nginx files: ' . $e->getMessage());
        }
        $r = $this->app->nginx()->reload();
        if ($r->ok()) {
            Flash::add('success', $ok . ' nginx reloaded.');
        } else {
            Flash::add('warn', $ok . ' But nginx was not reloaded:', $r->output);
        }
    }

    /** @return array{0:string, 1:string} [nginx root "$projects_dir/...", absolute folder] */
    private function rootFromRequest(Request $request): array
    {
        $roots = Paths::projectRoots();
        if (!$roots) {
            throw new InvalidArgumentException('Add your projects folder on the Settings page first.');
        }
        $path = trim(str_replace('\\', '/', $request->postString('root_path')));
        if (Validator::absolutePath($path)) {
            $nginxRoot = Paths::toProjectRelative($path);
            if ($nginxRoot === null) {
                throw new InvalidArgumentException('That folder is outside your project folders - add its parent folder on the Settings page.');
            }
        } else {
            $var = $request->postString('root_var', 'projects_dir');
            if (!isset($roots[$var])) {
                throw new InvalidArgumentException('Unknown project folder.');
            }
            $rel = trim($path, '/');
            $nginxRoot = '$' . $var . ($rel === '' ? '' : '/' . $rel);
        }
        if (!preg_match('~^\$[a-z0-9_]+(/[A-Za-z0-9._-]+)*$~', $nginxRoot) || str_contains($nginxRoot, '..')) {
            throw new InvalidArgumentException('The project path may only contain letters, digits, ".", "_", "-" and "/" (no spaces).');
        }
        return [$nginxRoot, Paths::resolveRoot($nginxRoot)];
    }

    private function phpFromRequest(Request $request): array
    {
        $php = $this->app->phpVersions()->find($request->postString('php'));
        if ($php === null) {
            throw new InvalidArgumentException('Choose a PHP version.');
        }
        return $php;
    }

    private function groupFromRequest(Request $request): ?string
    {
        $new = $request->postString('group_new');
        if ($new !== '') {
            return $this->app->groups()->create($new)['id'];
        }
        $id = $request->postString('group');
        if ($id === '') {
            return null;
        }
        if ($this->app->groups()->find($id) === null) {
            throw new InvalidArgumentException('Unknown group.');
        }
        return $id;
    }

    /** @return list<string> */
    private function aliases(string $value): array
    {
        $names = array_values(array_unique(array_filter(preg_split('/[\s,]+/', strtolower($value)) ?: [])));
        foreach ($names as $n) {
            if (!Validator::domain($n)) {
                throw new InvalidArgumentException("\"$n\" is not a valid domain.");
            }
        }
        return $names;
    }

    /** @return array{0:string, 1:string} conf-relative certificate + key paths for a site id */
    private function certPaths(string $id): array
    {
        return ["../ssl/$id/ssl.crt/server.crt", "../ssl/$id/ssl.key/server.key"];
    }

    private function certExists(string $id): bool
    {
        return is_file(Paths::in('ssl', $id, 'ssl.crt', 'server.crt')) && is_file(Paths::in('ssl', $id, 'ssl.key', 'server.key'));
    }

    private function hostsAdd(array $names): void
    {
        foreach ($names as $name) {
            try {
                $this->app->hosts()->add($name);
            } catch (InvalidArgumentException $e) {
                Flash::add('info', $e->getMessage());
            } catch (RuntimeException $e) {
                Flash::add('warn', $e->getMessage() . " Add \"127.0.0.1 $name\" to the hosts file yourself meanwhile.");
                return;
            }
        }
    }

    private function warnIfPhpStopped(array $php): void
    {
        if (!$php['running']) {
            Flash::add('warn', "PHP {$php['id']} is not running - start it on the PHP Versions page" . ($php['autostart'] ? '.' : ' (and switch on Auto-start if this site should always work).'));
        }
    }

    private function formData(): array
    {
        return [
            'roots'      => Paths::projectRoots(),
            'phpList'    => $this->app->phpVersions()->installed(),
            'frameworks' => SiteConfigBuilder::FRAMEWORKS,
            'groups'     => $this->app->groups()->all(),
            'old'        => $_SESSION['_old'] ?? [],
        ];
    }

    private function backToForm(Request $request): Response
    {
        $_SESSION['_old'] = array_map(static fn($v) => is_string($v) ? $v : '', array_diff_key($_POST, ['_token' => 1]));
        return Response::redirect('/sites/new');
    }

    /** @return array<string, array> upstream => PHP entry */
    private function phpByUpstream(): array
    {
        $out = [];
        foreach ($this->app->phpVersions()->installed() as $v) {
            $out[$v['upstream']] = $v;
        }
        return $out;
    }

    private function notFound(): Response
    {
        return Response::html(View::render('errors/404', ['title' => 'Site not found', 'message' => 'There is no site with that name.']), 404);
    }
}
