/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : config.js
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
 * File: desktop/lib/config.js
 * Purpose: Which Nginx Manager install the app controls, and the app's own small settings.
 * Responsibilities:
 *   - isManagerFolder(dir, platform): manager/bin/manager.php + the start script of this
 *     system (start-all.ps1 on Windows, start-all.sh on macOS)
 *   - resolveBase(): run from the repo -> the parent of desktop/; installed -> the folder the
 *     user picked once (remembered), or null when it has to be picked (again)
 *   - load()/save(): settings.json in Electron's userData folder - never inside the repo
 * Used by: main.js
 */

'use strict';

const fs = require('fs');
const path = require('path');

const DEFAULTS = Object.freeze({
    base: '',            // the Nginx Manager install folder
    bounds: null,        // window position and size
    notifiedTray: false, // "still running in the tray" shown once
    startAtLogin: false,
});

function isManagerFolder(dir, platform = process.platform) {
    if (!dir) return false;
    const script = platform === 'win32' ? 'start-all.ps1' : 'start-all.sh';
    return fs.existsSync(path.join(dir, 'manager', 'bin', 'manager.php'))
        && fs.existsSync(path.join(dir, script));
}

/**
 * @param {{isPackaged: boolean, appDir: string, stored: string, platform?: string}} o
 * @returns {string|null} the install folder, or null when the user must pick it
 */
function resolveBase({ isPackaged, appDir, stored, platform = process.platform }) {
    if (!isPackaged) {
        const repo = path.resolve(appDir, '..');
        if (isManagerFolder(repo, platform)) return repo;
    }
    return isManagerFolder(stored, platform) ? stored : null;
}

/**
 * The install folder a running manager reports in /health, if it really is one.
 * @param {{base?: string}|null} healthAnswer
 * @returns {string|null}
 */
function fromHealth(healthAnswer, platform = process.platform) {
    const dir = healthAnswer && healthAnswer.base ? path.normalize(healthAnswer.base) : '';
    return dir && isManagerFolder(dir, platform) ? dir : null;
}

function load(file) {
    try {
        const data = JSON.parse(fs.readFileSync(file, 'utf8'));
        return { ...DEFAULTS, ...(data && typeof data === 'object' ? data : {}) };
    } catch (e) {
        return { ...DEFAULTS };
    }
}

/** Merges $changes into the stored settings; written through a temp file (no half files). */
function save(file, changes) {
    const next = { ...load(file), ...changes };
    fs.mkdirSync(path.dirname(file), { recursive: true });
    const tmp = file + '.tmp';
    fs.writeFileSync(tmp, JSON.stringify(next, null, 2));
    fs.renameSync(tmp, file);
    return next;
}

module.exports = { DEFAULTS, isManagerFolder, resolveBase, fromHealth, load, save };
