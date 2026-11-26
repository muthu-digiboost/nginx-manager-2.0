<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : index.php
 *  Date    : 26/11/2026
 *  Author  : Semansys Development Team
 *
 *  Copyright (c) 2026
 *  Tel: +91 97863 37914
 *  Email: dev.digiboost.muthu@gmail.com
 *  All rights reserved, see the LICENSE file for more information.
 *
 ******************************************************************************/

/* View: sites/index | All sites: stats, search + status filters, one section per group with rich rows
   (letter badge, folder, PHP state, HTTPS, enable switch, Visit / Edit).
   Data: $groups (GroupRepository::all()), $byGroup (group id|'' => list<Site>), $total (int),
         $php (upstream => PHP entry), $hosts (HostsFile) */
use NginxManager\Nginx\Site;

$all = array_merge(...array_values($byGroup ?: [[]]));
$enabled = count(array_filter($all, fn(Site $s) => $s->enabled));
$https = count(array_filter($all, fn(Site $s) => $s->parsed->ssl));
$sections = [];
foreach ($groups as $g) {
    $sections[] = ['id' => $g['id'], 'name' => $g['name'], 'color' => $g['color'], 'sites' => $byGroup[$g['id']] ?? []];
}
$sections[] = ['id' => '', 'name' => 'Ungrouped', 'color' => '#a7aaad', 'sites' => $byGroup[''] ?? []];
$initials = static function (string $name): string {
    $w = preg_split('/[\s._-]+/', trim($name)) ?: [];
    return mb_strtoupper(mb_substr($w[0] ?? '?', 0, 1) . mb_substr($w[1] ?? '', 0, 1)) ?: '?';
};
?>
<?php if ($total === 0): ?>
    <div class="empty-state">
        <div class="empty-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg></div>
        <h2>No sites yet</h2>
        <p class="muted">Pick a domain, the project folder and a PHP version - the manager writes the nginx config, tests it and reloads nginx for you.</p>
        <a class="btn" href="/sites/new">Add your first site</a>
    </div>
<?php else: ?>

<div class="stat-strip">
    <div class="stat-box">
        <span class="stat-ic"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg></span>
        <span class="stat-box-text"><span class="stat-box-value"><?= (int) $total ?></span><span class="stat-box-label">Sites</span></span>
    </div>
    <div class="stat-box ok">
        <span class="stat-ic"><svg viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></svg></span>
        <span class="stat-box-text"><span class="stat-box-value"><?= $enabled ?></span><span class="stat-box-label">Enabled</span></span>
    </div>
    <div class="stat-box ok">
        <span class="stat-ic"><svg viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></span>
        <span class="stat-box-text"><span class="stat-box-value"><?= $https ?></span><span class="stat-box-label">Using HTTPS</span></span>
    </div>
    <div class="stat-box <?= $total - $enabled ? 'warn' : '' ?>">
        <span class="stat-ic"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M6 6l12 12"/></svg></span>
        <span class="stat-box-text"><span class="stat-box-value"><?= $total - $enabled ?></span><span class="stat-box-label">Disabled</span></span>
    </div>
</div>

<div class="list-toolbar">
    <input type="search" placeholder="Search domains or folders..." data-list-search="#site-groups [data-list]" aria-label="Search sites">
    <div class="seg" role="tablist" aria-label="Filter sites">
        <?php foreach (['all' => 'All', 'on' => 'Enabled', 'off' => 'Disabled', 'https' => 'HTTPS', 'http' => 'HTTP'] as $k => $label): ?>
            <button type="button" class="seg-btn <?= $k === 'all' ? 'current' : '' ?>" data-site-filter="<?= $k ?>"><?= $label ?></button>
        <?php endforeach; ?>
    </div>
    <span class="spacer"></span>
    <a class="small" href="/groups">Manage groups</a>
</div>

