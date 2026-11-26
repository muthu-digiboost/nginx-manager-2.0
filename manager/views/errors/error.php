<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : error.php
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
<?php /* View: errors/error | Expired form (419) or unexpected error (500).
   Data: $title, $message, optional $detail (exception class + location), $code */ ?>
<div class="card empty">
    <h2><?= e($title) ?></h2>
    <p><?= e($message) ?></p>
    <?php if (!empty($detail)): ?><pre class="code"><?= e($detail) ?></pre><?php endif; ?>
    <p class="muted">Full details are in logs/manager-php-error.log.</p>
    <p><a class="btn" href="javascript:history.back()">Go back</a> <a class="btn btn-ghost" href="/">Dashboard</a></p>
</div>
