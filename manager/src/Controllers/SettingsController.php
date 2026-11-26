<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : SettingsController.php
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
 * File: manager/src/Controllers/SettingsController.php
 * Purpose: Machine settings edited from the browser (stored in .env, never committed).
 * Responsibilities:
 *   GET  /settings   General (time zone), Project folders, PHP (default build, PATH
 *                    folders), Certificates (openssl.exe) and read-only System information
 *   POST /settings   validate -> write .env -> regenerate nginx variables -> reload nginx
 * Used by: routes.php, sidebar "Settings"
 * Notes: project folders become nginx variables ($projects_dir, $projects_dir_<name>);
 *        removing one that sites still use would break them, so that is refused.
 */

declare(strict_types=1);

namespace NginxManager\Controllers;

use InvalidArgumentException;
use NginxManager\App;
use NginxManager\Http\Flash;
use NginxManager\Http\Request;
use NginxManager\Http\Response;
use NginxManager\Nginx\NginxConfigWriter;
use NginxManager\Support\Env;
use NginxManager\Support\EnvFile;
use NginxManager\Support\Paths;
use NginxManager\Support\Timezone;
use NginxManager\Support\Validator;
use NginxManager\Support\View;

final class SettingsController
{
    public function __construct(private readonly App $app) {}

    public function index(Request $request): Response
    {
        $roots = Paths::projectRoots();
        $folders = [['name' => '', 'var' => 'projects_dir', 'path' => $roots['projects_dir'] ?? '', 'main' => true]];
        foreach ($roots as $var => $path) {
            if ($var !== 'projects_dir') {
                $folders[] = ['name' => strtoupper(substr($var, strlen('projects_dir_'))), 'var' => $var, 'path' => $path, 'main' => false];
            }
        }
        $nginxV = $this->app->shell()->run([\NginxManager\Support\Platform::nginxBinary(), '-v'], Paths::base(), 10)->output;

        return Response::html(View::render('settings/index', [
            'title'     => 'Settings',
            'folders'   => $folders,
            'usage'     => $this->rootUsage(),
            'extraPath' => array_values(array_filter(explode(';', (string) Env::get('PHP_EXTRA_PATH', '')))),
            'openssl'   => (string) Env::get('OPENSSL_BIN', ''),
            'opensslFound' => $this->detectOpenSsl(),
            'ts'        => Env::get('PHP_THREAD_SAFETY', 'ts') === 'nts' ? 'nts' : 'ts',
            'timezone'  => Timezone::configured(),
            'zones'     => timezone_identifiers_list(),
            'system'    => [
                'Install folder'      => Paths::base(),
                'Manager address'     => 'http://' . Env::get('MANAGER_HOST', '127.0.0.1') . ':' . Env::int('MANAGER_PORT', 8080),
                'nginx'               => trim((string) preg_replace('/^.*?nginx\//s', 'nginx/', $nginxV)) ?: 'not found',
                'nginx ports'         => Env::int('NGINX_HTTP_PORT', 80) . ' (HTTP) · ' . Env::int('NGINX_HTTPS_PORT', 443) . ' (HTTPS)',
                'Manager runs on'     => 'PHP ' . PHP_VERSION . '  (change on PHP Versions > Manager PHP)',                'Server time'         => date('M j, Y H:i') . ' (' . date_default_timezone_get() . ')',
            ],
        ]));
    }

