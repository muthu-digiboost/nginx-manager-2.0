/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : scripts.js
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
 * File: desktop/lib/scripts.js
 * Purpose: Runs the install's own server scripts for the tray actions.
 * Responsibilities:
 *   - commandFor(platform, action, base): start | stop | restart | reload ->
 *       Windows: powershell.exe ... -File <base>\start-all.ps1 -NoPause (the scripts ask
 *                for Administrator themselves - one UAC prompt - and relaunch elevated)
 *       macOS:   /bin/bash <base>/start-all.sh
 *   - run(cmd): spawn without a shell; resolves {code, output} (never rejects)
 * Used by: main.js
 * Notes: on Windows an elevated relaunch returns at once, so callers wait for the result
 *        through /health (see health.js), not through the exit code alone.
 */

'use strict';

const { spawn } = require('child_process');
const path = require('path');

const SCRIPTS = {
    start:   { win32: 'start-all.ps1',    other: 'start-all.sh' },
    stop:    { win32: 'stop-all.ps1',     other: 'stop-all.sh' },
    restart: { win32: 'restart-all.ps1',  other: 'restart-all.sh' },
    reload:  { win32: 'reload-nginx.ps1', other: 'reload-nginx.sh' },
};

function commandFor(platform, action, base) {
    const entry = SCRIPTS[action];
    if (!entry) throw new Error(`Unknown action: ${action}`);
    if (platform === 'win32') {
        return {
            file: 'powershell.exe',
            args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', path.win32.join(base, entry.win32), '-NoPause', '-Hidden'],   // -Hidden: no PowerShell window
        };
    }
    return { file: '/bin/bash', args: [path.posix.join(base, entry.other)] };
}

/** @returns {Promise<{code: number, output: string}>} */
function run({ file, args }, { cwd, timeoutMs = 300000 } = {}) {
    return new Promise((resolve) => {
        let output = '';
        let child;
        try {
            child = spawn(file, args, { cwd, shell: false, windowsHide: true });
        } catch (e) {
            resolve({ code: -1, output: String(e.message || e) });
            return;
        }
        const timer = setTimeout(() => { child.kill(); }, timeoutMs);
        child.stdout.on('data', (d) => { output += d; });
        child.stderr.on('data', (d) => { output += d; });
        child.on('error', (e) => { clearTimeout(timer); resolve({ code: -1, output: output + String(e.message || e) }); });
        child.on('close', (code) => { clearTimeout(timer); resolve({ code: code === null ? -1 : code, output }); });
    });
}

module.exports = { commandFor, run, ACTIONS: Object.keys(SCRIPTS) };
