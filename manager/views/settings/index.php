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

/* View: settings/index | Settings with a section menu on the left and a sticky Save bar.
   Data: $folders (list of {name, var, path, main}), $usage (var => sites), $extraPath (list),
         $openssl (setting), $opensslFound (?string), $ts, $timezone, $zones, $system (label => value),
         $desktopApp (?string: the app's version when shown inside the desktop app) */
$desktopApp = $desktopApp ?? null;
$exists = static fn(string $p): bool => $p !== '' && is_dir($p);
?>
<form method="post" action="/settings" class="settings-layout">
    <?= csrf_field() ?>
    <nav class="settings-nav" aria-label="Settings sections">
        <a href="#general">General</a>
        <a href="#folders">Project folders</a>
        <a href="#php">PHP</a>
        <a href="#certificates">Certificates</a>
        <a href="#system">System</a>
        <?php if ($desktopApp !== null): ?><a href="#desktop-app">Desktop app</a><?php endif; ?>
    </nav>

    <div class="settings-sections">
        <!-- General ------------------------------------------------------------ -->
        <section class="card" id="general">
            <h2 class="card-title">General</h2>
            <table class="form-table">
                <tr>
                    <th><label for="timezone">Time zone</label></th>
                    <td>
                        <select id="timezone" name="timezone" style="max-width: 320px">
                            <?php foreach ($zones as $z): ?>
                                <option value="<?= e($z) ?>" <?= $z === $timezone ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $z)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Used for dates and times shown in the manager.</p>
                    </td>
                </tr>
            </table>
        </section>

        <!-- Project folders ----------------------------------------------------- -->
        <section class="card" id="folders">
            <h2 class="card-title">Project folders <span class="muted">sites point to a folder inside one of these</span></h2>
            <table class="table settings-folders">
                <thead><tr><th>Folder</th><th style="width: 90px">Sites</th><th style="width: 60px"></th></tr></thead>
                <tbody id="folder-rows">
                <?php foreach ($folders as $f): ?>
                    <tr>
                        <td>
                            <?php if (!$f['main']): ?>
                                <!-- the name is kept so sites using $projects_dir_<name> keep working -->
                                <input type="hidden" name="extra_name[]" value="<?= e($f['name']) ?>">
                            <?php endif; ?>
                            <div class="row" style="flex-wrap: nowrap">
                                <input type="text" name="<?= $f['main'] ? 'projects_dir' : 'extra_folder[]' ?>" value="<?= e($f['path']) ?>" placeholder="<?= $f['main'] ? 'E:/projects' : 'D:/projects' ?>" aria-label="Folder path">
                                <?php if ($f['path'] !== ''): ?>
                                    <span class="state-icon <?= $exists($f['path']) ? 'ok' : 'bad' ?>" title="<?= $exists($f['path']) ? 'Folder found' : 'Folder not found' ?>"><?= $exists($f['path']) ? '✓' : '✕' ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="muted small" style="margin-top: 3px"><?= $f['main'] ? '<strong>Main folder</strong> · ' : '' ?><span class="mono">$<?= e($f['var']) ?></span></div>
                        </td>
                        <td><?= (int) ($usage[$f['var']] ?? 0) ?></td>
                        <td class="actions">
                            <?php if (!$f['main'] && empty($usage[$f['var']])): ?>
                                <button type="button" class="icon-btn" data-remove-row title="Remove">✕</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <template id="folder-template">
                <tr>
                    <td>
                        <input type="hidden" name="extra_name[]" value="">
                        <input type="text" name="extra_folder[]" placeholder="D:/projects" aria-label="Folder path">
                        <div class="muted small" style="margin-top: 3px">Name is set automatically when you save</div>
                    </td>
                    <td></td>
                    <td class="actions"><button type="button" class="icon-btn" data-remove-row title="Remove">✕</button></td>
                </tr>
            </template>
            <button type="button" class="btn btn-ghost btn-sm" data-add-row="#folder-template" data-target="#folder-rows" style="margin-top: 10px">+ Add folder</button>
        </section>

        <!-- PHP --------------------------------------------------------------------- -->
        <section class="card" id="php">
            <h2 class="card-title">PHP</h2>
            <?php if (!\NginxManager\Support\Platform::isWindows()): ?>
                <p class="description">PHP versions come from Homebrew; add and update them on <a href="/php">PHP Versions</a>.
                    The manager's own PHP is chosen there too (Manager PHP).</p>
            <?php else: ?>
            <table class="form-table">
                <tr>
                    <th>Default build for new versions</th>
                    <td class="row">
                        <label class="choice <?= $ts === 'ts' ? 'on' : '' ?>"><input type="radio" name="php_ts" value="ts" <?= $ts === 'ts' ? 'checked' : '' ?>> <strong>Thread safe</strong> <span class="muted">TS</span></label>
                        <label class="choice <?= $ts === 'nts' ? 'on' : '' ?>"><input type="radio" name="php_ts" value="nts" <?= $ts === 'nts' ? 'checked' : '' ?>> <strong>Non thread safe</strong> <span class="muted">NTS</span></label>
                    </td>
                </tr>
                <tr>
                    <th>Extra folders on PATH</th>
                    <td>
                        <div id="path-rows" class="stack" style="max-width: 620px">
                            <?php foreach ($extraPath ?: [''] as $p): ?>
                                <div class="row" style="flex-wrap: nowrap">
                                    <input type="text" name="php_path[]" value="<?= e($p) ?>" placeholder="Folder with extra DLLs, e.g. Oracle Instant Client" aria-label="Folder">
                                    <?php if ($p !== ''): ?><span class="state-icon <?= $exists($p) ? 'ok' : 'bad' ?>"><?= $exists($p) ? '✓' : '✕' ?></span><?php endif; ?>
                                    <button type="button" class="icon-btn" data-remove-row title="Remove">✕</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <template id="path-template">
                            <div class="row" style="flex-wrap: nowrap">
                                <input type="text" name="php_path[]" placeholder="Folder" aria-label="Folder">
                                <button type="button" class="icon-btn" data-remove-row title="Remove">✕</button>
                            </div>
                        </template>
                        <button type="button" class="btn btn-ghost btn-sm" data-add-row="#path-template" data-target="#path-rows" style="margin-top: 8px">+ Add folder</button>
                        <p class="description">DLL folders some extensions need, e.g. the Oracle Instant Client for oci8.</p>
                    </td>
                </tr>
            </table>
            <?php endif; ?>
        </section>

        <!-- Certificates ------------------------------------------------------------- -->
        <section class="card" id="certificates">
            <h2 class="card-title">Certificates</h2>
            <table class="form-table">
                <tr>
                    <th><label for="openssl_bin">OpenSSL</label></th>
                    <td>
                        <input type="text" id="openssl_bin" name="openssl_bin" value="<?= e($openssl) ?>" placeholder="Detected automatically" style="max-width: 560px">
                        <p class="description">
                            <?php if ($opensslFound): ?><span class="state-icon ok">✓</span> Using <code><?= e($opensslFound) ?></code>
                            <?php else: ?><span class="state-icon bad">✕</span> Not found - <?= \NginxManager\Support\Platform::isWindows()
                                ? 'install Git for Windows' : 'run <code>brew install openssl@3</code>' ?> or enter the path.<?php endif; ?>
                        </p>
                    </td>
                </tr>
            </table>
        </section>

        <!-- System (read-only) -------------------------------------------------------- -->
        <section class="card" id="system">
            <h2 class="card-title">System</h2>
            <table class="table">
                <?php foreach ($system as $label => $value): ?>
                    <tr><td class="muted" style="width: 200px"><?= e($label) ?></td><td class="mono"><?= e($value) ?></td></tr>
                <?php endforeach; ?>
            </table>
        </section>

        <?php if ($desktopApp !== null): ?>
        <!-- Desktop app (only inside the app: its links are handled by the app) --------------- -->
        <section class="card" id="desktop-app">
            <h2 class="card-title">Desktop app <span class="muted">updates come from GitHub releases</span></h2>
            <table class="table">
                <tr><td class="muted" style="width: 200px">Version</td><td class="mono"><?= e($desktopApp) ?></td></tr>
                <tr>
                    <td class="muted">Updates</td>
                    <td>
                        <a class="btn" href="<?= e(\NginxManager\Support\DesktopApp::CHECK_UPDATES_URL) ?>">Check for updates</a>
                        <a class="btn btn-secondary" href="<?= e(\NginxManager\Support\DesktopApp::SETTINGS_URL) ?>">App settings</a>
                        <div class="muted small" style="margin-top: 6px">Opens the app's Settings window and checks for a new version; <b>Update now</b> installs it - sites, certificates and settings are kept.</div>
                    </td>
                </tr>
            </table>
        </section>
        <?php endif; ?>
    </div>

    <div class="savebar">
        <span class="muted small">Changes apply to nginx immediately after saving.</span>
        <button type="submit" class="btn">Save Changes</button>
    </div>
</form>
<script>
(function () {
    'use strict';
    document.addEventListener('click', function (ev) {
        var add = ev.target.closest('[data-add-row]');
        if (add) {
            var tpl = document.querySelector(add.dataset.addRow);
            document.querySelector(add.dataset.target).appendChild(tpl.content.cloneNode(true));
        }
        var rm = ev.target.closest('[data-remove-row]');
        if (rm) {
            var row = rm.closest('tr') || rm.closest('.row');
            row.parentNode.removeChild(row);
        }
    });
    document.querySelectorAll('.choice input').forEach(function (r) {
        r.addEventListener('change', function () {
            document.querySelectorAll('.choice').forEach(function (c) { c.classList.toggle('on', c.querySelector('input').checked); });
        });
    });
    // Highlight the section in view in the left menu.
    var links = document.querySelectorAll('.settings-nav a');
    var obs = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
            if (en.isIntersecting) {
                links.forEach(function (a) { a.classList.toggle('current', a.getAttribute('href') === '#' + en.target.id); });
            }
        });
    }, { rootMargin: '-40% 0px -55% 0px' });
    document.querySelectorAll('.settings-sections > section').forEach(function (s) { obs.observe(s); });
})();
</script>
