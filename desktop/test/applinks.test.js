/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : applinks.test.js
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
 * File: desktop/test/applinks.test.js
 * Purpose: Links on manager pages that ask the app itself to do something (the Settings
 *          page's "Check for updates"); everything else is an ordinary link.
 */

'use strict';

const assert = require('assert');
const applinks = require('../lib/applinks');

test('the two app links are recognised', () => {
    assert.strictEqual(applinks.actionFor('nginx-manager://check-updates'), 'check-updates');
    assert.strictEqual(applinks.actionFor('nginx-manager://settings'), 'settings');
    assert.strictEqual(applinks.actionFor('nginx-manager://check-updates/'), 'check-updates');   // trailing slash
});

test('anything else is not an app link', () => {
    for (const url of ['nginx-manager://format-c', 'nginx-manager://check-updates/../x', 'http://127.0.0.1:8080/settings',
        'https://example.com/nginx-manager://settings', 'javascript:alert(1)', '', null, undefined]) {
        assert.strictEqual(applinks.actionFor(url), null, String(url));
    }
});

test('the app marks its user agent, so the manager knows it runs inside the app', () => {
    assert.strictEqual(applinks.userAgent('Mozilla/5.0 Chrome/140 Electron/45.5.0', '2.0.12'),
        'Mozilla/5.0 Chrome/140 Electron/45.5.0 NginxManagerApp/2.0.12');
    // not twice
    assert.strictEqual(applinks.userAgent('Mozilla/5.0 NginxManagerApp/2.0.12', '2.0.12'), 'Mozilla/5.0 NginxManagerApp/2.0.12');
});
