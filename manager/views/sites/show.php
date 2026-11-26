<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : show.php
 *  Date    : 26/11/2026
 *  Author  : Semansys Development Team
 *
 *  Copyright (c) 2026
 *  Tel: +91 97863 37914
 *  Email: dev.digiboost.muthu@gmail.com
 *  All rights reserved, see the LICENSE file for more information.
 *
 ******************************************************************************/

/* View: sites/show | One site: Overview (editable), Config (raw editor), Logs, Backups, and the
   enable / delete actions.
   Data: $site (Site), $raw (file text), $rootAbs, $groupId, $certExists, $errorLog/$accessLog (paths),
         $errorTail/$accessTail (text), $backups (names), $inHosts (bool), $roots, $phpList, $groups */
use NginxManager\Support\Paths;

$p = $site->parsed;
$id = rawurlencode($site->id);
$selPhp = '';
foreach ($phpList as $v) {
    if ($v['upstream'] === $p->upstream) { $selPhp = $v['id']; }
}
$selVar = 'projects_dir';
$selPath = (string) $p->root;
if (preg_match('~^\$(projects_dir(?:_[a-z0-9]+)?)(?:/(.*))?$~', (string) $p->root, $m)) {
    [$selVar, $selPath] = [$m[1], $m[2] ?? ''];
}
$draft = $_SESSION['_draft'][$site->id] ?? null;
$url = ($p->ssl ? 'https://' : 'http://') . $site->domain();
?>
<section class="site-hero <?= $site->enabled ? '' : 'is-disabled' ?>">
    <span class="site-badge lg" style="--c: var(--primary)"><?= e(mb_strtoupper(mb_substr($site->domain(), 0, 1))) ?></span>
    <div class="site-hero-main">
        <a class="site-hero-url" href="<?= e($url) ?>" target="_blank" rel="noopener"><?= e($url) ?></a>
        <div class="row" style="gap: 6px; margin-top: 6px">
            <?= $site->enabled ? '<span class="tag tag-ok">Enabled</span>' : '<span class="tag tag-warn">Disabled</span>' ?>
            <?= $p->ssl ? '<span class="ssl-pill"><svg viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>HTTPS</span>' : '<span class="ssl-pill off">HTTP</span>' ?>
            <?php foreach ($phpList as $v): if ($v['upstream'] === $p->upstream): ?>
                <span class="php-pill <?= $v['running'] ? 'on' : '' ?>"><span class="dot <?= $v['running'] ? 'dot-ok' : 'dot-off' ?>"></span>PHP <?= e($v['id']) ?></span>
            <?php endif; endforeach; ?>
            <?php if ($p->framework): ?><span class="tag"><?= e(ucfirst($p->framework)) ?></span><?php endif; ?>
            <?php if (!$inHosts): ?><span class="tag tag-warn" title="The domain is not in the hosts file">not in hosts file</span><?php endif; ?>
        </div>
    </div>
    <div class="site-hero-actions">
        <a class="btn btn-ghost" href="<?= e($url) ?>" target="_blank" rel="noopener">Visit site ↗</a>
        <form method="post" action="/sites/<?= $id ?>/toggle">
            <?= csrf_field() ?>
            <button type="submit" class="btn <?= $site->enabled ? 'btn-ghost' : '' ?>"><?= $site->enabled ? 'Disable' : 'Enable' ?></button>
        </form>
    </div>
</section>

