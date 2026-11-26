/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : errors.js
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
 * File: desktop/lib/errors.js
 * Purpose: Unexpected errors in the app's main process.
 * Responsibilities:
 *   - isAppReplaced(err, appPath): ENOENT for the app's own install folder (or a file in it)
 *     = an upgrade is replacing the app right now -> quit quietly; the new version starts
 *     fine afterwards
 *   - log(file, err): timestamped line(s) in <userData>/logs/main.log instead of Electron's
 *     raw "A JavaScript error occurred in the main process" dialog
 * Used by: main.js (process.on('uncaughtException' / 'unhandledRejection'))
 */

'use strict';

const fs = require('fs');
const path = require('path');

function norm(p) {
    return String(p || '').replace(/\\/g, '/').replace(/\/+$/, '').toLowerCase();
}

/** The folder the app is installed in: the parent of ".../resources/app.asar". */
function installDir(appPath) {
    const p = norm(appPath);
    const i = p.lastIndexOf('/resources/');
    return i >= 0 ? p.slice(0, i) : p;
}

function isAppReplaced(err, appPath) {
    if (!err || err.code !== 'ENOENT' || !err.path) return false;
    const missing = norm(err.path);
    const dir = installDir(appPath);
    // The install folder itself, a folder above it, or anything inside it has disappeared.
    return missing === dir || dir.startsWith(missing + '/') || missing.startsWith(dir + '/');
}

function log(file, err) {
    const stamp = new Date().toISOString().replace('T', ' ').slice(0, 19);
    const text = err instanceof Error ? (err.stack || `${err.name}: ${err.message}`) : String(err);
    try {
        fs.mkdirSync(path.dirname(file), { recursive: true });
        fs.appendFileSync(file, `[${stamp}] ${text}\n`);
    } catch (e) {
        // nowhere to log - nothing else to do
    }
}

module.exports = { isAppReplaced, installDir, log };
