/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : updates.js
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
 * File: desktop/lib/updates.js
 * Purpose: The plain-Node part of the app's Update button (main.js drives electron-updater).
 * Responsibilities:
 *   - mode(platform, isPackaged): 'auto' (Windows: download, install, restart),
 *     'download' (macOS: auto-update needs an Apple-signed app - open the release page),
 *     'dev' (run from the project folder: nothing to update)
 *   - isNewer(a, b): numeric version comparison ("v2.0.10" > "2.0.9")
 *   - statusText(status): the line shown in the Settings window
 *   - releasePage(pkg, version): the GitHub release page (from package.json build.publish)
 * Releases come from GitHub (public repository): every push to main publishes one
 * (.github/workflows/desktop.yml). Updates replace the app and refresh the manager's code;
 * data (.env, sites, certificates, PHP versions) is never touched (lib/setup.js).
 */

'use strict';

function mode(platform, isPackaged) {
    if (!isPackaged) return 'dev';
    return platform === 'win32' ? 'auto' : 'download';
}

function parts(v) {
    return String(v || '').replace(/^v/i, '').split('.').map((n) => parseInt(n, 10) || 0);
}

function isNewer(a, b) {
    const pa = parts(a);
    const pb = parts(b);
    for (let i = 0; i < Math.max(pa.length, pb.length, 3); i++) {
        if ((pa[i] || 0) !== (pb[i] || 0)) return (pa[i] || 0) > (pb[i] || 0);
    }
    return false;
}

function statusText(s) {
    switch (s.state) {
        case 'checking': return 'Checking for updates...';
        case 'none': return `You have the latest version (${s.current}).`;
        case 'available': return `Version ${s.version} is available.`;
        case 'downloading': return `Downloading the update... ${Math.round(s.percent || 0)}%`;
        case 'ready': return `Version ${s.version} is ready. Restart to finish - your sites and settings are kept.`;
        case 'error': return `Could not check for updates: ${s.message}`;
        case 'dev': return 'Updates work in the installed app (this copy runs from the project folder).';
        default: return '';
    }
}

function releasePage(pkg, version) {
    const pub = ((pkg && pkg.build && pkg.build.publish) || []).find((p) => p.provider === 'github');
    if (!pub) return null;
    const base = `https://github.com/${pub.owner}/${pub.repo}/releases`;
    return version ? `${base}/tag/v${String(version).replace(/^v/i, '')}` : `${base}/latest`;
}

module.exports = { mode, isNewer, statusText, releasePage };