<div data-tabs>
    <div class="tabs">
        <button type="button" class="tab" data-tab="overview">Overview</button>
        <button type="button" class="tab" data-tab="config">Config</button>
        <button type="button" class="tab" data-tab="logs">Logs</button>
        <button type="button" class="tab" data-tab="backups">Backups (<?= count($backups) ?>)</button>
    </div>

    <!-- Overview ------------------------------------------------------------- -->
    <div class="tab-panel" data-panel="overview">
        <div class="grid-2" style="grid-template-columns: 2fr 1fr; align-items: start">
            <form method="post" action="/sites/<?= $id ?>" class="card">
                <?= csrf_field() ?>
                <h2 class="card-title">Settings</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="domains">Domains</label></th>
                        <td><input type="text" id="domains" name="domains" value="<?= e(implode(' ', $p->serverNames)) ?>" required></td>
                    </tr>
                    <?= \NginxManager\Support\View::partial('sites/partials/fields', [
                        'roots' => $roots, 'phpList' => $phpList, 'groups' => $groups,
                        'selRootVar' => $selVar, 'selRootPath' => $selPath, 'selPhp' => $selPhp, 'selGroup' => (string) $groupId,
                        'keepPhpOption' => $selPhp === '',   // static/proxied site or PHP not installed here
                    ]) ?>
                    <tr>
                        <th>HTTPS</th>
                        <td>
                            <label class="check">
                                <input type="checkbox" name="ssl" value="1" <?= $p->ssl ? 'checked' : '' ?> <?= (!$p->ssl && !$certExists) ? 'disabled' : '' ?>>
                                Serve this site over HTTPS (HTTP redirects to it)
                            </label>
                            <?php if (!$p->ssl && !$certExists): ?>
                                <div class="small muted" style="margin-top: 4px"><a href="/ssl?domain=<?= rawurlencode($site->domain()) ?>">Create a certificate</a> to enable HTTPS.</div>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <div class="card-footer"><button type="submit" class="btn">Save Changes</button></div>
            </form>

            <div class="stack">
                <section class="card">
                    <h2 class="card-title">Details</h2>
                    <table class="table">
                        <tr><td class="muted">Framework</td><td><?= e($p->framework ?? '—') ?></td></tr>
                        <tr><td class="muted">Folder</td><td class="mono small" style="word-break: break-all"><?= e($rootAbs ?? '—') ?>
                            <?php if ($rootAbs && !is_dir($rootAbs)): ?><span class="tag tag-bad">missing</span><?php endif; ?></td></tr>
                        <tr><td class="muted">Upstream</td><td class="mono"><?= e($p->upstream ?? '—') ?></td></tr>
                        <tr><td class="muted">Config file</td><td class="mono small"><?= e(basename($site->file)) ?></td></tr>
                    </table>
                </section>
                <section class="card">
                    <h2 class="card-title">Actions</h2>
                    <div class="stack">
                        <form method="post" action="/sites/<?= $id ?>/toggle">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-ghost btn-block"><?= $site->enabled ? 'Disable site' : 'Enable site' ?></button>
                        </form>
                        <form method="post" action="/sites/<?= $id ?>/delete" data-confirm="Click again to delete">
                            <?= csrf_field() ?>
                            <label class="check small" style="margin-bottom: 6px"><input type="checkbox" name="hosts" value="1" checked> Also remove from the hosts file</label>
                            <button type="submit" class="btn btn-danger btn-block">Delete site</button>
                        </form>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <!-- Config ---------------------------------------------------------------- -->
    <div class="tab-panel" data-panel="config">
        <form method="post" action="/sites/<?= $id ?>/raw" class="card">
            <?= csrf_field() ?>
            <h2 class="card-title">nginx configuration <span class="muted"><?= e(basename($site->file)) ?></span>
                <?php if ($draft !== null): ?><span class="tag tag-warn">unsaved draft - nginx rejected it</span><?php endif; ?></h2>
            <textarea name="content" spellcheck="false" aria-label="nginx configuration"><?= e($draft ?? $raw) ?></textarea>
            <div class="card-footer row">
                <button type="submit" class="btn">Test &amp; Save</button>
                <?php if ($draft !== null): ?><a class="btn btn-ghost" href="/sites/<?= $id ?>#config" onclick="this.closest('form').querySelector('textarea').value = <?= e(json_encode($raw)) ?>; return false;">Discard draft</a><?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Logs -------------------------------------------------------------------- -->
    <div class="tab-panel" data-panel="logs">
        <section class="card">
            <h2 class="card-title">Error log <span class="muted mono"><?= e($p->errorLog ?? 'none') ?></span></h2>
            <pre class="code dark"><?= e($errorTail !== '' ? $errorTail : 'No errors logged.') ?></pre>
        </section>
        <section class="card" style="margin-top: 20px">
            <h2 class="card-title">Access log <span class="muted mono"><?= e($p->accessLog ?? 'none') ?></span></h2>
            <pre class="code dark"><?= e($accessTail !== '' ? $accessTail : 'No requests logged yet.') ?></pre>
        </section>
    </div>

    <!-- Backups ------------------------------------------------------------------- -->
    <div class="tab-panel" data-panel="backups">
        <section class="card">
            <h2 class="card-title">Previous versions</h2>
            <?php if (!$backups): ?>
                <p class="muted">No backups yet - one is made automatically before every change.</p>
            <?php else: ?>
                <table class="table">
                    <thead><tr><th>Saved</th><th class="actions"></th></tr></thead>
                    <tbody>
                    <?php foreach ($backups as $name): ?>
                        <?php $ts = DateTime::createFromFormat('Ymd-His', substr($name, 0, 15)); ?>
                        <tr>
                            <td><?= e($ts ? $ts->format('M j, Y H:i:s') : $name) ?></td>
                            <td class="actions">
                                <form method="post" action="/sites/<?= $id ?>/restore" data-confirm="Click again to restore">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="name" value="<?= e($name) ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm">Restore</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </div>
</div>
