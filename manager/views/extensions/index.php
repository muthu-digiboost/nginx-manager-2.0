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

/* View: extensions/index | cPanel-style extension manager for one PHP version.
   Data: $installed (all PHP), $phpId, $php (selected PHP entry|null), $extensions (ExtensionManager::list()),
         $popular (PECL names), $draft (list of names the user ticked when the last save was rejected|null) */
$enabledCount = count(array_filter($extensions, fn($e) => $e['enabled']));
$broken = array_values(array_filter($extensions, fn($e) => $e['enabled'] && !$e['loaded']));
?>
<?php if (!$php): ?>
    <div class="card empty"><h2>No PHP versions</h2><p class="muted">Install a PHP version first.</p>
        <p><a class="btn" href="/php#add-php">Add PHP Version</a></p></div>
<?php else: ?>

<form method="get" action="/extensions" class="ext-switcher card">
    <label for="php-switch" class="label" style="margin: 0">PHP version</label>
    <select id="php-switch" name="php" onchange="this.form.submit()" style="max-width: 280px">
        <?php foreach ($installed as $v): ?>
            <option value="<?= e($v['id']) ?>" <?= $v['id'] === $phpId ? 'selected' : '' ?>>PHP <?= e($v['id']) ?> (<?= e(trim($v['version'] . ' ' . strtoupper($v['threadSafety']))) ?>)</option>
        <?php endforeach; ?>
    </select>
    <span class="pill <?= $php['running'] ? 'pill-ok' : '' ?>"><span class="dot <?= $php['running'] ? 'dot-ok' : 'dot-off' ?>"></span><?= $php['running'] ? 'Running' : 'Stopped' ?></span>
    <span class="spacer"></span>
    <span class="muted"><?= $enabledCount ?> of <?= count($extensions) ?> enabled</span>
    <noscript><button class="btn btn-ghost btn-sm" type="submit">Show</button></noscript>
</form>

<?php if ($broken): ?>
    <div class="alert alert-error">
        Enabled but not loading: <strong><?= e(implode(', ', array_column($broken, 'name'))) ?></strong>.
        Untick them, or add the folder with their DLLs on <a href="/settings#php">Settings</a>.
    </div>
<?php endif; ?>

<form method="post" action="/extensions/<?= rawurlencode($phpId) ?>" class="card" id="ext-form">
    <?= csrf_field() ?>
    <h2 class="card-title">Extensions
        <span class="actions">
            <span class="ext-filters" role="tablist">
                <button type="button" class="ext-filter current" data-show="all">All</button>
                <button type="button" class="ext-filter" data-show="on">Enabled</button>
                <button type="button" class="ext-filter" data-show="off">Disabled</button>
            </span>
            <input type="search" placeholder="Search extensions..." data-list-search="#ext-grid" aria-label="Search extensions" style="width: 220px">
        </span>
    </h2>
    <div class="ext-grid" id="ext-grid" data-list data-page-size="50">
        <?php foreach ($extensions as $e): ?>
            <?php
            $checked = $draft !== null ? in_array($e['name'], $draft, true) : $e['enabled'];
            $isBroken = $e['enabled'] && !$e['loaded'];
            $builtin = $e['builtin'] ?? false;
            $title = $builtin ? 'Built into this PHP - always on'
                : (!$e['available'] ? 'The extension file is not in the extension folder' : ($isBroken ? 'Enabled in php.ini but PHP could not load it' : ''));
            ?>
            <label class="ext <?= $isBroken ? 'broken' : '' ?> <?= !$e['available'] ? 'missing' : '' ?>"
                   data-filter-text="<?= e($e['name'] . ($e['enabled'] ? ' enabled' : ' disabled')) ?>"
                   data-state="<?= $e['enabled'] ? 'on' : 'off' ?>" title="<?= e($title) ?>">
                <?php if ($builtin): ?>
                    <input type="checkbox" checked disabled aria-label="Built in">
                <?php else: ?>
                <input type="checkbox" name="ext[]" value="<?= e($e['name']) ?>" <?= $checked ? 'checked' : '' ?>
                       data-initial="<?= $e['enabled'] ? '1' : '0' ?>" <?= !$e['available'] && !$e['enabled'] ? 'disabled' : '' ?>>
                <?php endif; ?>
                <span class="name"><?= e($e['name']) ?></span>
                <?php if ($e['zend']): ?><span class="tag" title="Zend extension">zend</span><?php endif; ?>
                <?php if ($builtin): ?><span class="tag" title="Built into this PHP - always on">built in</span><?php endif; ?>
                <?php if ($isBroken): ?><span class="tag tag-bad">error</span><?php elseif ($e['enabled'] && $e['loaded']): ?><span class="dot dot-ok" title="Loaded"></span><?php endif; ?>
            </label>
        <?php endforeach; ?>
    </div>
    <div class="card-footer row">
        <button type="submit" class="btn" id="ext-save">Save Changes</button>
        <span class="muted small" id="ext-changes"></span>
    </div>
