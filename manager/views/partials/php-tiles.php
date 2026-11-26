<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : php-tiles.php
 *  Date    : 26/11/2026
 *  Author  : Semansys Development Team
 *
 *  Copyright (c) 2026
 *  Tel: +91 97863 37914
 *  Email: dev.digiboost.muthu@gmail.com
 *  All rights reserved, see the LICENSE file for more information.
 *
 ******************************************************************************/

/* View: partials/php-tiles | Dashboard "PHP Services": one tile per installed PHP version
   with status, sites using it, Auto-start switch and Start / Stop / Restart.
   Data: $php (PhpVersionRepository::installed()), $siteCounts (upstream => number of sites) */
$siteCounts = $siteCounts ?? [];
?>
<div class="list-toolbar">
    <input type="search" placeholder="Search PHP versions..." data-list-search="#php-tiles" aria-label="Search PHP versions">
</div>
<div class="php-tiles" id="php-tiles" data-list data-page-size="8">
    <?php foreach ($php as $v): ?>
        <?php
        $sites = (int) ($siteCounts[$v['upstream']] ?? 0);
        $only = '/php/service?only=' . rawurlencode($v['id']);
        $search = implode(' ', ['php', $v['id'], $v['version'], $v['upstream'], $v['port'], $v['threadSafety'],
            $v['running'] ? 'running' : 'stopped', $v['autostart'] ? 'auto-start' : '', $v['isManager'] ? 'manager' : '']);
        ?>
        <form method="post" class="php-tile <?= $v['running'] ? 'is-running' : 'is-stopped' ?>" data-filter-text="<?= e($search) ?>">
            <?= csrf_field() ?>
            <div class="php-tile-head">
                <span class="php-tile-name">PHP <?= e($v['id']) ?></span>
                <?php if ($v['isManager']): ?><span class="tag tag-info" title="This manager runs on this PHP">manager</span><?php endif; ?>
                <span class="php-tile-state"><span class="dot <?= $v['running'] ? 'dot-ok' : 'dot-off' ?>"></span><?= $v['running'] ? 'Running' : 'Stopped' ?></span>
            </div>
            <div class="php-tile-meta">
                <span><?= e($v['version']) ?><?= $v['threadSafety'] !== '' ? ' · ' . e(strtoupper($v['threadSafety'])) : '' ?></span>
                <span class="mono">:<?= (int) $v['port'] ?></span>
                <span><?= $sites ?> site<?= $sites === 1 ? '' : 's' ?></span>
            </div>
            <div class="php-tile-foot">
                <button type="submit" class="switch-btn <?= $v['autostart'] ? 'on' : '' ?>"
                        formaction="/php/<?= rawurlencode($v['id']) ?>/autostart" name="on" value="<?= $v['autostart'] ? '0' : '1' ?>"
                        title="<?= $v['isManager'] ? 'Always on - this manager runs on this PHP' : 'Start automatically whenever the server starts' ?>"
                        <?= $v['isManager'] ? 'disabled' : '' ?>>
                    <span class="track"></span><span class="small">Auto-start</span>
                </button>
                <span class="spacer"></span>
                <?php if ($v['running']): ?>
                    <?php if ($v['isManager']): ?>
                        <button type="submit" class="btn btn-ghost btn-sm" formaction="<?= e($only) ?>" name="action" value="restart">Restart</button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-danger btn-sm" formaction="<?= e($only) ?>" name="action" value="stop">Stop</button>
                    <?php endif; ?>
                <?php else: ?>
                    <button type="submit" class="btn btn-sm" formaction="<?= e($only) ?>" name="action" value="start" <?= $v['exists'] ? '' : 'disabled' ?>>Start</button>
                <?php endif; ?>
            </div>
        </form>
    <?php endforeach; ?>
</div>
