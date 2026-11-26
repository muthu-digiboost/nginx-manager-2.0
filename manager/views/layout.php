<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : layout.php
 *  Date    : 26/11/2026
 *  Author  : Semansys Development Team
 *
 *  Copyright (c) 2026
 *  Tel: +91 97863 37914
 *  Email: dev.digiboost.muthu@gmail.com
 *  All rights reserved, see the LICENSE file for more information.
 *
 ******************************************************************************/
/* View: layout | Shell of every page: full-height left menu, white top header, content.
   Data: $title (string), $content (rendered page HTML), optional $actions (HTML next to the title),
         optional $crumbs (list of [label, href|null] shown before the title in the header),
         shared: $updateCount (int, menu badge), $statusBar (callable => nginx / phpRunning / phpTotal) */
$status = isset($statusBar) && is_callable($statusBar) ? $statusBar() : ['nginx' => false, 'phpRunning' => 0, 'phpTotal' => 0];
$crumbs = $crumbs ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nginx Manager<?= !empty($title) ? ' - ' . e($title) : '' ?></title>
    <link rel="stylesheet" href="<?= e(asset('/assets/app.css')) ?>">
    <link rel="icon" type="image/svg+xml" href="/assets/logo.svg">
</head>
<body>
<div class="app">
    <?= \NginxManager\Support\View::partial('partials/sidebar', get_defined_vars()) ?>

    <header class="topbar">
        <nav class="crumb" aria-label="Breadcrumb">
            <a href="/">Home</a>
            <?php foreach ($crumbs as [$label, $href]): ?>
                <span class="sep">/</span>
                <?php if ($href): ?><a href="<?= e($href) ?>"><?= e($label) ?></a><?php else: ?><span><?= e($label) ?></span><?php endif; ?>
            <?php endforeach; ?>
            <span class="sep">/</span>
            <strong><?= e($title ?? '') ?></strong>
        </nav>
        <div class="topbar-right">
            <div class="status-panel" role="status" aria-label="Server status">
                <a class="status-item <?= $status['nginx'] ? 'is-ok' : 'is-bad' ?>" href="/" title="nginx web server">
                    <span class="status-dot"></span>
                    <span class="status-text"><small>nginx</small><b><?= $status['nginx'] ? 'Running' : 'Stopped' ?></b></span>
                </a>
                <a class="status-item <?= $status['phpRunning'] ? 'is-ok' : 'is-off' ?>" href="/php" title="PHP versions running now">
                    <span class="status-dot"></span>
                    <span class="status-text"><small>PHP</small><b><?= (int) $status['phpRunning'] ?> of <?= (int) $status['phpTotal'] ?> running</b></span>
                </a>
            </div>
            <form method="post" action="/nginx/reload">
                <?= csrf_field() ?>
                <button type="submit" class="top-btn" title="Test the configuration, then reload nginx">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7L21 8M21 3v5h-5"/></svg>
                    <span class="pill-label">Reload</span>
                </button>
            </form>
            <a class="top-btn primary" href="/sites/new">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                <span class="pill-label">New Site</span>
            </a>
        </div>
    </header>

    <main class="main">
        <header class="page-header">
            <h1><?= e($title ?? '') ?></h1>
            <?php if (!empty($actions)): ?><div class="page-actions"><?= $actions ?></div><?php endif; ?>
        </header>
        <?= \NginxManager\Support\View::partial('partials/flash') ?>
        <div class="page-body">
            <?= $content ?>
        </div>
    </main>
</div>
<script src="<?= e(asset('/assets/app.js')) ?>"></script>
</body>
</html>
