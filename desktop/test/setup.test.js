/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : setup.test.js
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
 * File: desktop/test/setup.test.js
 * Purpose: Setting up Nginx Manager from the files bundled in the installer (a new machine
 *          has no scripts, nginx or PHP), and refreshing the code on app updates without
 *          ever touching the user's data.
 */

'use strict';

const assert = require('assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const setup = require('../lib/setup');

function tmp() {
    return fs.mkdtempSync(path.join(os.tmpdir(), 'nm-setup-'));
}

function write(root, rel, text) {
    const f = path.join(root, ...rel.split('/'));
    fs.mkdirSync(path.dirname(f), { recursive: true });
    fs.writeFileSync(f, text);
}

function read(root, rel) {
    return fs.readFileSync(path.join(root, ...rel.split('/')), 'utf8');
}

function bundle() {
    const b = tmp();
    write(b, 'start-all.ps1', 'new start');
    write(b, 'manager/bin/manager.php', '<?php // new');
    write(b, 'conf/nginx.conf', 'new nginx.conf');
    write(b, 'conf/sites/_template.conf.example', 'template');
    write(b, 'ssl/configuration.conf', 'new openssl template');
    write(b, '.env.example', 'PROJECTS_DIR=');
    write(b, 'nginx.exe', 'NGINX');
    write(b, 'php/8.4/php.exe', 'PHP84');
    write(b, 'php/8.4/php.ini', 'bundled ini');
    return b;
}

test('protected paths: user data is never overwritten', () => {
    for (const rel of ['.env', 'data/settings.json', 'conf/sites/a.test.conf', 'conf/generated/env.conf',
        'conf/manager/manager.conf', 'ssl/a.test/ssl.crt/server.crt', 'logs/error.log', 'backups/x', 'temp/y']) {
        assert.strictEqual(setup.isProtected(rel), true, rel);
    }
    for (const rel of ['start-all.ps1', 'manager/src/App.php', 'conf/nginx.conf', 'ssl/configuration.conf',
        'conf/sites/_template.conf.example', 'nginx.exe', '.env.example']) {
        assert.strictEqual(setup.isProtected(rel), false, rel);
    }
});

test('fresh setup copies everything into an empty folder', () => {
    const b = bundle();
    const dest = path.join(tmp(), 'NginxManager');
    const r = setup.apply(b, dest, '2.0.1');
    assert.strictEqual(read(dest, 'start-all.ps1'), 'new start');
    assert.strictEqual(read(dest, 'php/8.4/php.ini'), 'bundled ini');
    assert.strictEqual(read(dest, 'nginx.exe'), 'NGINX');
    assert.strictEqual(setup.installedVersion(dest), '2.0.1');
    assert.ok(r.copied > 0);
});

test('an update refreshes code but keeps data, sites, certificates and existing PHP folders', () => {
    const b = bundle();
    const dest = tmp();
    write(dest, 'start-all.ps1', 'old start');
    write(dest, 'manager/bin/manager.php', '<?php // old');
    write(dest, '.env', 'PROJECTS_DIR=D:/work');
    write(dest, 'data/settings.json', '{"mine":true}');
    write(dest, 'conf/sites/shop.test.conf', 'my site');
    write(dest, 'ssl/shop.test/ssl.crt/server.crt', 'my cert');
    write(dest, 'php/8.4/php.ini', 'my tuned php.ini');
    write(dest, 'php/8.4/ext/php_redis.dll', 'my extension');
    setup.apply(b, dest, '2.0.2');
    assert.strictEqual(read(dest, 'start-all.ps1'), 'new start');                 // code refreshed
    assert.strictEqual(read(dest, 'manager/bin/manager.php'), '<?php // new');
    assert.strictEqual(read(dest, 'ssl/configuration.conf'), 'new openssl template');
    assert.strictEqual(read(dest, '.env'), 'PROJECTS_DIR=D:/work');               // data kept
    assert.strictEqual(read(dest, 'data/settings.json'), '{"mine":true}');
    assert.strictEqual(read(dest, 'conf/sites/shop.test.conf'), 'my site');
    assert.strictEqual(read(dest, 'ssl/shop.test/ssl.crt/server.crt'), 'my cert');
    assert.strictEqual(read(dest, 'php/8.4/php.ini'), 'my tuned php.ini');        // existing PHP untouched
    assert.strictEqual(read(dest, 'php/8.4/ext/php_redis.dll'), 'my extension');
    assert.strictEqual(setup.installedVersion(dest), '2.0.2');
});

test('needsUpdate: only when the app is newer than what set the folder up', () => {
    const dest = tmp();
    assert.strictEqual(setup.needsUpdate(dest, '2.0.0'), false);    // not set up by the app: never touched
    setup.apply(bundle(), dest, '2.0.0');
    assert.strictEqual(setup.needsUpdate(dest, '2.0.0'), false);
    assert.strictEqual(setup.needsUpdate(dest, '2.1.0'), true);
    assert.strictEqual(setup.needsUpdate(dest, '1.9.0'), false);    // never downgrade
});

test('default folder: C:\\NginxManager on Windows, ~/NginxManager on macOS', () => {
    assert.strictEqual(setup.defaultFolder('win32', 'C:\\Users\\me'), 'C:\\NginxManager');
    assert.strictEqual(setup.defaultFolder('darwin', '/Users/me'), '/Users/me/NginxManager');
});

test('Windows: the folder the app set up is recorded for the uninstaller (HKCU, no admin needed)', () => {
    assert.deepStrictEqual(setup.rememberCommand('win32', 'C:\\NginxManager'), {
        file: 'reg.exe',
        args: ['add', 'HKCU\\Software\\Nginx Manager', '/v', 'SetupFolder', '/t', 'REG_SZ', '/d', 'C:\\NginxManager', '/f'],
    });
    assert.strictEqual(setup.rememberCommand('darwin', '/Users/me/NginxManager'), null);   // no uninstaller on macOS
});
