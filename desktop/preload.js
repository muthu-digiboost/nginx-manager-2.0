/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : preload.js
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
 * File: desktop/preload.js
 * Purpose: The few actions the app's own Settings window (assets/settings.html) may ask for.
 *          Loaded ONLY into that window - never into the manager pages, which keep no Node
 *          access at all. Each call is a fixed message; no arbitrary IPC or file access.
 */

'use strict';

const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('nm', {
    info: () => ipcRenderer.invoke('nm:info'),
    checkForUpdates: () => ipcRenderer.invoke('nm:check-updates'),
    installUpdate: () => ipcRenderer.invoke('nm:install-update'),
    setStartAtLogin: (on) => ipcRenderer.invoke('nm:start-at-login', !!on),
    changeFolder: () => ipcRenderer.invoke('nm:change-folder'),
    onStatus: (fn) => ipcRenderer.on('nm:update-status', (_event, status) => fn(status)),
});
