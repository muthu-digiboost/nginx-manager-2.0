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

/* View: ssl/index | Certificate overview (stats), collapsible create panel and certificate cards.
   Data: $certs (list<CertInfo>), $sites (list<Site>), $prefill (domain from ?domain=), $openssl (?string path) */
use NginxManager\Ssl\CertInfo;

$httpSites = array_values(array_filter($sites, fn($s) => !$s->parsed->ssl));
$trusted = count(array_filter($certs, fn(CertInfo $c) => $c->trusted));
$soon = count(array_filter($certs, fn(CertInfo $c) => in_array($c->status(), ['soon', 'expired'], true)));
$inUse = count(array_filter($certs, fn(CertInfo $c) => (bool) $c->usedBy));
$openCreate = $prefill !== '' || !$certs;
$lock = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';
?>
<div class="stat-strip">
    <div class="stat-box">
        <span class="stat-ic"><?= $lock ?></span>
        <span class="stat-box-text"><span class="stat-box-value"><?= count($certs) ?></span><span class="stat-box-label">Certificates</span></span>
    </div>
    <div class="stat-box ok">
        <span class="stat-ic"><svg viewBox="0 0 24 24"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/></svg></span>
        <span class="stat-box-text"><span class="stat-box-value"><?= $trusted ?></span><span class="stat-box-label">Trusted by <?= \NginxManager\Support\Platform::label() ?></span></span>
    </div>
    <div class="stat-box <?= $soon ? 'warn' : '' ?>">
        <span class="stat-ic"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
        <span class="stat-box-text"><span class="stat-box-value"><?= $soon ?></span><span class="stat-box-label">Expiring within 30 days</span></span>
    </div>
    <div class="stat-box">
        <span class="stat-ic"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg></span>
        <span class="stat-box-text"><span class="stat-box-value"><?= $inUse ?></span><span class="stat-box-label">Used by sites</span></span>
    </div>
</div>

<section class="card create-panel <?= $openCreate ? '' : 'hidden' ?>" id="create-panel">
    <h2 class="card-title">New certificate <span class="actions"><button type="button" class="icon-btn" data-toggle="#create-panel" title="Close">✕</button></span></h2>
    <?php if (!$openssl): ?>
        <div class="alert alert-error">OpenSSL was not found. <?= \NginxManager\Support\Platform::isWindows() ? 'Install Git for Windows' : 'Run <code>brew install openssl@3</code>' ?> or set its path on <a href="/settings#certificates">Settings</a>.</div>
    <?php endif; ?>
    <form method="post" action="/ssl/generate" class="create-grid">
        <?= csrf_field() ?>
        <div class="field">
            <label for="ssl-domain">Domain</label>
            <input type="text" id="ssl-domain" name="domain" value="<?= e($prefill) ?>" placeholder="myapp.test or *.myapp.test" required>
        </div>
        <div class="field">
            <label for="ssl-sans">Extra domains <span class="hint">optional</span></label>
            <input type="text" id="ssl-sans" name="sans" placeholder="api.myapp.test admin.myapp.test">
        </div>
        <div class="field">
            <label for="ssl-site">Switch a site to HTTPS <span class="hint">optional</span></label>
            <select id="ssl-site" name="apply_site" <?= $httpSites ? '' : 'disabled' ?>>
                <option value=""><?= $httpSites ? "— Don't change any site —" : 'All sites already use HTTPS' ?></option>
                <?php foreach ($httpSites as $s): ?>
                    <option value="<?= e($s->id) ?>" <?= $prefill !== '' && in_array($prefill, $s->parsed->serverNames, true) ? 'selected' : '' ?>><?= e($s->domain()) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="create-foot">
            <label class="check"><input type="checkbox" name="trust" value="1" checked> Trust on this computer <span class="muted">(no browser warning)</span></label>
            <span class="muted small">Covers the domain, *.domain and localhost · valid <?= \NginxManager\Support\Platform::isWindows() ? '10 years' : '825 days (the macOS maximum)' ?></span>
            <span class="spacer"></span>
            <button type="submit" class="btn" <?= $openssl ? '' : 'disabled' ?>>Create Certificate</button>
        </div>
    </form>
