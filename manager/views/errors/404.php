<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : 404.php
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
<?php /* View: errors/404 | Unknown URL or unknown item (site, group, job ...). Data: $title, optional $message */ ?>
<div class="card empty">
    <h2>Not found</h2>
    <p class="muted"><?= e($message ?? 'The page or item you asked for does not exist.') ?></p>
    <p><a class="btn" href="/">Back to the dashboard</a></p>
</div>
