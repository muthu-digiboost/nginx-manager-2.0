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
/* View: jobs/show | Live progress of a background job (PHP start/stop/install, extension install).
   Data: $job (data/jobs/<id>.json), $back (same-origin path to return to when done)
   Notes: polls /jobs/<id>/log every 1.5 s. If the manager itself restarts (its own PHP was
          restarted or updated) requests fail for a moment - the page keeps retrying for 90 s. */
?>
<section class="card" id="job" data-id="<?= e($job['id']) ?>" data-back="<?= e($back) ?>">
    <h2 class="card-title">
        <?= e($job['title'] ?? 'Job') ?>
        <span class="actions"><span class="job-state" id="job-state"><span class="spinner"></span> <?= e($job['state'] ?? '') ?></span></span>
    </h2>
    <pre class="code dark job-log" id="job-log"></pre>
    <p class="muted small" id="job-note"></p>
    <div class="card-footer row">
        <a class="btn btn-ghost" href="<?= e($back) ?>">Back</a>
        <span class="muted small">Job <?= e($job['id']) ?></span>
    </div>
</section>
<script>
(function () {
    'use strict';
    var box = document.getElementById('job');
    var logEl = document.getElementById('job-log');
    var stateEl = document.getElementById('job-state');
    var noteEl = document.getElementById('job-note');
    var offset = 0, failures = 0;

    function setState(state, message) {
        stateEl.textContent = '';
        if (state === 'queued' || state === 'running') {
            var s = document.createElement('span'); s.className = 'spinner'; stateEl.appendChild(s);
        }
        var tag = document.createElement('span');
        tag.className = 'tag ' + (state === 'done' ? 'tag-ok' : state === 'failed' ? 'tag-bad' : 'tag-info');
        tag.textContent = state;
        stateEl.appendChild(tag);
        if (message) { noteEl.textContent = message; }
    }

    function poll() {
        fetch('/jobs/' + encodeURIComponent(box.dataset.id) + '/log?offset=' + offset, { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
            .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
            .then(function (d) {
                failures = 0;
                if (d.text) { logEl.textContent += d.text; logEl.scrollTop = logEl.scrollHeight; }
                offset = d.offset;
                setState(d.state, d.message);
                if (d.state === 'done') {
                    noteEl.textContent = (d.message || 'Done') + ' - returning in 2 seconds...';
                    setTimeout(function () { location.href = box.dataset.back; }, 2000);
                } else if (d.state !== 'failed') {
                    setTimeout(poll, 1500);
                }
            })
            .catch(function () {
                failures++;
                noteEl.textContent = 'Manager is restarting... reconnecting (' + failures + ')';
                if (failures < 60) { setTimeout(poll, 1500); }
                else { noteEl.textContent = 'Lost contact with the manager. Reload this page to check the job.'; }
            });
    }
    poll();
})();
</script>
