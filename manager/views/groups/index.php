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

/* View: groups/index | Group cards (tinted header, stats, site list) with inline Manage / Edit / Delete,
   a collapsible "Add New Group" panel with live colour preview, and the Ungrouped card.
   Data: $groups (list {id,name,color}), $assign (site id => group id), $sites (list<Site>), $colors (palette),
         $php (upstream => PHP entry) */
use NginxManager\Nginx\Site;

$byId = [];
foreach ($sites as $s) { $byId[$s->id] = $s; }
$members = [];
foreach ($assign as $siteId => $gid) {
    if (isset($byId[$siteId])) { $members[$gid][] = $byId[$siteId]; }
}
$ungrouped = array_values(array_filter($sites, fn(Site $s) => !isset($assign[$s->id])));
$groupNames = array_column($groups, 'name', 'id');
$initials = static function (string $name): string {
    $words = preg_split('/[\s._-]+/', trim($name)) ?: [];
    $out = '';
    foreach (array_slice($words, 0, 2) as $w) { $out .= mb_strtoupper(mb_substr($w, 0, 1)); }
    return $out ?: '?';
};
$siteRow = static function (Site $s) use ($php): string {
    $p = $php[$s->parsed->upstream ?? ''] ?? null;
    return '<a class="gsite" href="/sites/' . rawurlencode($s->id) . '">'
        . '<span class="dot ' . ($s->enabled ? 'dot-ok' : 'dot-off') . '"></span>'
        . '<span class="gsite-name">' . e($s->domain()) . '</span>'
        . ($s->parsed->ssl ? '<span class="tag tag-ok">HTTPS</span>' : '')
        . '<span class="tag">' . e($p ? 'PHP ' . $p['id'] : ($s->parsed->upstream ?? '—')) . '</span></a>';
};
?>
<?php if ($groups): ?>
<div class="list-toolbar">
    <input type="search" placeholder="Search groups and sites..." data-list-search="#group-cards" aria-label="Search groups">
    <span class="muted"><?= count($groups) ?> group<?= count($groups) === 1 ? '' : 's' ?> · <?= count($sites) ?> site<?= count($sites) === 1 ? '' : 's' ?></span>
</div>
<?php endif; ?>

<?php
// One compact form: live initials badge + name + colour + button. Used inside the empty
// state (no groups yet) and in the panel opened by "Add New Group".
ob_start();
?>
<form method="post" action="/groups" class="group-form">
    <?= csrf_field() ?>
    <span class="avatar group-form-badge" id="group-preview" style="--c: <?= e($colors[0]) ?>" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 7h7l2 2h9v10H3z"/></svg></span>
    <input type="text" id="group-name" name="name" placeholder="Group name, e.g. Client A" maxlength="40" required aria-label="Group name">
    <div class="swatches" role="radiogroup" aria-label="Colour">
        <?php foreach ($colors as $i => $c): ?>
            <label class="swatch-pick" style="--c: <?= e($c) ?>" title="<?= e($c) ?>"><input type="radio" name="color" value="<?= e($c) ?>" <?= $i === 0 ? 'checked' : '' ?>><span></span></label>
        <?php endforeach; ?>
    </div>
    <button type="submit" class="btn">Add Group</button>
</form>
<?php $groupForm = (string) ob_get_clean(); ?>

<?php if (!$groups && !$ungrouped): ?>
    <section class="empty-state wide">
        <div class="empty-icon"><svg viewBox="0 0 24 24"><path d="M3 7h7l2 2h9v10H3z"/></svg></div>
        <h2>Create your first group</h2>
        <p class="muted">Groups keep related sites together - one per client, product or team.</p>
        <?= $groupForm ?>
    </section>
<?php else: ?>
<section class="card create-panel hidden" id="new-group">
    <h2 class="card-title">New group <span class="actions"><button type="button" class="icon-btn" data-toggle="#new-group" title="Close">✕</button></span></h2>
    <?= $groupForm ?>
