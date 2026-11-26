/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : config.test.js
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
 * File: desktop/test/config.test.js
 * Purpose: Finding the Nginx Manager install the app controls, and the app's own settings file.
 */

'use strict';

const assert = require('assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const config = require('../lib/config');

function tmp() {
    return fs.mkdtempSync(path.join(os.tmpdir(), 'nm-desktop-'));
}

function fakeInstall(platform) {
    const dir = tmp();
    fs.mkdirSync(path.join(dir, 'manager', 'bin'), { recursive: true });
    fs.writeFileSync(path.join(dir, 'manager', 'bin', 'manager.php'), '<?php');
    fs.writeFileSync(path.join(dir, platform === 'win32' ? 'start-all.ps1' : 'start-all.sh'), '');
    return dir;
}

test('an install folder has manager/bin/manager.php and the start script of this system', () => {
    assert.strictEqual(config.isManagerFolder(fakeInstall('win32'), 'win32'), true);
    assert.strictEqual(config.isManagerFolder(fakeInstall('darwin'), 'darwin'), true);
    assert.strictEqual(config.isManagerFolder(fakeInstall('darwin'), 'win32'), false);   // no start-all.ps1
    assert.strictEqual(config.isManagerFolder(tmp(), 'win32'), false);
    assert.strictEqual(config.isManagerFolder('', 'win32'), false);
});

test('run from the repo: the parent of desktop/ is the install', () => {
    const base = fakeInstall(process.platform);
    const appDir = path.join(base, 'desktop');
    assert.strictEqual(config.resolveBase({ isPackaged: false, appDir, stored: '', platform: process.platform }), base);
});

test('installed: the remembered folder, if it is still an install; otherwise none', () => {
    const base = fakeInstall(process.platform);
    assert.strictEqual(config.resolveBase({ isPackaged: true, appDir: tmp(), stored: base, platform: process.platform }), base);
    assert.strictEqual(config.resolveBase({ isPackaged: true, appDir: tmp(), stored: tmp(), platform: process.platform }), null);
    assert.strictEqual(config.resolveBase({ isPackaged: true, appDir: tmp(), stored: '', platform: process.platform }), null);
});

test('settings are kept in a JSON file; a missing or broken file gives the defaults', () => {
    const file = path.join(tmp(), 'settings.json');
    assert.deepStrictEqual(config.load(file), config.DEFAULTS);
    config.save(file, { base: '/x', notifiedTray: true });
    const s = config.load(file);
    assert.strictEqual(s.base, '/x');
    assert.strictEqual(s.notifiedTray, true);
    assert.strictEqual(s.startAtLogin, false);                 // default kept
    fs.writeFileSync(file, '{ broken');
    assert.deepStrictEqual(config.load(file), config.DEFAULTS);
});

test('the install a running manager reports is used when it is a real install folder', () => {
    const base = fakeInstall(process.platform);
    assert.strictEqual(config.fromHealth({ ok: true, php: '8.5', base }, process.platform), base);
    assert.strictEqual(config.fromHealth({ ok: true, php: '8.5', base: tmp() }, process.platform), null);   // not an install
    assert.strictEqual(config.fromHealth({ ok: true, php: '8.5' }, process.platform), null);                // older manager
    assert.strictEqual(config.fromHealth(null, process.platform), null);                                    // not running
});