<div id="site-groups" class="site-groups">
    <?php foreach ($sections as $section): ?>
        <?php if (!$section['sites'] && $section['id'] === '') { continue; } ?>
        <details class="site-group" open data-filter-group style="--c: <?= e($section['color']) ?>">
            <summary>
                <span class="avatar-sm"><?= e($section['id'] === '' ? '–' : $initials($section['name'])) ?></span>
                <span class="site-group-name"><?= e($section['name']) ?></span>
                <span class="tag"><?= count($section['sites']) ?></span>
                <span class="spacer"></span>
                <span class="chev" aria-hidden="true"></span>
            </summary>
            <?php if (!$section['sites']): ?>
                <p class="muted small" style="padding: 4px 16px 14px">No sites in this group yet.</p>
            <?php else: ?>
            <div class="site-rows" id="sites-<?= e($section['id'] ?: 'ungrouped') ?>" data-list data-page-size="10">
                <?php foreach ($section['sites'] as $site): /** @var Site $site */ ?>
                    <?php
                    $p = $site->parsed;
                    $phpEntry = $php[$p->upstream ?? ''] ?? null;
                    $url = ($p->ssl ? 'https://' : 'http://') . $site->domain();
                    $id = rawurlencode($site->id);
                    ?>
                    <div class="site-row <?= $site->enabled ? '' : 'is-disabled' ?>"
                         data-filter-text="<?= e(implode(' ', $p->serverNames) . ' ' . $site->id . ' ' . $p->root) ?>"
                         data-enabled="<?= $site->enabled ? '1' : '0' ?>" data-ssl="<?= $p->ssl ? '1' : '0' ?>">
                        <span class="site-badge" style="--c: <?= e($section['color']) ?>"><?= e(mb_strtoupper(mb_substr($site->domain(), 0, 1))) ?></span>
                        <div class="site-main">
                            <a class="site-domain" href="/sites/<?= $id ?>"><?= e($site->domain()) ?></a>
                            <?php if (count($p->serverNames) > 1): ?><span class="muted small" title="<?= e(implode(' ', array_slice($p->serverNames, 1))) ?>">+<?= count($p->serverNames) - 1 ?> more</span><?php endif; ?>
                            <div class="site-root mono" title="<?= e($p->root) ?>"><?= e($p->root ?? '—') ?></div>
                        </div>
                        <div class="site-meta">
                            <?php if ($phpEntry): ?>
                                <span class="php-pill <?= $phpEntry['running'] ? 'on' : '' ?>" title="PHP <?= e($phpEntry['version']) ?> - <?= $phpEntry['running'] ? 'running' : 'stopped' ?>">
                                    <span class="dot <?= $phpEntry['running'] ? 'dot-ok' : 'dot-off' ?>"></span>PHP <?= e($phpEntry['id']) ?>
                                </span>
                            <?php else: ?>
                                <span class="tag tag-bad" title="Upstream <?= e($p->upstream) ?> is not an installed PHP"><?= e($p->upstream ?? 'no PHP') ?></span>
                            <?php endif; ?>
                            <?php if ($p->ssl): ?>
                                <span class="ssl-pill" title="HTTPS"><svg viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>HTTPS</span>
                            <?php else: ?>
                                <span class="ssl-pill off" title="HTTP only">HTTP</span>
                            <?php endif; ?>
                        </div>
                        <div class="site-actions">
                            <form method="post" action="/sites/<?= $id ?>/toggle">
                                <?= csrf_field() ?>
                                <button type="submit" class="switch-btn <?= $site->enabled ? 'on' : '' ?>" title="<?= $site->enabled ? 'Enabled - click to disable' : 'Disabled - click to enable' ?>">
                                    <span class="track"></span>
                                </button>
                            </form>
                            <a class="icon-link" href="<?= e($url) ?>" target="_blank" rel="noopener" title="Visit <?= e($url) ?>">
                                <svg viewBox="0 0 24 24"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>
                            </a>
                            <a class="btn btn-ghost btn-sm" href="/sites/<?= $id ?>">Edit</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </details>
    <?php endforeach; ?>
</div>
<script>
(function () {
    'use strict';
    // Status filter applied on top of search + pagination in every group list.
    var filter = 'all';
    var lists = document.querySelectorAll('#site-groups [data-list]');
    function predicate(el) {
        switch (filter) {
            case 'on': return el.dataset.enabled === '1';
            case 'off': return el.dataset.enabled === '0';
            case 'https': return el.dataset.ssl === '1';
            case 'http': return el.dataset.ssl === '0';
            default: return true;
        }
    }
    lists.forEach(function (l) { l._predicate = predicate; });
    document.querySelectorAll('[data-site-filter]').forEach(function (b) {
        b.addEventListener('click', function () {
            filter = b.dataset.siteFilter;
            document.querySelectorAll('[data-site-filter]').forEach(function (x) { x.classList.toggle('current', x === b); });
            lists.forEach(function (l) { if (l._refresh) { l._refresh(); } });
        });
    });
})();
</script>
<?php endif; ?>
