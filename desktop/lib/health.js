/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : health.js
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
 * File: desktop/lib/health.js
 * Purpose: Is the manager up? Where is it?
 * Responsibilities:
 *   - managerUrl(base): http://MANAGER_HOST:MANAGER_PORT from <base>/.env (default
 *     127.0.0.1:8080), the same values nginx's manager.conf is generated from
 *   - check(url): GET <url>/health -> {ok, php} or null (down, 502 from nginx, timeout)
 *   - waitFor(url, wantUp, timeoutMs): polls until up (or down); the scripts relaunch
 *     themselves elevated on Windows, so their exit code says nothing about the result
 *   - isManagerUrl(url, origin): may this URL load inside the app window?
 * Used by: main.js
 */

'use strict';

const fs = require('fs');
const http = require('http');
const path = require('path');

function readEnv(base) {
    const out = {};
    let text = '';
    try {
        text = fs.readFileSync(path.join(base, '.env'), 'utf8');
    } catch (e) {
        return out;
    }
    for (const line of text.split(/\r?\n/)) {
        const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/);
        if (m) out[m[1]] = m[2].replace(/^(['"])(.*)\1$/, '$2');
    }
    return out;
}

function managerUrl(base) {
    const env = readEnv(base);
    const host = env.MANAGER_HOST || '127.0.0.1';
    const port = /^\d+$/.test(env.MANAGER_PORT || '') ? env.MANAGER_PORT : '8080';
    return `http://${host}:${port}`;
}

/** @returns {Promise<{ok: boolean, php: string}|null>} */
function check(url, timeoutMs = 3000) {
    return new Promise((resolve) => {
        const req = http.get(url + '/health', { timeout: timeoutMs }, (res) => {
            let body = '';
            res.setEncoding('utf8');
            res.on('data', (d) => { body += d; });
            res.on('end', () => {
                try {
                    const json = JSON.parse(body);
                    if (res.statusCode !== 200 || !json || !json.ok) { resolve(null); return; }
                    const out = { ok: true, php: String(json.php || '') };
                    if (json.base) out.base = String(json.base);   // the install folder (newer managers)
                    resolve(out);
                } catch (e) {
                    resolve(null);
                }
            });
        });
        req.on('timeout', () => req.destroy());
        req.on('error', () => resolve(null));
    });
}

async function waitFor(url, wantUp, timeoutMs, intervalMs = 1000) {
    const deadline = Date.now() + timeoutMs;
    for (;;) {
        const up = (await check(url, Math.min(3000, intervalMs * 3))) !== null;
        if (up === wantUp) return true;
        if (Date.now() + intervalMs > deadline) return false;
        await new Promise((r) => setTimeout(r, intervalMs));
    }
}

function isManagerUrl(url, origin) {
    try {
        return new URL(url).origin === new URL(origin).origin;
    } catch (e) {
        return false;
    }
}

module.exports = { managerUrl, check, waitFor, isManagerUrl, readEnv };
