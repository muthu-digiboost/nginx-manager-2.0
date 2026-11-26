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
/* View: php/index | PHP Versions page: Updates available, Installed versions, Add PHP Version.
   Data: $php (PhpVersionRepository::installed()), $siteCounts (upstream => sites), $updates (UpdateChecker::compare()),
         $catalog (PhpCatalog::merge()), $checkedAt (?int unix time), $feedErrors (list<string>), $defaultTs ('ts'|'nts') */
use NginxManager\Php\PhpCatalog;

// Compact catalogue for the Add PHP Version dropdowns (filled by the script below).
$picker = [];
foreach ($catalog as $branch => $b) {
    $versions = [];
    foreach ($b['versions'] as $ver => $v) {
        $versions[] = [
            'v'        => $ver,
            'ts'       => isset($v['builds']['ts']),
            'nts'      => isset($v['builds']['nts']),
            'checksum' => !empty(($v['builds']['ts'] ?? $v['builds']['nts'] ?? [])['sha256']),
            'runtime'  => PhpCatalog::runtimeFor((string) (($v['builds']['ts'] ?? $v['builds']['nts'] ?? [])['compiler'] ?? '')),
        ];
    }
    $picker[$branch] = ['eol' => $b['eol'], 'versions' => $versions];
}
$installedVersions = array_column($php, 'version');
?>
<?php if ($feedErrors): ?>
    <div class="alert alert-warn">Could not reach php.net just now - the lists below may be out of date.
        <details><summary>Details</summary><pre class="code"><?= e(implode("\n", $feedErrors)) ?></pre></details></div>
<?php endif; ?>

<?php if ($updates['updates']): ?>
<section class="card" style="border-left: 4px solid var(--warn)">
    <h2 class="card-title">Updates available (<?= count($updates['updates']) ?>)
        <span class="muted"><?= $checkedAt ? 'checked ' . e(date('M j, H:i', $checkedAt)) : '' ?></span></h2>
    <table class="table">
        <tbody>
        <?php foreach ($updates['updates'] as $u): ?>
            <tr>
                <td><span class="row-title">PHP <?= e($u['id']) ?></span>
                    <?php if ($u['isManager']): ?> <span class="tag tag-info">runs this manager</span><?php endif; ?></td>
                <td class="nowrap"><span class="ver-change"><span class="ver-old"><?= e($u['version']) ?></span><span class="ver-arrow">→</span><strong class="ver-new"><?= e($u['latest']) ?></strong></span></td>
                <td class="muted small update-note">Your php.ini and extra extensions are kept. The old version is backed up and restored automatically if the new one does not start.</td>
                <td class="actions">
                    <form method="post" action="/php/<?= rawurlencode($u['id']) ?>/update">
                        <?= csrf_field() ?>
                        <button class="btn" type="submit">Update to <?= e($u['latest']) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<section class="card">
    <h2 class="card-title">Installed PHP versions <span class="muted">each runs as php-cgi on its own port</span></h2>
    <?php if (!$php): ?>
        <p class="muted">No PHP versions yet - add one below.</p>
    <?php else: ?>
        <?= \NginxManager\Support\View::partial('partials/php-services', [
            'php'        => $php,
            'siteCounts' => $siteCounts,
            'eolIds'     => array_column($updates['eol'], 'id'),
            'pinnedIds'  => array_column($updates['pinned'], 'id'),
        ]) ?>
    <?php endif; ?>
</section>

