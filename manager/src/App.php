<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : App.php
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
 * File: manager/src/App.php
 * Purpose: Tiny service holder for one request - builds each service once, on first use.
 * Responsibilities: explicit getters for every shared service (settings, shell, nginx,
 *                   sites, PHP versions, background jobs, ...)
 * Used by: routes.php (passed to every controller), bin/manager.php
 * Notes: deliberately not a DI container - explicit getters keep the wiring readable.
 */

declare(strict_types=1);

namespace NginxManager;

use NginxManager\Groups\GroupRepository;
use NginxManager\Hosts\HostsFile;
use NginxManager\Nginx\NginxControl;
use NginxManager\Nginx\SiteRepository;
use NginxManager\Php\ExtensionManager;
use NginxManager\Php\JobRunner;
use NginxManager\Php\PeclCatalog;
use NginxManager\Php\PhpArchiveCatalog;
use NginxManager\Php\PhpReleaseFeed;
use NginxManager\Php\PhpVersionRepository;
use NginxManager\Php\UpdateChecker;
use NginxManager\Ssl\CertificateService;
use NginxManager\Support\Settings;
use NginxManager\Support\Shell;

final class App
{
    /** @var array<string, object> */
    private array $services = [];

    public function settings(): Settings
    {
        return $this->services['settings'] ??= Settings::load();
    }

    public function shell(): Shell
    {
        return $this->services['shell'] ??= new Shell();
    }

    public function nginx(): NginxControl
    {
        return $this->services['nginx'] ??= new NginxControl($this->shell());
    }

    public function sites(): SiteRepository
    {
        return $this->services['sites'] ??= new SiteRepository($this->nginx());
    }

    public function phpVersions(): PhpVersionRepository
    {
        return $this->services['phpVersions'] ??= new PhpVersionRepository($this->settings());
    }

    public function jobs(): JobRunner
    {
        return $this->services['jobs'] ??= new JobRunner();
    }

    public function groups(): GroupRepository
    {
        return $this->services['groups'] ??= new GroupRepository();
    }

    public function hosts(): HostsFile
    {
        return $this->services['hosts'] ??= new HostsFile();
    }

    public function extensions(): ExtensionManager
    {
        return $this->services['extensions'] ??= new ExtensionManager($this->settings(), $this->shell(), $this->jobs());
    }

    public function certificates(): CertificateService
    {
        return $this->services['certificates'] ??= new CertificateService($this->shell());
    }

    public function pecl(): PeclCatalog
    {
        $base = (string) $this->settings()->get('php.peclBase', PeclCatalog::DEFAULT_URL);
        return $this->services['pecl'] ??= new PeclCatalog($base);
    }

    public function phpReleases(): PhpReleaseFeed
    {
        $url = (string) $this->settings()->get('php.feedUrl', PhpReleaseFeed::DEFAULT_URL);
        return $this->services['phpReleases'] ??= new PhpReleaseFeed($url);
    }

    public function phpArchives(): PhpArchiveCatalog
    {
        $base = rtrim((string) $this->settings()->get('php.downloadBase', 'https://downloads.php.net/~windows/releases/'), '/');
        return $this->services['phpArchives'] ??= new PhpArchiveCatalog($base . '/archives/');
    }

    /** Number of PHP updates, from the cached feed only (never the network) - menu badge. */
    public function phpUpdateCount(): int
    {
        $res = \NginxManager\Support\Platform::isWindows()
            ? UpdateChecker::compare($this->phpVersions()->installed(), $this->phpReleases()->cachedOnly())
            : $this->brewCatalog()->compare($this->phpVersions()->installed());   // cache only, no brew call
        return count($res['updates']);
    }

    /** macOS: PHP branches of the Homebrew tap + updates from "brew outdated". */
    public function brewCatalog(): \NginxManager\Mac\BrewCatalog
    {
        return $this->services['brewCatalog'] ??= new \NginxManager\Mac\BrewCatalog(new \NginxManager\Mac\Brew($this->shell()));
    }
}
