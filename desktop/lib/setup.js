/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : setup.js
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
 * File: desktop/lib/setup.js
 * Purpose: Sets up Nginx Manager from the files bundled in the installer (scripts, manager,
 *          configs; on Windows also nginx.exe and one PHP), and refreshes the code when the
 *          app is updated - without ever touching the user's data.
 * Responsibilities:
 *   - isProtected(rel): user data that is never overwritten: .env, data/, conf/sites/
 *     (except the template), conf/generated/, conf/manager/, ssl/<domain>/, logs/,
 *     backups/, temp/
 *   - apply(bundleDir, dest, version): copy new files, refresh code files, skip protected
 *     files that exist, never touch an existing php/<version>/ folder; records the version
 *     in data/.nm-app-version
 *   - needsUpdate(dest, version): the folder was set up by the app and the app is newer
 *   - defaultFolder(platform, home): C:\NginxManager / ~/NginxManager
 * Used by: main.js (first run on a new machine, and after app updates)
 */

'use strict';

const fs = require('fs');
const path = require('path');

const MARKER = ['data', '.nm-app-version'];

function isProtected(rel) {
    const r = rel.replace(/\\/g, '/');
    if (r === '.env') return true;
    if (r === 'conf/sites/_template.conf.example' || r === 'conf/sites/.gitkeep') return false;
    if (r === 'ssl/configuration.conf' || r === 'ssl/.gitkeep') return false;
    return /^(data|conf\/sites|conf\/generated|conf\/manager|ssl|logs|backups|temp)\//.test(r);
}

/** @returns {string[]} files below dir, as "a/b/c" relative paths */
function listFiles(dir, prefix = '') {
    const out = [];
    for (const entry of fs.readdirSync(path.join(dir, prefix), { withFileTypes: true })) {
        const rel = prefix ? prefix + '/' + entry.name : entry.name;
        if (entry.isDirectory()) out.push(...listFiles(dir, rel));
        else out.push(rel);
    }
    return out;
}

/** @returns {{copied: number, skipped: number}} */
function apply(bundleDir, dest, version) {
    let copied = 0;
    let skipped = 0;
    const phpFolderExisted = {};   // php/<version> already there before this run -> left alone
    for (const rel of listFiles(bundleDir)) {
        const target = path.join(dest, ...rel.split('/'));
        const php = rel.match(/^php\/([^/]+)\//);
        if (php) {
            if (!(php[1] in phpFolderExisted)) phpFolderExisted[php[1]] = fs.existsSync(path.join(dest, 'php', php[1]));
            if (phpFolderExisted[php[1]]) { skipped++; continue; }
        }
        if (isProtected(rel) && fs.existsSync(target)) { skipped++; continue; }
        fs.mkdirSync(path.dirname(target), { recursive: true });
        fs.copyFileSync(path.join(bundleDir, ...rel.split('/')), target);
        copied++;
    }
    fs.mkdirSync(path.join(dest, MARKER[0]), { recursive: true });
    fs.writeFileSync(path.join(dest, ...MARKER), version + '\n');
    return { copied, skipped };
}

function installedVersion(dest) {
    try {
        return fs.readFileSync(path.join(dest, ...MARKER), 'utf8').trim();
    } catch (e) {
        return null;
    }
}

function compare(a, b) {
    const pa = String(a).split('.').map(Number);
    const pb = String(b).split('.').map(Number);
    for (let i = 0; i < 3; i++) {
        if ((pa[i] || 0) !== (pb[i] || 0)) return (pa[i] || 0) - (pb[i] || 0);
    }
    return 0;
}

function needsUpdate(dest, version) {
    const v = installedVersion(dest);
    return v !== null && compare(version, v) > 0;
}

function defaultFolder(platform, home) {
    return platform === 'win32' ? 'C:\\NginxManager' : path.posix.join(home, 'NginxManager');
}

module.exports = { isProtected, listFiles, apply, installedVersion, needsUpdate, defaultFolder };
