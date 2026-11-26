/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : settings.js
 *  Date    : 26/11/2026
 *  Author  : Semansys Development Team
 *
 *  Copyright (c) 2026
 *  Tel: +91 97863 37914
 *  Email: dev.digiboost.muthu@gmail.com
 *  All rights reserved, see the LICENSE file for more information.
 *
 ******************************************************************************/

/**
 * File: desktop/assets/settings.js
 * Purpose: Behaviour of the app's Settings window (settings.html). Uses only window.nm
 *          (preload.js); shows update status pushed by the main process.
 */

'use strict';

(function () {
    const $ = (id) => document.getElementById(id);
    const status = $('status');
    const install = $('install');
    const check = $('check');
    const progress = $('progress');

    function show(s) {
        status.textContent = s.text || '';
        status.className = s.state === 'error' ? 'error' : (s.state === 'ready' || s.state === 'none' ? 'ok' : 'muted');
        progress.style.display = s.state === 'downloading' ? 'block' : 'none';
        progress.value = s.percent || 0;
        check.disabled = s.state === 'checking' || s.state === 'downloading';
        install.hidden = !(s.state === 'available' || s.state === 'ready');
        install.textContent = s.state === 'ready' ? 'Restart and update'
            : (s.mode === 'download' ? 'Open download page' : 'Update now');
        install.disabled = false;
    }

    window.nm.onStatus(show);

    window.nm.info().then((info) => {
        $('version').textContent = 'Version ' + info.version;
        $('base').textContent = info.base || '(not set)';
        $('login').checked = !!info.startAtLogin;
        $('login').disabled = !info.isPackaged;
        if (info.status) show(info.status);
    });

    check.addEventListener('click', () => window.nm.checkForUpdates());
    install.addEventListener('click', () => { install.disabled = true; window.nm.installUpdate(); });
    $('folder').addEventListener('click', () => window.nm.changeFolder());
    $('login').addEventListener('change', (e) => window.nm.setStartAtLogin(e.target.checked));
})();
