<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : create.php
 *  Date    : 26/11/2026
 *  Author  : Semansys Development Team
 *
 *  Copyright (c) 2026
 *  Tel: +91 97863 37914
 *  Email: dev.digiboost.muthu@gmail.com
 *  All rights reserved, see the LICENSE file for more information.
 *
 ******************************************************************************/

/* View: sites/create | "Add New Site": form sections on the left, live summary on the right,
   framework chosen from tiles.
   Data: $roots, $phpList, $frameworks, $groups, $old (values from a rejected submit) */
$o = static fn(string $k, string $d = '') => (string) ($old[$k] ?? $d);
$defaultPhp = '';
foreach ($phpList as $v) {
    if ($v['running'] && version_compare($v['id'], $defaultPhp ?: '0', '>')) { $defaultPhp = $v['id']; }
}
unset($_SESSION['_old']);
$fwIcons = [
    'general'     => '<path d="M8 6l-6 6 6 6M16 6l6 6-6 6"/>',
    'laravel'     => '<path d="M3 7l9-4 9 4-9 4zM3 7v10l9 4 9-4V7"/>',
    'symfony'     => '<circle cx="12" cy="12" r="9"/><path d="M8 15c2 1 4 0 5-3s3-4 5-3"/>',
    'wordpress'   => '<circle cx="12" cy="12" r="9"/><path d="M6 8l4 10 2-6 2 6 4-10"/>',
    'codeigniter' => '<path d="M12 3c3 4-2 5 1 9 1-2 3-2 3 1a4 4 0 0 1-8 0c0-4 4-5 4-10z"/>',
];
?>
<form method="post" action="/sites" class="create-site" id="create-site">
    <?= csrf_field() ?>
    <div class="create-site-main">
        <section class="card">
            <h2 class="card-title"><span class="step">1</span> Domain</h2>
            <div class="form-grid">
                <div class="field">
                    <label for="domain">Domain</label>
                    <input type="text" id="domain" name="domain" value="<?= e($o('domain')) ?>" placeholder="myapp.test" required autofocus>
                </div>
                <div class="field">
                    <label for="aliases">Other domains <span class="hint">optional</span></label>
                    <input type="text" id="aliases" name="aliases" value="<?= e($o('aliases')) ?>" placeholder="www.myapp.test api.myapp.test">
                </div>
            </div>
        </section>

        <section class="card">
            <h2 class="card-title"><span class="step">2</span> Project</h2>
            <table class="form-table compact">
                <?= \NginxManager\Support\View::partial('sites/partials/fields', [
                    'roots' => $roots, 'phpList' => $phpList, 'groups' => $groups,
                    'selRootVar' => $o('root_var', 'projects_dir'), 'selRootPath' => $o('root_path'),
                    'selPhp' => $o('php', $defaultPhp), 'selGroup' => $o('group'),
                ]) ?>
            </table>
            <div class="field" style="margin-top: 6px">
                <span class="label">Framework</span>
                <div class="fw-tiles" role="radiogroup" aria-label="Framework">
                    <?php foreach ($frameworks as $key => $label): ?>
                        <label class="fw-tile">
                            <input type="radio" name="framework" value="<?= e($key) ?>" <?= $o('framework', 'laravel') === $key ? 'checked' : '' ?>>
                            <span class="fw-ic"><svg viewBox="0 0 24 24"><?= $fwIcons[$key] ?? $fwIcons['general'] ?></svg></span>
                            <span class="fw-name"><?= e(explode(' (', $label)[0]) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="card">
            <h2 class="card-title"><span class="step">3</span> Options</h2>
            <div class="option-rows">
                <label class="option-row">
                    <input type="checkbox" name="hosts" value="1" <?= $o('hosts', '1') === '1' ? 'checked' : '' ?>>
                    <span><strong>Add to the hosts file</strong><br><span class="muted small">The domain points to this computer (127.0.0.1).</span></span>
                </label>
                <label class="option-row">
                    <input type="checkbox" name="ssl" value="1" id="opt-ssl" <?= $o('ssl') === '1' ? 'checked' : '' ?>>
                    <span><strong>Use HTTPS</strong><br><span class="muted small">Needs a certificate for this domain from <a href="/ssl">SSL Certificates</a>.</span></span>
                </label>
            </div>
        </section>
    </div>

    <aside class="create-site-side">
        <section class="card summary-card">
            <h2 class="card-title">Summary</h2>
            <dl class="summary">
                <dt>Address</dt><dd class="mono" id="sum-url">http://myapp.test</dd>
                <dt>Folder</dt><dd class="mono" id="sum-root">—</dd>
                <dt>PHP</dt><dd id="sum-php">—</dd>
                <dt>Framework</dt><dd id="sum-fw">—</dd>
                <dt>Group</dt><dd id="sum-group">—</dd>
            </dl>
            <div class="summary-foot">
                <button type="submit" class="btn btn-block">Add Site</button>
                <a class="btn btn-ghost btn-block" href="/sites">Cancel</a>
            </div>
            <p class="muted small" style="margin: 10px 0 0">The config is tested with nginx before it is used; nothing changes if the test fails.</p>
        </section>
    </aside>
</form>
<script>
(function () {
    'use strict';
    var f = document.getElementById('create-site');
    function val(name) { var el = f.elements[name]; return el ? (el.value || '').trim() : ''; }
    function selText(name) { var el = f.elements[name]; return el && el.selectedIndex >= 0 ? el.options[el.selectedIndex].text.trim() : ''; }
    function update() {
        var https = document.getElementById('opt-ssl').checked;
        document.getElementById('sum-url').textContent = (https ? 'https://' : 'http://') + (val('domain') || 'myapp.test');
        var base = selText('root_var'), path = val('root_path');
        document.getElementById('sum-root').textContent = base ? base + path : '—';
        document.getElementById('sum-php').textContent = selText('php') || '—';
        var fw = f.querySelector('input[name=framework]:checked');
        document.getElementById('sum-fw').textContent = fw ? fw.closest('.fw-tile').querySelector('.fw-name').textContent : '—';
        document.getElementById('sum-group').textContent = val('group_new') || (val('group') ? selText('group') : 'No group');
    }
    f.addEventListener('input', update);
    f.addEventListener('change', update);
    update();
})();
</script>
