<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : dashboard.php
 *  Date    : 26/11/2026
 *  Author  : Semansys Development Team
 *
 *  Copyright (c) 2026
 *  Tel: +91 97863 37914
 *  Email: dev.digiboost.muthu@gmail.com
 *  All rights reserved, see the LICENSE file for more information.
 *
 ******************************************************************************/
/* View: dashboard | wp-admin style dashboard: welcome panel + widget cards.
   Data: $nginxRunning (bool), $php (all installed PHP), $pinned (PHP starred for the dashboard), $siteCount, $siteDisabled,
         $groupCount, $certCount, $updates (int), $siteCounts (upstream => sites),
         $jobs (recent jobs, newest first) */
$running = count(array_filter($php, fn($v) => $v['running']));
?>
<section class="welcome">
    <h2>Welcome to Nginx Manager 2.0</h2>
    <p>Your local nginx and PHP servers in one place.</p>
    <div class="welcome-columns">
        <div>
            <h3>Get started</h3>
            <a class="btn" href="/sites/new">Add a new site</a>
        </div>
        <div>
            <h3>Next steps</h3>
            <ul>
                <li><a href="/sites">Manage your sites</a></li>
                <li><a href="/ssl">Create an SSL certificate</a></li>
                <li><a href="/groups">Organise sites into groups</a></li>
            </ul>
        </div>
        <div>
            <h3>PHP</h3>
            <ul>
                <li><a href="/php">Install or update PHP versions</a></li>
                <li><a href="/extensions">Enable PHP extensions</a></li>
            </ul>
        </div>
    </div>
</section>

<div class="widgets two">
    <section class="card">
        <h2 class="card-title">At a Glance</h2>
        <div class="glance-grid">
            <a class="glance-tile <?= $nginxRunning ? 'ok' : 'bad' ?>" href="/">
                <span class="glance-icon"><svg viewBox="0 0 24 24"><path d="M12 2l9 5v10l-9 5-9-5V7z"/></svg></span>
                <span class="glance-value"><?= $nginxRunning ? 'Running' : 'Stopped' ?></span>
                <span class="glance-label">nginx</span>
            </a>
            <a class="glance-tile" href="/sites">
                <span class="glance-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg></span>
                <span class="glance-value"><?= (int) $siteCount ?></span>
                <span class="glance-label">Sites<?= $siteDisabled ? ' · ' . (int) $siteDisabled . ' disabled' : '' ?></span>
            </a>
            <a class="glance-tile <?= $running ? 'ok' : '' ?>" href="/php">
                <span class="glance-icon"><svg viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg></span>
                <span class="glance-value"><?= $running ?> / <?= count($php) ?></span>
                <span class="glance-label">PHP running</span>
            </a>
            <a class="glance-tile <?= $updates ? 'warn' : '' ?>" href="/php">
                <span class="glance-icon"><svg viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-3-6.7L21 8M21 3v5h-5"/></svg></span>
                <span class="glance-value"><?= (int) $updates ?></span>
                <span class="glance-label">PHP updates</span>
            </a>
            <a class="glance-tile" href="/groups">
                <span class="glance-icon"><svg viewBox="0 0 24 24"><path d="M3 7h7l2 2h9v10H3z"/></svg></span>
                <span class="glance-value"><?= (int) $groupCount ?></span>
                <span class="glance-label">Groups</span>
            </a>
            <a class="glance-tile" href="/ssl">
                <span class="glance-icon"><svg viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></span>
                <span class="glance-value"><?= (int) $certCount ?></span>
                <span class="glance-label">SSL certificates</span>
            </a>
        </div>
        <?php
        $managerPhp = array_values(array_filter($php, static fn($v) => $v['isManager']))[0] ?? null;
        $httpsCount = (int) ($httpsCount ?? 0);
        ?>
        <dl class="glance-facts">
            <div><dt>Manager runs on</dt><dd><?= $managerPhp ? 'PHP ' . e($managerPhp['version'] !== '' ? $managerPhp['version'] : $managerPhp['id']) : '-' ?></dd></div>
            <div><dt>Sites on HTTPS</dt><dd><?= $httpsCount ?> of <?= (int) $siteCount ?></dd></div>
            <div><dt>Web ports</dt><dd><?= e($webPorts ?? '80 (HTTP) · 443 (HTTPS)') ?></dd></div>
            <div><dt>Manager address</dt><dd class="mono"><?= e($managerUrl ?? 'http://127.0.0.1:8080') ?></dd></div>
        </dl>
    </section>

    <section class="card">
        <h2 class="card-title">Activity <span class="actions"><a class="btn btn-ghost btn-sm" href="/jobs">View all</a></span></h2>
        <?= \NginxManager\Support\View::partial('partials/jobs-list', ['jobs' => $jobs]) ?>
    </section>
</div>

<section class="card">
    <h2 class="card-title">PHP Services <span class="actions"><a class="btn btn-ghost btn-sm" href="/php">All PHP versions</a></span></h2>
    <?php if (!$php): ?>
        <p class="muted">No PHP versions yet. Install one from <a href="/php">PHP Versions</a>.</p>
    <?php elseif (!$pinned): ?>
        <div class="empty-hint">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/></svg>
            <div><strong>No PHP versions on the dashboard yet.</strong><br>
                <span class="muted">Star the versions you use most on <a href="/php">PHP Versions</a> to start and stop them from here.</span></div>
        </div>
    <?php else: ?>
        <?= \NginxManager\Support\View::partial('partials/php-tiles', ['php' => $pinned, 'siteCounts' => $siteCounts]) ?>
    <?php endif; ?>
</section>
