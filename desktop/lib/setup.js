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
 *   - defaultFolder / fallbackFolder / legacyFolders / chooseFolder: where it is set up -
 *     C:\Program Files\Nginx Manager Server (Windows), ~/NginxManager (macOS); never asks
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

function sameContent(a, b) {
    try {
        const sa = fs.statSync(a);
        const sb = fs.statSync(b);
        return sa.size === sb.size && fs.readFileSync(a).equals(fs.readFileSync(b));
    } catch (e) {
        return false;
    }
}

/**
 * Unchanged files are left alone (a running nginx.exe is locked); a file that cannot be
 * replaced is listed in `failed` and never stops the rest.
 * @returns {{copied: number, skipped: number, failed: string[]}}
 */
function apply(bundleDir, dest, version) {
    let copied = 0;
    let skipped = 0;
    const failed = [];
    // PHP is part of the FIRST setup only. After that PHP versions belong to the user (added
    // and removed in the app): an update never touches php/ - it neither overwrites a tuned
    // php.ini nor brings back a version the user removed.
    const firstSetup = installedVersion(dest) === null;
    const phpFolderExisted = {};   // php/<version> already there before this run -> left alone
    for (const rel of listFiles(bundleDir)) {
        const target = path.join(dest, ...rel.split('/'));
        const php = rel.match(/^php\/([^/]+)\//);
        if (php) {
            if (!firstSetup) { skipped++; continue; }
            if (!(php[1] in phpFolderExisted)) phpFolderExisted[php[1]] = fs.existsSync(path.join(dest, 'php', php[1]));
            if (phpFolderExisted[php[1]]) { skipped++; continue; }
        }
        if (isProtected(rel) && fs.existsSync(target)) { skipped++; continue; }
        const source = path.join(bundleDir, ...rel.split('/'));
        if (sameContent(source, target)) { skipped++; continue; }
        fs.mkdirSync(path.dirname(target), { recursive: true });
        try {
            fs.copyFileSync(source, target);
            copied++;
        } catch (e) {
            if (!fs.existsSync(target)) throw e;   // a missing file must be there: real error
            failed.push(rel);                      // in use - the next update tries again
        }
    }
    fs.mkdirSync(path.join(dest, MARKER[0]), { recursive: true });
    fs.writeFileSync(path.join(dest, ...MARKER), version + '\n');
    return { copied, skipped, failed };
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

/**
 * Windows: records the folder the app set up (HKCU\Software\Nginx Manager\SetupFolder), so
 * the uninstaller can offer to delete it - and ONLY that folder (never one the app did not
 * create, like a developer's checkout). macOS has no uninstaller: null.
 */
function rememberCommand(platform, dest) {
    if (platform !== 'win32') return null;
    return { file: 'reg.exe', args: ['add', 'HKCU\\Software\\Nginx Manager', '/v', 'SetupFolder', '/t', 'REG_SZ', '/d', dest, '/f'] };
}

/** Windows: reads the recorded folder back ("reg query"); null elsewhere. */
function recallCommand(platform) {
    if (platform !== 'win32') return null;
    return { file: 'reg.exe', args: ['query', 'HKCU\\Software\\Nginx Manager', '/v', 'SetupFolder'] };
}

/** "    SetupFolder    REG_SZ    C:\NginxManager" -> "C:\NginxManager" (null when missing). */
function parseRegQuery(output) {
    const m = String(output || '').match(/^\s*SetupFolder\s+REG_SZ\s+(.+?)\s*$/m);
    return m ? m[1] : null;
}

/**
 * The first candidate that is an Nginx Manager folder (the recorded one, then the default
 * one) - so a reinstall uses the existing setup instead of asking to set up again.
 */
function findExisting(candidates, platform = process.platform) {
    const { isManagerFolder } = require('./config');
    for (const dir of candidates) {
        if (dir && isManagerFolder(dir, platform)) return dir;
    }
    return null;
}

/**
 * First run: the app sets itself up without a question when the folder is free (missing or
 * empty) or already holds Nginx Manager. A folder with other files is never written into
 * silently - then the app asks.
 */
function canSetUpAutomatically(dir, platform = process.platform) {
    if (!fs.existsSync(dir)) return true;
    try {
        if (fs.readdirSync(dir).length === 0) return true;
    } catch (e) {
        return false;
    }
    const { isManagerFolder } = require('./config');
    return isManagerFolder(dir, platform);
}

/**
 * Windows: "C:\Program Files\Nginx Manager Server" - next to the app, NOT inside its folder
 * (every app update replaces "C:\Program Files\Nginx Manager" completely). The installer
 * creates it and lets the users of the PC write to it (nginx/PHP write logs, sites, certs).
 */
function defaultFolder(platform, home, env = process.env) {
    if (platform !== 'win32') return path.posix.join(home, 'NginxManager');
    return path.win32.join(env.ProgramFiles || 'C:\\Program Files', 'Nginx Manager Server');
}

/** Windows, only when Program Files cannot be written: the user's own app data folder. */
function fallbackFolder(platform, home, env = process.env) {
    if (platform !== 'win32') return path.posix.join(home, 'NginxManager');
    return path.win32.join(env.LOCALAPPDATA || path.win32.join(home, 'AppData', 'Local'), 'Nginx Manager Server');
}

/** Folders older versions set up - found again on start, never set up anew. */
function legacyFolders(platform) {
    return platform === 'win32' ? ['C:\\NginxManager'] : [];
}

function isWritable(dir) {
    try {
        fs.mkdirSync(dir, { recursive: true });
        const probe = path.join(dir, '.nm-write-test');
        fs.writeFileSync(probe, '');
        fs.unlinkSync(probe);
        return true;
    } catch (e) {
        return false;
    }
}

/** The first folder the app may set itself up in without asking (free or its own, writable). */
function chooseFolder(candidates, platform = process.platform) {
    for (const dir of candidates) {
        if (dir && canSetUpAutomatically(dir, platform) && isWritable(dir)) return dir;
    }
    return null;
}

module.exports = { isProtected, listFiles, apply, installedVersion, needsUpdate, rememberCommand, recallCommand, parseRegQuery, findExisting, canSetUpAutomatically, defaultFolder, fallbackFolder, legacyFolders, chooseFolder };
