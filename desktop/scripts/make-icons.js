/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : make-icons.js
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
 * File: desktop/scripts/make-icons.js
 * Purpose: Renders the Nginx Manager logo (manager/public/assets/logo.svg: green hexagon,
 *          white N) into the PNG icons the app needs. Run: npm run icons
 * Output (committed):
 *   build/icon.png                 1024 px - electron-builder makes .ico / .icns from it
 *   assets/icon.png                 256 px - window and notification icon
 *   assets/tray.png, tray@2x.png    16 / 32 px - Windows tray (colour)
 *   assets/trayTemplate.png, @2x    16 / 32 px - macOS menu bar (black shape, N cut out;
 *                                   macOS tints "Template" images for light / dark mode)
 * Notes: the two paths are the ones in logo.svg (viewBox 0 0 24 24) - keep them in sync.
 */

'use strict';

const { app, BrowserWindow } = require('electron');
const fs = require('fs');
const path = require('path');

const HEXAGON = 'M12 1.2 21.6 6.6v10.8L12 22.8 2.4 17.4V6.6z';
const LETTER_N = 'M8.2 16.6V7.6l7.6 9V7.4';

const OUTPUTS = [
    { file: 'build/icon.png', size: 1024, template: false },
    { file: 'assets/icon.png', size: 256, template: false },
    { file: 'assets/tray.png', size: 16, template: false },
    { file: 'assets/tray@2x.png', size: 32, template: false },
    { file: 'assets/trayTemplate.png', size: 16, template: true },
    { file: 'assets/trayTemplate@2x.png', size: 32, template: true },
];

/** Runs in the page: draws the logo on a canvas, returns a PNG data URL. */
function drawScript(size, template) {
    return `(() => {
        const c = document.createElement('canvas');
        c.width = c.height = ${size};
        const g = c.getContext('2d');
        g.scale(${size} / 24, ${size} / 24);
        g.fillStyle = ${JSON.stringify(template ? '#000000' : '#009639')};
        g.fill(new Path2D(${JSON.stringify(HEXAGON)}));
        g.lineWidth = 2.3; g.lineCap = 'round'; g.lineJoin = 'round';
        ${template ? "g.globalCompositeOperation = 'destination-out';" : ''}
        g.strokeStyle = '#ffffff';
        g.stroke(new Path2D(${JSON.stringify(LETTER_N)}));
        return c.toDataURL('image/png');
    })()`;
}

app.whenReady().then(async () => {
    const win = new BrowserWindow({ show: false, webPreferences: { offscreen: true, contextIsolation: true, sandbox: true } });
    await win.loadURL('data:text/html,<!DOCTYPE html><title>icons</title>');
    const root = path.join(__dirname, '..');
    for (const o of OUTPUTS) {
        const dataUrl = await win.webContents.executeJavaScript(drawScript(o.size, o.template));
        const file = path.join(root, o.file);
        fs.mkdirSync(path.dirname(file), { recursive: true });
        fs.writeFileSync(file, Buffer.from(dataUrl.split(',')[1], 'base64'));
        console.log(`wrote ${o.file} (${o.size}px)`);
    }
    app.exit(0);
}).catch((e) => { console.error(e); app.exit(1); });
