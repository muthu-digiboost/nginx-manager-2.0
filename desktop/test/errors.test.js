/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : errors.test.js
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
 * File: desktop/test/errors.test.js
 * Purpose: Unexpected errors in the app are logged, and "the app's own files are gone" (an
 *          upgrade replacing them while it starts or runs) quits quietly instead of showing
 *          Electron's raw "A JavaScript error occurred in the main process" dialog.
 */

'use strict';

const assert = require('assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const errors = require('../lib/errors');

const APP_DIR = 'C:\\Users\\me\\AppData\\Local\\Programs\\Nginx Manager';

test('ENOENT for the app\'s own folder means the app is being replaced', () => {
    const e = Object.assign(new Error(`ENOENT: no such file or directory, lstat '${APP_DIR}'`), { code: 'ENOENT', path: APP_DIR });
    assert.strictEqual(errors.isAppReplaced(e, APP_DIR + '\\resources\\app.asar'), true);
    const inside = Object.assign(new Error('ENOENT'), { code: 'ENOENT', path: APP_DIR + '\\resources\\app.asar\\lib\\x.js' });
    assert.strictEqual(errors.isAppReplaced(inside, APP_DIR + '\\resources\\app.asar'), true);
});

test('other errors are not mistaken for an upgrade', () => {
    const elsewhere = Object.assign(new Error('ENOENT'), { code: 'ENOENT', path: 'C:\\NginxManager\\.env' });
    assert.strictEqual(errors.isAppReplaced(elsewhere, APP_DIR + '\\resources\\app.asar'), false);
    assert.strictEqual(errors.isAppReplaced(new TypeError('x is not a function'), APP_DIR), false);
    assert.strictEqual(errors.isAppReplaced(null, APP_DIR), false);
});

test('errors are appended to a log file with a timestamp', () => {
    const file = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'nm-log-')), 'logs', 'main.log');
    errors.log(file, new Error('first'));
    errors.log(file, 'second');
    const text = fs.readFileSync(file, 'utf8');
    assert.match(text, /^\[\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\] Error: first/m);
    assert.match(text, /\] second$/m);
});