<?php
$current = null;
foreach ($managerCandidates as $c) {
    if ($c['isManager']) { $current = $c; }
}
$eligible = array_values(array_filter($managerCandidates, static fn($c) => $c['eligible']));
?>
<section class="card" id="manager-php">
    <h2 class="card-title">Manager PHP <span class="muted">the PHP version this manager itself runs on</span></h2>
    <form method="post" class="form" id="manager-php-form"
          action="/php/<?= $eligible ? rawurlencode(end($eligible)['id']) : '' ?>/manager"
          data-confirm="Click again to switch">
        <?= csrf_field() ?>
        <table class="form-table">
            <tr>
                <th>Runs on</th>
                <td><strong>PHP <?= e($current['version'] ?? PHP_VERSION) ?></strong>
                    <span class="tag tag-info">runs this manager</span></td>
            </tr>
            <tr>
                <th><label for="manager-php-target">Switch to</label></th>
                <td>
                    <?php if (!$eligible): ?>
                        <span class="muted">No other installed version can run the manager (needs PHP <?= e(\NginxManager\Support\ManagerRequirements::MIN_PHP) ?> or newer).</span>
                    <?php else: ?>
                        <div class="row">
                            <select id="manager-php-target" style="max-width: 260px">
                                <?php foreach ($managerCandidates as $c): if ($c['isManager']) continue; ?>
                                    <option value="<?= e($c['id']) ?>" <?= $c['eligible'] ? '' : 'disabled' ?> <?= $c['id'] === end($eligible)['id'] ? 'selected' : '' ?>>
                                        PHP <?= e($c['version']) ?><?= $c['eligible'] ? '' : ' - ' . e($c['reason']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn" type="submit">Switch manager PHP</button>
                        </div>
                        <p class="description">Checked before switching. If anything fails, it stays on PHP <?= e($current['version'] ?? PHP_VERSION) ?>.</p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </form>
    <script>
    (function () {
        var sel = document.getElementById('manager-php-target');
        var form = document.getElementById('manager-php-form');
        if (!sel || !form) { return; }
        function sync() { form.action = '/php/' + encodeURIComponent(sel.value) + '/manager'; }
        sel.addEventListener('change', sync);
        sync();
    })();
    </script>
</section>

<section class="card" id="add-php">
    <?php if (isset($macBranches)): /* macOS: Homebrew, shivammathur/php tap */ ?>
    <h2 class="card-title">Add PHP Version <span class="muted">installed with Homebrew from the shivammathur/php tap, 5.6 to the newest</span></h2>
    <?php
    $installedIds = array_column($php, 'id');
    $available = array_values(array_filter($macBranches, static fn($b) => !in_array($b['branch'], $installedIds, true)));
    ?>
    <?php if (!$available): ?>
        <p class="muted">Every PHP branch Homebrew offers is already installed.</p>
    <?php else: ?>
        <form method="post" action="/php/install" class="form">
            <?= csrf_field() ?>
            <table class="form-table">
                <tr>
                    <th><label for="php-branch">Version</label></th>
                    <td>
                        <div class="row">
                            <select id="php-branch" name="branch" style="max-width: 260px">
                                <?php foreach ($available as $i => $b): ?>
                                    <option value="<?= e($b['branch']) ?>"><?= e('PHP ' . $b['branch']) ?><?= $b['eol'] ? ' (end of life)' : '' ?><?= $i === 0 ? ' (newest)' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn" type="submit">Install</button>
                        </div>
                        <p class="description">Installs the newest PHP of that branch with Homebrew, registers a port and nginx upstream, then starts it. This can take several minutes.</p>
                    </td>
                </tr>
            </table>
        </form>
    <?php endif; ?>
    <?php else: ?>
    <h2 class="card-title">Add PHP Version <span class="muted">official PHP for Windows builds from php.net, 5.5 to the newest</span></h2>
    <?php if (!$catalog): ?>
        <p class="muted">The version list could not be loaded. Check the internet connection and use "Check for updates".</p>
    <?php else: ?>
        <form method="post" action="/php/install" class="form">
            <?= csrf_field() ?>
            <table class="form-table">
                <tr>
                    <th><label for="php-branch">Branch</label></th>
                    <td>
                        <select id="php-branch" name="branch" style="max-width: 260px">
                            <?php foreach ($catalog as $branch => $b): ?>
                                <option value="<?= e($branch) ?>"><?= e("PHP $branch") ?><?= $b['eol'] ? ' (end of life)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="php-version">Version</label></th>
                    <td><select id="php-version" name="version" style="max-width: 260px"></select></td>
                </tr>
                <tr>
                    <th>Build</th>
                    <td class="row">
                        <label class="check"><input type="radio" name="flavour" value="ts" <?= $defaultTs === 'ts' ? 'checked' : '' ?>> Thread safe (TS)</label>
                        <label class="check"><input type="radio" name="flavour" value="nts" <?= $defaultTs === 'nts' ? 'checked' : '' ?>> Non thread safe (NTS)</label>
                        <span class="muted small">Both work with nginx; use the same as your other versions if unsure.</span>
                    </td>
                </tr>
                <tr>
                    <th></th>
                    <td><div id="php-notes" class="stack"></div></td>
                </tr>
                <tr>
                    <th></th>
                    <td><button class="btn" type="submit" id="php-install">Install</button>
                        <span class="muted small">Downloads, sets up php.ini, registers a port and nginx upstream, then starts it.</span></td>
                </tr>
            </table>
        </form>
        <script type="application/json" id="php-catalog"><?= json_encode(['branches' => $picker, 'installed' => $installedVersions], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
        <script>
        (function () {
            'use strict';
            var data = JSON.parse(document.getElementById('php-catalog').textContent);
            var branchSel = document.getElementById('php-branch');
            var versionSel = document.getElementById('php-version');
            var notes = document.getElementById('php-notes');
            var button = document.getElementById('php-install');

            function note(type, text) {
                var div = document.createElement('div');
                div.className = 'alert alert-' + type;
                div.textContent = text;
                notes.appendChild(div);
            }
            function current() {
                var b = data.branches[branchSel.value];
                return b && b.versions.filter(function (v) { return v.v === versionSel.value; })[0];
            }
            function flavour() {
                var r = document.querySelector('input[name=flavour]:checked');
                return r ? r.value : 'ts';
            }
            function refreshNotes() {
                notes.textContent = '';
                var b = data.branches[branchSel.value], v = current();
                if (!v) { button.disabled = true; return; }
                var installed = data.installed.indexOf(v.v) !== -1;
                var hasBuild = v[flavour()];
                button.disabled = installed || !hasBuild;
                if (installed) { note('info', 'PHP ' + v.v + ' is already installed.'); }
                if (!hasBuild) { note('error', 'php.net has no ' + flavour().toUpperCase() + ' x64 build of ' + v.v + ' - pick the other build type.'); }
                if (b.eol) { note('warn', 'PHP ' + branchSel.value + ' is end of life: it gets no security fixes. Fine for a legacy project on this machine.'); }
                if (!v.checksum) { note('warn', 'php.net publishes no checksum for this archived build - it is downloaded over HTTPS only.'); }
                note('info', 'Needs the ' + v.runtime + ' runtime from Microsoft. The installer checks this and tells you if it is missing.');
            }
            function fillVersions() {
                versionSel.textContent = '';
                data.branches[branchSel.value].versions.forEach(function (v, i) {
                    var o = document.createElement('option');
                    o.value = v.v;
                    o.textContent = v.v + (i === 0 ? ' (latest)' : '') + (data.installed.indexOf(v.v) !== -1 ? ' - installed' : '');
                    versionSel.appendChild(o);
                });
                refreshNotes();
            }
            branchSel.addEventListener('change', fillVersions);
            versionSel.addEventListener('change', refreshNotes);
            document.querySelectorAll('input[name=flavour]').forEach(function (r) { r.addEventListener('change', refreshNotes); });
            fillVersions();
        })();
        </script>
    <?php endif; ?>
    <?php endif; /* Windows */ ?>
</section>
