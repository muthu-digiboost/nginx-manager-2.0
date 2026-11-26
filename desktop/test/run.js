/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : run.js
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
 * File: desktop/test/run.js
 * Purpose: Minimal test runner for the desktop app's plain-Node modules (desktop/lib).
 *          Works on Node 16+ (node:test needs 18). Usage: npm test [filter]
 * Each *.test.js file calls test(name, fn); fn may be async. Exit code 1 on any failure.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const tests = [];
global.test = (name, fn) => tests.push({ name, fn, file: global.__currentFile });

const filter = process.argv[2] || '';
for (const file of fs.readdirSync(__dirname).filter((f) => f.endsWith('.test.js')).sort()) {
    if (filter && !file.includes(filter)) continue;
    global.__currentFile = file;
    require(path.join(__dirname, file));
}

(async () => {
    let passed = 0;
    let failed = 0;
    for (const t of tests) {
        try {
            await t.fn();
            passed++;
            console.log(`PASS  ${t.file} :: ${t.name}`);
        } catch (e) {
            failed++;
            console.log(`FAIL  ${t.file} :: ${t.name}`);
            console.log('      ' + String(e && e.stack ? e.stack : e).split('\n').slice(0, 4).join('\n      '));
        }
    }
    console.log(`\n${failed ? 'FAILED' : 'OK'}: ${passed} passed, ${failed} failed`);
    process.exit(failed ? 1 : 0);
})();