    public function save(Request $request): Response
    {
        try {
            $changes = [];

            // -- General
            $tz = $request->postString('timezone');
            if (!in_array($tz, timezone_identifiers_list(), true)) {
                throw new InvalidArgumentException('Choose a time zone from the list.');
            }
            $changes['APP_TIMEZONE'] = $tz;

            // -- Project folders: the first row is the main folder; the rest are NAME + folder.
            $changes['PROJECTS_DIR'] = $this->folder($request->postString('projects_dir'), 'Main projects folder', true);
            foreach (array_keys(Env::all()) as $key) {
                if (str_starts_with($key, 'PROJECTS_DIR_')) {
                    $changes[$key] = null;
                }
            }
            // Extra folders: existing rows keep their name (sites use $projects_dir_<name>);
            // new rows get one derived from the folder (see autoName()).
            $names = $request->postList('extra_name');
            $paths = $request->postList('extra_folder');
            $used = [];
            foreach ($names as $name) {
                if (preg_match('/^[A-Z0-9]{1,20}$/', strtoupper(trim($name)))) {
                    $used[] = strtoupper(trim($name));
                }
            }
            foreach ($paths as $i => $path) {
                $path = trim($path);
                if ($path === '') {
                    continue;
                }
                $folder = $this->folder($path, 'Projects folder', false);
                $name = strtoupper(trim($names[$i] ?? ''));
                if (!preg_match('/^[A-Z0-9]{1,20}$/', $name)) {
                    $name = self::autoName($folder, $used);
                    $used[] = $name;
                }
                $changes['PROJECTS_DIR_' . $name] = $folder;
            }
            foreach ($this->rootUsage() as $var => $count) {
                $key = strtoupper($var);
                if ($count > 0 && array_key_exists($key, $changes) && ($changes[$key] === null || $changes[$key] === '')) {
                    throw new InvalidArgumentException("$count site(s) still use the folder \$$var - move them to another folder first.");
                }
            }

            // -- PHP (Windows only: build flavour and DLL folders; macOS uses Homebrew)
            if (\NginxManager\Support\Platform::isWindows()) {
                $extra = array_values(array_filter(array_map('trim', $request->postList('php_path'))));
                foreach ($extra as $p) {
                    $this->folder($p, 'Extra PATH folder', false);
                }
                $changes['PHP_EXTRA_PATH'] = implode(';', array_map(static fn($p) => rtrim(str_replace('\\', '/', $p), '/'), $extra));
                $changes['PHP_THREAD_SAFETY'] = $request->postString('php_ts') === 'nts' ? 'nts' : 'ts';
            }

            // -- Certificates
            $openssl = str_replace('\\', '/', $request->postString('openssl_bin'));
            if ($openssl !== '' && (!Validator::absolutePath($openssl) || !is_file($openssl))) {
                throw new InvalidArgumentException('OpenSSL: enter the full path of an existing file, or leave it empty to detect it.');
            }
            $changes['OPENSSL_BIN'] = $openssl;

            EnvFile::set(Paths::in('.env'), $changes);
        } catch (InvalidArgumentException $e) {
            Flash::add('error', $e->getMessage());
            return Response::redirect('/settings');
        }

        Env::load(Paths::in('.env'));
        NginxConfigWriter::writeAll($this->app->settings());
        $r = $this->app->nginx()->reload();
        $r->ok() ? Flash::add('success', 'Settings saved.')
                 : Flash::add('warn', 'Settings saved, but nginx was not reloaded:', $r->output);
        return Response::redirect('/settings');
    }

    /**
     * Name for a new extra project folder: the drive letter ("D:/projects" -> D); when taken,
     * drive + folder name ("D:/work" -> D_WORK -> DWORK); then a number.
     * @param list<string> $used
     */
    public static function autoName(string $folder, array $used): string
    {
        $drive = strtoupper(substr($folder, 0, 1));
        $leaf = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', basename($folder)));
        foreach ([$drive, substr($drive . $leaf, 0, 20)] as $candidate) {
            if ($candidate !== '' && !in_array($candidate, $used, true)) {
                return $candidate;
            }
        }
        for ($n = 2; ; $n++) {
            if (!in_array($drive . $n, $used, true)) {
                return $drive . $n;
            }
        }
    }

    /** @return array<string, int> project variable => number of sites using it */
    private function rootUsage(): array
    {
        $usage = [];
        foreach ($this->app->sites()->all() as $site) {
            if (preg_match('/^\$(projects_dir(?:_[a-z0-9]+)?)/', (string) $site->parsed->root, $m)) {
                $usage[$m[1]] = ($usage[$m[1]] ?? 0) + 1;
            }
        }
        return $usage;
    }

    /** Same search as the certificate service (setting, usual install places, PATH). */
    private function detectOpenSsl(): ?string
    {
        return $this->app->certificates()->opensslPath();
    }

    private function folder(string $value, string $label, bool $allowEmpty): string
    {
        $value = rtrim(str_replace('\\', '/', trim($value)), '/');
        if ($value === '') {
            if ($allowEmpty) {
                return '';
            }
            throw new InvalidArgumentException("$label: enter a folder.");
        }
        if (!Validator::absolutePath($value) || !Validator::nginxSafePath($value)) {
            throw new InvalidArgumentException("$label: enter a full folder path such as D:/projects.");
        }
        if (!is_dir($value)) {
            throw new InvalidArgumentException("$label: the folder $value does not exist.");
        }
        return $value;
    }
}