</section>
<div class="group-cards" id="group-cards" data-list data-page-size="9">
    <?php foreach ($groups as $g): ?>
        <?php
        $list = $members[$g['id']] ?? [];
        $enabled = count(array_filter($list, fn(Site $s) => $s->enabled));
        $https = count(array_filter($list, fn(Site $s) => $s->parsed->ssl));
        $gid = rawurlencode($g['id']);
        ?>
        <section class="group-card" id="group-<?= e($g['id']) ?>" style="--c: <?= e($g['color']) ?>"
                 data-filter-text="<?= e($g['name'] . ' ' . implode(' ', array_map(fn($s) => $s->domain(), $list))) ?>">
            <header class="group-card-head">
                <span class="avatar"><?= e($initials($g['name'])) ?></span>
                <div class="group-card-title">
                    <h3><?= e($g['name']) ?></h3>
                    <span class="small muted"><?= count($list) ?> site<?= count($list) === 1 ? '' : 's' ?> · <?= $enabled ?> enabled · <?= $https ?> HTTPS</span>
                </div>
            </header>

            <div class="gsites">
                <?php if (!$list): ?><p class="muted small" style="margin: 4px 0">No sites yet - use “Manage sites”.</p><?php endif; ?>
                <?php foreach (array_slice($list, 0, 6) as $s): ?><?= $siteRow($s) ?><?php endforeach; ?>
                <?php if (count($list) > 6): ?><a class="small" href="/sites" style="padding: 4px 6px">+ <?= count($list) - 6 ?> more</a><?php endif; ?>
            </div>

            <footer class="group-card-foot">
                <button type="button" class="btn btn-ghost btn-sm" data-toggle="#manage-<?= e($g['id']) ?>">Manage sites</button>
                <button type="button" class="btn btn-ghost btn-sm" data-toggle="#edit-<?= e($g['id']) ?>">Edit</button>
                <span class="spacer"></span>
                <form method="post" action="/groups/<?= $gid ?>/delete"
                      data-confirm-dialog="Delete the group &quot;<?= e($g['name']) ?>&quot;?"
                      data-confirm-text="Only the group is removed. Its <?= count($list) ?> site<?= count($list) === 1 ? '' : 's' ?> keep working and become ungrouped."
                      data-confirm-button="Delete group">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                </form>
            </footer>

            <form method="post" action="/groups/<?= $gid ?>/sites" class="group-panel hidden" id="manage-<?= e($g['id']) ?>">
                <?= csrf_field() ?>
                <?php if (!$sites): ?><p class="muted small">No sites yet - <a href="/sites/new">add one</a>.</p><?php endif; ?>
                <div class="site-picks">
                    <?php foreach ($sites as $s): ?>
                        <?php $other = $assign[$s->id] ?? null; ?>
                        <label class="check">
                            <input type="checkbox" name="sites[]" value="<?= e($s->id) ?>" <?= $other === $g['id'] ? 'checked' : '' ?>>
                            <?= e($s->domain()) ?>
                            <?php if ($other !== null && $other !== $g['id']): ?><span class="muted small">(in <?= e($groupNames[$other] ?? $other) ?>)</span><?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php if ($sites): ?><button type="submit" class="btn btn-sm">Save sites</button><?php endif; ?>
            </form>

            <form method="post" action="/groups/<?= $gid ?>" class="group-panel row hidden" id="edit-<?= e($g['id']) ?>">
                <?= csrf_field() ?>
                <input type="text" name="name" value="<?= e($g['name']) ?>" maxlength="40" required style="flex: 1; min-width: 140px">
                <input type="color" name="color" value="<?= e($g['color']) ?>" aria-label="Colour">
                <button type="submit" class="btn btn-sm">Save</button>
            </form>
        </section>
    <?php endforeach; ?>

    <?php if ($ungrouped): ?>
    <section class="group-card is-ungrouped" style="--c: #a7aaad" data-filter-text="<?= e('ungrouped ' . implode(' ', array_map(fn($s) => $s->domain(), $ungrouped))) ?>">
        <header class="group-card-head">
            <span class="avatar">–</span>
            <div class="group-card-title">
                <h3>Ungrouped</h3>
                <span class="small muted"><?= count($ungrouped) ?> site<?= count($ungrouped) === 1 ? '' : 's' ?> without a group</span>
            </div>
        </header>
        <div class="gsites">
            <?php foreach ($ungrouped as $s): ?><?= $siteRow($s) ?><?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</div>
<?php endif; ?>
<script>
(function () {
    'use strict';
    // Live badge in front of the name box: initials + chosen colour.
    var name = document.getElementById('group-name');
    var prev = document.getElementById('group-preview');
    var EMPTY_ICON = prev ? prev.innerHTML : '';   // the light folder icon rendered by the server
    if (!name || !prev) { return; }
    function update() {
        var v = name.value.trim();
        var initials = v.split(/[\s._-]+/).slice(0, 2).map(function (w) { return w.charAt(0); }).join('').toUpperCase();
        if (initials) { prev.textContent = initials; } else { prev.innerHTML = EMPTY_ICON; }   // static markup
        var c = document.querySelector('input[name=color]:checked');
        if (c) { prev.style.setProperty('--c', c.value); }
    }
    name.addEventListener('input', update);
    document.querySelectorAll('input[name=color]').forEach(function (r) { r.addEventListener('change', update); });
})();
</script>
