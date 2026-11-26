<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : php-services.php
 *  Date    : 26/11/2026
 *  Author  : Semansys Development Team
 *
 *  Copyright (c) 2026
 *  Tel: +91 97863 37914
 *  Email: dev.digiboost.muthu@gmail.com
 *  All rights reserved, see the LICENSE file for more information.
 *
 ******************************************************************************/
/* View: partials/php-services | Start / stop / restart one or many PHP versions + Auto-start.
   Used on the dashboard (compact) and the PHP Versions page (full).
   Data: $php (list from PhpVersionRepository::installed()), $compact (bool), $siteCounts (upstream => int, optional), $eolIds / $pinnedIds (optional id lists for tags)
   Notes: one form; checkboxes select several versions for the bulk bar, and each row's buttons post
          only their own version through formaction="...?only=<id>" (forms cannot be nested). */
$compact = $compact ?? false;
$siteCounts = $siteCounts ?? [];
$eolIds = $eolIds ?? [];
$pinnedIds = $pinnedIds ?? [];
?>
<form method="post" action="/php/service" id="php-services">
    <?= csrf_field() ?>
    <div class="tablenav">
        <select name="action" aria-label="Bulk action">
            <option value="start">Start</option>
            <option value="stop">Stop</option>
            <option value="restart">Restart</option>
        </select>
        <button type="submit" class="btn btn-ghost btn-sm">Apply to selected</button>
        <span class="spacer"></span>
        <input type="search" placeholder="Search PHP versions..." data-list-search="#php-table" aria-label="Search PHP versions" style="max-width: 260px">
    </div>
    <table class="table <?= $compact ? '' : 'widefat' ?>" id="php-table" data-list data-page-size="10">
        <thead>
        <tr>
            <th class="check"><input type="checkbox" data-check-all="#php-services" aria-label="Select all"></th>
            <th>PHP</th>
            <?php if (!$compact): ?><th>Version</th><th>Port</th><th class="col-upstream">Upstream</th><th>Sites</th><?php endif; ?>
            <th>Status</th>
            <th title="Start automatically whenever the server starts. Versions that are Off stay stopped until you click Start.">Auto-start</th>
            <th title="Show this version with quick actions on the dashboard">Dashboard</th>
            <th class="actions"></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($php as $v): ?>
            <tr data-filter-text="<?= e(implode(' ', ['php', $v['id'], $v['version'], $v['upstream'], $v['port'], $v['threadSafety'], $v['running'] ? 'running' : 'stopped'])) ?>">
                <td class="check"><input type="checkbox" name="ids[]" value="<?= e($v['id']) ?>" aria-label="Select PHP <?= e($v['id']) ?>"></td>
                <td>
                    <span class="row-title">PHP <?= e($v['id']) ?></span>
                    <?php if ($v['isManager']): ?> <span class="tag tag-info" title="This manager runs on this PHP">manager</span><?php endif; ?>
                    <?php if (!$v['exists']): ?> <span class="tag tag-bad">missing</span><?php endif; ?>
                    <?php if (in_array($v['id'], $eolIds, true)): ?> <span class="tag tag-warn" title="php.net no longer releases updates for this branch">end of life</span><?php endif; ?>
                    <?php if (in_array($v['id'], $pinnedIds, true)): ?> <span class="tag" title="Installed as an exact version; it is not updated">fixed version</span><?php endif; ?>
                    <?php if ($compact): ?><div class="muted small"><?= e($v['version']) ?> · :<?= (int) $v['port'] ?></div><?php endif; ?>
                </td>
                <?php if (!$compact): ?>
                    <td><?= e($v['version']) ?> <span class="muted small"><?= e(strtoupper($v['threadSafety'])) ?></span></td>
                    <td class="mono">127.0.0.1:<?= (int) $v['port'] ?></td>
                    <td class="mono col-upstream"><?= e($v['upstream']) ?></td>
                    <td><?= (int) ($siteCounts[$v['upstream']] ?? 0) ?></td>
                <?php endif; ?>
                <td class="nowrap"><span class="dot <?= $v['running'] ? 'dot-ok' : 'dot-off' ?>"></span><?= $v['running'] ? 'Running' : 'Stopped' ?></td>
                <td>
                    <button type="submit" class="switch-btn <?= $v['autostart'] ? 'on' : '' ?>"
                            formaction="/php/<?= rawurlencode($v['id']) ?>/autostart" name="on" value="<?= $v['autostart'] ? '0' : '1' ?>"
                            <?= $v['isManager'] ? 'disabled title="Always on - this manager runs on this PHP"' : '' ?>>
                        <span class="track"></span><span class="small"><?= $v['autostart'] ? 'On' : 'Off' ?></span>
                    </button>
                </td>
                <td>
                    <button type="submit" class="pin-btn <?= $v['dashboard'] ? 'on' : '' ?>"
                            formaction="/php/<?= rawurlencode($v['id']) ?>/dashboard" name="on" value="<?= $v['dashboard'] ? '0' : '1' ?>"
                            title="<?= $v['dashboard'] ? 'Shown on the dashboard - click to hide' : 'Click to show on the dashboard' ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/></svg>
                        <span class="small"><?= $v['dashboard'] ? 'Shown' : 'Hidden' ?></span>
                    </button>
                </td>
                <td class="actions">
                    <?php $only = '/php/service?only=' . rawurlencode($v['id']); ?>
                    <?php if ($v['running']): ?>
                        <button type="submit" class="btn btn-ghost btn-sm" formaction="<?= e($only) ?>" name="action" value="restart">Restart</button>
                        <?php if (!$v['isManager']): ?>
                            <button type="submit" class="btn btn-danger btn-sm" formaction="<?= e($only) ?>" name="action" value="stop">Stop</button>
                        <?php endif; ?>
                    <?php else: ?>
                        <button type="submit" class="btn btn-sm" formaction="<?= e($only) ?>" name="action" value="start" <?= $v['exists'] ? '' : 'disabled' ?>>Start</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</form>