</form>

<section class="card" id="install-ext">
    <h2 class="card-title">Install a new extension <span class="muted"><?= \NginxManager\Support\Platform::isWindows()
        ? 'official Windows builds from PECL' : 'built by Homebrew from the shivammathur/extensions tap' ?></span></h2>
    <form method="post" action="/extensions/<?= rawurlencode($phpId) ?>/install" class="row" id="ext-install">
        <?= csrf_field() ?>
        <input type="text" name="ext" id="ext-name" list="pecl-popular" placeholder="Extension, e.g. redis" required pattern="[a-z0-9_]{2,40}" style="max-width: 260px" autocomplete="off">
        <datalist id="pecl-popular">
            <?php foreach ($popular as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?>
        </datalist>
        <button type="button" class="btn btn-ghost" id="ext-find">Find build</button>
        <label class="check"><input type="checkbox" name="enable" value="1" checked> Enable after installing</label>
        <button type="submit" class="btn" id="ext-install-btn" disabled>Install</button>
    </form>
    <div id="ext-result" class="small" style="margin-top: 10px"></div>
    <div class="row" style="margin-top: 10px">
        <span class="muted small">Popular:</span>
        <?php foreach ($popular as $p): ?><button type="button" class="tag ext-chip" data-ext="<?= e($p) ?>"><?= e($p) ?></button><?php endforeach; ?>
    </div>
</section>

<script>
(function () {
    'use strict';
    // -- Enabled / Disabled filter (works together with the search + pagination list) --
    var grid = document.getElementById('ext-grid');
    var show = 'all';
    grid._predicate = function (el) {
        if (show === 'all') { return true; }
        var on = el.querySelector('input').checked;
        return show === 'on' ? on : !on;
    };
    document.querySelectorAll('.ext-filter').forEach(function (b) {
        b.addEventListener('click', function () {
            show = b.dataset.show;
            document.querySelectorAll('.ext-filter').forEach(function (x) { x.classList.toggle('current', x === b); });
            if (grid._refresh) { grid._refresh(); }
        });
    });
    // -- Highlight unsaved changes --
    var info = document.getElementById('ext-changes');
    function refresh() {
        var n = 0;
        grid.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
            var changed = (cb.checked ? '1' : '0') !== cb.dataset.initial;
            cb.closest('.ext').classList.toggle('changed', changed);
            if (changed) { n++; }
        });
        info.textContent = n ? n + ' unsaved change' + (n > 1 ? 's' : '') : '';
    }
    grid.addEventListener('change', refresh);
    refresh();

    // -- Find a PECL build --
    var name = document.getElementById('ext-name');
    var result = document.getElementById('ext-result');
    var installBtn = document.getElementById('ext-install-btn');
    function find() {
        installBtn.disabled = true;
        if (!/^[a-z0-9_]{2,40}$/.test(name.value)) { result.textContent = 'Type an extension name (lower-case).'; return; }
        result.textContent = 'Searching...';
        fetch('/extensions/<?= rawurlencode($phpId) ?>/search?ext=' + encodeURIComponent(name.value), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                result.textContent = '';
                var span = document.createElement('span');
                span.className = 'alert ' + (d.found ? 'alert-success' : 'alert-warn');
                span.style.display = 'inline-block';
                span.textContent = d.found
                    ? d.ext + ' ' + d.version + ' for PHP ' + d.php + ' (' + d.flavour + (d.flavour === 'Homebrew' ? '' : ', x64') + ') - ' + d.zip
                    : d.message;
                result.appendChild(span);
                installBtn.disabled = !d.found;
            })
            .catch(function () { result.textContent = 'The search did not answer - check the internet connection.'; });
    }
    document.getElementById('ext-find').addEventListener('click', find);
    name.addEventListener('input', function () { installBtn.disabled = true; result.textContent = ''; });
    document.querySelectorAll('.ext-chip').forEach(function (c) {
        c.addEventListener('click', function () { name.value = c.dataset.ext; find(); });
    });
})();
</script>
<?php endif; ?>
