/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : applinks.js
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
 * File: desktop/lib/applinks.js
 * Purpose: Links on manager pages that ask the APP to do something, e.g. the Settings page's
 *          "Check for updates" (nginx-manager://check-updates). Only these exact links are
 *          actions; they open the app's own Settings window - nothing else can be triggered.
 *          The app also marks its user agent ("NginxManagerApp/<version>"), so the manager
 *          shows these links only inside the app (see manager/src/Support/DesktopApp.php).
 * Used by: main.js (the main window's navigation handlers).
 */

'use strict';

const ACTIONS = {
    'nginx-manager://check-updates': 'check-updates',
    'nginx-manager://settings': 'settings',
};

/** @returns {'check-updates'|'settings'|null} */
function actionFor(url) {
    if (typeof url !== 'string') return null;
    const key = url.replace(/\/$/, '');
    return Object.prototype.hasOwnProperty.call(ACTIONS, key) ? ACTIONS[key] : null;
}

/** The window's user agent with the app's marker (once). */
function userAgent(current, version) {
    const ua = String(current || '');
    return /\bNginxManagerApp\//.test(ua) ? ua : `${ua} NginxManagerApp/${version}`.trim();
}

module.exports = { actionFor, userAgent };
