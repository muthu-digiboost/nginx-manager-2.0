<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : flash.php
 *  Date    : 26/11/2026
 *  Author  : Semansys Development Team
 *
 *  Copyright (c) 2026
 *  Tel: +91 97863 37914
 *  Email: dev.digiboost.muthu@gmail.com
 *  All rights reserved, see the LICENSE file for more information.
 *
 ******************************************************************************/
?>
<?php /* View: partials/flash | One-time messages from the previous action.
   Data: none (pulls NginxManager\Http\Flash). Each message: type, message, optional details (preformatted). */
$messages = \NginxManager\Http\Flash::pull();
if (!$messages) {
    return;
}
?>
<div class="flash-stack">
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= e($m['type']) ?>" role="status">
            <div><?= e($m['message']) ?></div>
            <?php if (($m['details'] ?? '') !== ''): ?>
                <details <?= $m['type'] === 'error' ? 'open' : '' ?>>
                    <summary>Details</summary>
                    <pre class="code"><?= e($m['details']) ?></pre>
                </details>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
