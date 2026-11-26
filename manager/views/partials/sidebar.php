<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : sidebar.php
 *  Date    : 26/11/2026
 *  Author  : Semansys Development Team
 *
 *  Copyright (c) 2026
 *  Tel: +91 97863 37914
 *  Email: dev.digiboost.muthu@gmail.com
 *  All rights reserved, see the LICENSE file for more information.
 *
 ******************************************************************************/
/* View: partials/sidebar | Full-height left menu: logo, labelled sections, footer.
   Data: shared $updateCount (int) - PHP updates available (cached feed, no network). */
$sections = [
    'Overview' => [
        ['/', 'Dashboard', '<path d="M3 3h7v9H3zM14 3h7v5h-7zM14 12h7v9h-7zM3 16h7v5H3z"/>'],
    ],
    'Websites' => [
        ['/sites',  'Sites',            '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>'],
        ['/groups', 'Groups',           '<path d="M3 7h7l2 2h9v10H3z"/>'],
        ['/ssl',    'SSL Certificates', '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>'],
    ],
    'PHP' => [
        ['/php',        'PHP Versions',   '<path d="M12 2l9 5v10l-9 5-9-5V7z"/><path d="M12 22V12M21 7l-9 5-9-5"/>'],
        ['/extensions', 'PHP Extensions', '<path d="M9 3h6v4h4v6h-4v4H9v-4H5V7h4z"/>'],
    ],
    'System' => [
        ['/settings', 'Settings', '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>'],
    ],
];
$updates = isset($updateCount) && is_callable($updateCount) ? (int) $updateCount() : (int) ($updateCount ?? 0);
?>
<aside class="sidebar">
    <a class="sidebar-brand" href="/" title="Nginx Manager 2.0 - Dashboard">
        <img src="/assets/logo.svg" alt="">
        <span class="brand-words"><b>NGINX</b><small>MANAGER</small></span>
        <span class="brand-version">2.0</span>
    </a>
    <nav class="nav" aria-label="Main menu">
        <?php foreach ($sections as $label => $items): ?>
            <div class="nav-label"><?= e($label) ?></div>
            <?php foreach ($items as [$href, $text, $icon]): ?>
                <a class="nav-link <?= active($href) ?>" href="<?= e($href) ?>" title="<?= e($text) ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><?= $icon ?></svg>
                    <span><?= e($text) ?></span>
                    <?php if ($href === '/php' && $updates > 0): ?>
                        <span class="badge badge-update" title="<?= $updates ?> PHP update(s) available"><?= $updates ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
        <span>Manager address</span>
        <span class="mono">http://127.0.0.1:<?= e(\NginxManager\Support\Env::get('MANAGER_PORT', '8080')) ?></span>
    </div>
</aside>
