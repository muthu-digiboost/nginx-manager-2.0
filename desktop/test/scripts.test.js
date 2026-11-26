/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : scripts.test.js
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
 * File: desktop/test/scripts.test.js
 * Purpose: The tray actions run the existing server scripts of each system, as argument
 *          arrays (no shell strings), and report their exit code and output.
 */

'use strict';

const assert = require('assert');
const path = require('path');
const scripts = require('../lib/scripts');

test('Windows: PowerShell scripts with -NoPause (they ask for Administrator themselves)', () => {
    const base = 'Q:\\My Tools\\nginx-manager';
    assert.deepStrictEqual(scripts.commandFor('win32', 'start', base), {
        file: 'powershell.exe',
        args: ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', path.win32.join(base, 'start-all.ps1'), '-NoPause'],
    });
    assert.strictEqual(scripts.commandFor('win32', 'stop', base).args[4], path.win32.join(base, 'stop-all.ps1'));
    assert.strictEqual(scripts.commandFor('win32', 'restart', base).args[4], path.win32.join(base, 'restart-all.ps1'));
    assert.strictEqual(scripts.commandFor('win32', 'reload', base).args[4], path.win32.join(base, 'reload-nginx.ps1'));
});

test('macOS: the bash launchers', () => {
    const base = '/Users/me/nginx manager';
    assert.deepStrictEqual(scripts.commandFor('darwin', 'start', base), { file: '/bin/bash', args: ['/Users/me/nginx manager/start-all.sh'] });
    assert.deepStrictEqual(scripts.commandFor('darwin', 'reload', base).args, ['/Users/me/nginx manager/reload-nginx.sh']);
});

test('unknown actions are refused', () => {
    assert.throws(() => scripts.commandFor('win32', 'format-c', 'Q:\\x'), /Unknown action/);
});

test('run gives the exit code and the output of a command', async () => {
    const ok = await scripts.run({ file: process.execPath, args: ['-e', 'console.log("hello"); process.exit(0)'] });
    assert.strictEqual(ok.code, 0);
    assert.match(ok.output, /hello/);
    const bad = await scripts.run({ file: process.execPath, args: ['-e', 'console.error("boom"); process.exit(3)'] });
    assert.strictEqual(bad.code, 3);
    assert.match(bad.output, /boom/);
    const missing = await scripts.run({ file: 'definitely-not-a-program-nm', args: [] });
    assert.notStrictEqual(missing.code, 0);
});
