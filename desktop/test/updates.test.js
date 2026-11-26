/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : updates.test.js
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
 * File: desktop/test/updates.test.js
 * Purpose: The app's Update button: how each system updates, version comparison, the texts
 *          shown in Settings, and where macOS downloads a new version.
 */

'use strict';

const assert = require('assert');
const updates = require('../lib/updates');

test('Windows updates itself; macOS opens the download page; a copy run from the repo does not update', () => {
    assert.strictEqual(updates.mode('win32', true), 'auto');
    assert.strictEqual(updates.mode('darwin', true), 'download');   // auto-update needs an Apple-signed app
    assert.strictEqual(updates.mode('win32', false), 'dev');
    assert.strictEqual(updates.mode('darwin', false), 'dev');
});

test('versions compare by number, not as text', () => {
    assert.ok(updates.isNewer('2.0.10', '2.0.9'));
    assert.ok(updates.isNewer('2.1.0', '2.0.99'));
    assert.ok(!updates.isNewer('2.0.9', '2.0.10'));
    assert.ok(!updates.isNewer('2.0.5', '2.0.5'));
    assert.ok(updates.isNewer('v2.0.6', '2.0.5'));                   // tags start with v
});

test('status texts for the Settings window', () => {
    assert.strictEqual(updates.statusText({ state: 'checking' }), 'Checking for updates...');
    assert.strictEqual(updates.statusText({ state: 'none', current: '2.0.7' }), 'You have the latest version (2.0.7).');
    assert.strictEqual(updates.statusText({ state: 'available', version: '2.0.8' }), 'Version 2.0.8 is available.');
    assert.strictEqual(updates.statusText({ state: 'downloading', percent: 41.6 }), 'Downloading the update... 42%');
    assert.strictEqual(updates.statusText({ state: 'ready', version: '2.0.8' }),
        'Version 2.0.8 is ready. Restart to finish - your sites and settings are kept.');
    assert.strictEqual(updates.statusText({ state: 'error', message: 'net::ERR_INTERNET_DISCONNECTED' }),
        'Could not check for updates: net::ERR_INTERNET_DISCONNECTED');
    assert.strictEqual(updates.statusText({ state: 'dev' }), 'Updates work in the installed app (this copy runs from the project folder).');
});

test('macOS: the release page of the new version on GitHub', () => {
    const pkg = { build: { publish: [{ provider: 'github', owner: 'muthu-digiboost', repo: 'nginx-manager-2.0' }] } };
    assert.strictEqual(updates.releasePage(pkg, '2.0.8'), 'https://github.com/muthu-digiboost/nginx-manager-2.0/releases/tag/v2.0.8');
    assert.strictEqual(updates.releasePage(pkg), 'https://github.com/muthu-digiboost/nginx-manager-2.0/releases/latest');
    assert.strictEqual(updates.releasePage({}), null);
});
