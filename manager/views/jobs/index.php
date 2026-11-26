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

/* View: jobs/index | Every background job, newest first, with search and pagination.
   Data: $jobs (JobRunner::recent()) */
?>
<section class="card">
    <h2 class="card-title">Background jobs <span class="muted"><?= count($jobs) ?></span></h2>
    <div class="list-toolbar">
        <input type="search" placeholder="Search activity..." data-list-search="#jobs-list" aria-label="Search activity">
    </div>
    <?= \NginxManager\Support\View::partial('partials/jobs-list', ['jobs' => $jobs, 'listId' => 'jobs-list']) ?>
</section>
