/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : health.test.js
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
 * File: desktop/test/health.test.js
 * Purpose: The manager's address (from .env), its /health answer, waiting for it to come up
 *          or go down, and which links may open inside the app window.
 */

'use strict';

const assert = require('assert');
const fs = require('fs');
const http = require('http');
const os = require('os');
const path = require('path');
const health = require('../lib/health');

function serve(handler) {
    return new Promise((resolve) => {
        const server = http.createServer(handler);
        server.listen(0, '127.0.0.1', () => resolve(server));
    });
}

test('the manager address comes from .env (MANAGER_HOST / MANAGER_PORT), with defaults', () => {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'nm-env-'));
    assert.strictEqual(health.managerUrl(dir), 'http://127.0.0.1:8080');
    fs.writeFileSync(path.join(dir, '.env'), '# c\nMANAGER_HOST=127.0.0.1\nMANAGER_PORT="8181"\nOTHER=x\n');
    assert.strictEqual(health.managerUrl(dir), 'http://127.0.0.1:8181');
});

test('health: {ok, php} when the manager answers, null otherwise', async () => {
    const server = await serve((req, res) => {
        res.setHeader('Content-Type', 'application/json');
        res.end(req.url === '/health' ? '{"ok":true,"php":"8.5.12","base":"C:/NginxManager"}' : 'nope');
    });
    const url = `http://127.0.0.1:${server.address().port}`;
    assert.deepStrictEqual(await health.check(url), { ok: true, php: '8.5.12', base: 'C:/NginxManager' });
    server.close();
    assert.strictEqual(await health.check('http://127.0.0.1:1', 500), null);   // nothing listens
});

test('waitFor: until the manager is up (or down), within a time limit', async () => {
    let up = false;
    const server = await serve((req, res) => {
        res.statusCode = up ? 200 : 502;
        res.end(up ? '{"ok":true,"php":"8.4.1"}' : 'Bad Gateway');
    });
    const url = `http://127.0.0.1:${server.address().port}`;
    setTimeout(() => { up = true; }, 300);
    assert.strictEqual(await health.waitFor(url, true, 5000, 100), true);
    assert.strictEqual(await health.waitFor(url, false, 400, 100), false);    // stays up: gives up
    server.close();
});

test('only the manager itself opens inside the app window', () => {
    const origin = 'http://127.0.0.1:8080';
    assert.strictEqual(health.isManagerUrl('http://127.0.0.1:8080/sites/x', origin), true);
    assert.strictEqual(health.isManagerUrl('http://127.0.0.1:8081/', origin), false);
    assert.strictEqual(health.isManagerUrl('https://shop.example.test/', origin), false);
    assert.strictEqual(health.isManagerUrl('file:///C:/Windows/win.ini', origin), false);
    assert.strictEqual(health.isManagerUrl('not a url', origin), false);
});