</section>

<?php if ($certs): ?>
<div class="list-toolbar">
    <input type="search" placeholder="Search certificates..." data-list-search="#cert-cards" aria-label="Search certificates">
</div>
<div class="cert-cards" id="cert-cards" data-list data-page-size="12">
    <?php foreach ($certs as $c): /** @var CertInfo $c */ ?>
        <?php
        $f = rawurlencode($c->domain);
        $status = $c->status();
        $state = $status === 'expired' ? 'bad' : ($c->trusted ? 'ok' : 'warn');
        $pct = $c->daysLeft === null ? 0 : max(0, min(100, (int) round($c->daysLeft / 3650 * 100)));
        $left = $c->daysLeft === null ? 'unknown' : ($c->daysLeft < 0 ? 'expired' : ($c->daysLeft > 365 ? intdiv($c->daysLeft, 365) . ' years left' : $c->daysLeft . ' days left'));
        ?>
        <article class="cert-card is-<?= $state ?>" data-filter-text="<?= e($c->domain . ' ' . implode(' ', $c->sans) . ' ' . implode(' ', $c->usedBy)) ?>">
            <header class="cert-head">
                <span class="cert-icon"><?= $lock ?></span>
                <div class="cert-title">
                    <strong><?= e($c->commonName) ?></strong>
                    <span class="small <?= $c->trusted ? 'text-ok' : 'text-warn' ?>"><?= $c->trusted ? 'Trusted by ' . \NginxManager\Support\Platform::label() : 'Not trusted - browsers will warn' ?></span>
                </div>
                <?php if ($c->domain === 'localhost'): ?><span class="tag tag-info" title="Used when a site has no certificate of its own">default</span><?php endif; ?>
            </header>

            <div class="chips">
                <?php foreach ($c->sans as $san): ?><span class="chip mono"><?= e($san) ?></span><?php endforeach; ?>
            </div>

            <div class="cert-expiry">
                <div class="row small"><span>Expires <?= $c->notAfter ? e(date('M j, Y', $c->notAfter)) : '—' ?></span><span class="spacer"></span>
                    <strong class="<?= $status === 'ok' ? 'text-ok' : ($status === 'soon' ? 'text-warn' : 'text-bad') ?>"><?= e($left) ?></strong></div>
                <div class="meter"><span style="width: <?= $pct ?>%" class="<?= $status ?>"></span></div>
            </div>

            <div class="cert-used small">
                <span class="muted">Used by</span>
                <?php foreach ($c->usedBy as $siteId): ?>
                    <a class="chip" href="/sites/<?= rawurlencode($siteId) ?>"><?= e($siteId) ?></a>
                <?php endforeach; ?>
                <?php if (!$c->usedBy): ?><span class="muted">no site</span><?php endif; ?>
            </div>

            <footer class="cert-actions">
                <form method="post" action="/ssl/<?= $f ?>/<?= $c->trusted ? 'untrust' : 'trust' ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm"><?= $c->trusted ? 'Untrust' : 'Trust' ?></button>
                </form>
                <form method="post" action="/ssl/generate" data-confirm="Click again to renew">
                    <?= csrf_field() ?>
                    <input type="hidden" name="domain" value="<?= e($c->commonName) ?>">
                    <input type="hidden" name="sans" value="<?= e(implode(', ', \NginxManager\Ssl\CertificateService::extraSans($c->commonName, $c->sans))) ?>">
                    <input type="hidden" name="trust" value="<?= $c->trusted ? '1' : '0' ?>">
                    <button type="submit" class="btn btn-ghost btn-sm">Renew</button>
                </form>
                <span class="spacer"></span>
                <?php if ($c->domain !== 'localhost' && !$c->usedBy): ?>
                    <form method="post" action="/ssl/<?= $f ?>/delete" data-confirm="Click again to delete">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                    </form>
                <?php endif; ?>
            </footer>
        </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>
