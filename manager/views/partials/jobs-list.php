<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : jobs-list.php
 *  Date    : 26/11/2026
 *  Author  : Semansys Development Team
 *
 *  Copyright (c) 2026
 *  Tel: +91 97863 37914
 *  Email: dev.digiboost.muthu@gmail.com
 *  All rights reserved, see the LICENSE file for more information.
 *
 ******************************************************************************/

/* View: partials/jobs-list | Timeline of background jobs (PHP start/stop/install/update, ...).
   Used by the dashboard Activity card (latest few) and the Jobs page (all, searchable, paginated).
   Data: $jobs (list of job arrays: id, title, state, message, createdAt, updatedAt), $listId (optional, enables paging) */
$listId = $listId ?? null;
$ago = static function (int $ts): string {
    $d = time() - $ts;
    return match (true) {
        $d < 60    => 'just now',
        $d < 3600  => intdiv($d, 60) . ' min ago',
        $d < 86400 => intdiv($d, 3600) . ' h ago',
        default    => date('M j, H:i', $ts),
    };
};
$icons = [
    'done'    => '<path d="M5 12l5 5 9-10"/>',
    'failed'  => '<path d="M6 6l12 12M18 6L6 18"/>',
    'running' => '',
    'queued'  => '',
];
?>
<?php if (!$jobs): ?>
    <p class="muted" style="margin: 4px 0">No activity yet.</p>
<?php else: ?>
    <ul class="timeline" <?= $listId ? 'id="' . e($listId) . '" data-list data-page-size="15"' : '' ?>>
        <?php foreach ($jobs as $job): ?>
            <?php
            $state = (string) ($job['state'] ?? 'queued');
            $created = strtotime((string) ($job['createdAt'] ?? '')) ?: time();
            $updated = strtotime((string) ($job['updatedAt'] ?? '')) ?: $created;
            $secs = max(0, $updated - $created);
            $duration = in_array($state, ['done', 'failed'], true) ? ($secs < 60 ? "{$secs}s" : intdiv($secs, 60) . 'm ' . ($secs % 60) . 's') : '';
            ?>
            <li class="timeline-item is-<?= e($state) ?>" data-filter-text="<?= e(($job['title'] ?? '') . ' ' . $state . ' ' . ($job['message'] ?? '')) ?>">
                <span class="timeline-icon" title="<?= e($state) ?>">
                    <?php if (in_array($state, ['running', 'queued'], true)): ?>
                        <span class="spinner"></span>
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons[$state] ?? $icons['done'] ?></svg>
                    <?php endif; ?>
                </span>
                <div class="timeline-body">
                    <a class="timeline-title" href="/jobs/<?= e($job['id']) ?>"><?= e($job['title'] ?? $job['id']) ?></a>
                    <?php if ($state === 'failed' && !empty($job['message'])): ?>
                        <div class="timeline-error" title="<?= e($job['message']) ?>"><?= e($job['message']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="timeline-meta">
                    <time datetime="<?= e(date('c', $created)) ?>" title="<?= e(date('M j, Y H:i:s', $created)) ?>"><?= e($ago($created)) ?></time>
                    <?php if ($duration): ?><span><?= e($duration) ?></span><?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
