<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : setup.php
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
<?php /* View: errors/setup | Stand-alone page (no layout) shown while .env does not exist.
   Data: $title, $message */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Nginx Manager - <?= e($title) ?></title>
    <link rel="stylesheet" href="<?= e(asset('/assets/app.css')) ?>">
</head>
<body class="setup">
<div class="card empty">
    <h2>Almost there - create your .env</h2>
    <p><?= e($message) ?></p>
    <ol>
        <li>In the install folder, copy <code>.env.example</code> to <code>.env</code>.</li>
        <li>Set <code>PROJECTS_DIR</code> to the folder that holds your projects.</li>
        <li>Run <code><?= \NginxManager\Support\Platform::isWindows() ? 'start-all.ps1' : './start-all.sh' ?></code> again and reload this page.</li>
    </ol>
</div>
</body>
</html>
