/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : main.js
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
 * File: desktop/main.js
 * Purpose: The Nginx Manager desktop app (Electron main process).
 * Behaviour (docs/superpowers/specs/2026-11-26-desktop-app-design.md):
 *   - one instance; clicking the app again brings the window to the front
 *   - finds the install (repo parent, or the folder picked once); starts the servers with
 *     the install's own script when the manager does not answer, then shows it
 *   - closing the window hides it to the tray; nginx and PHP keep running
 *   - tray / menu-bar icon: Open, status, Start / Stop / Restart all, Reload nginx,
 *     Open in browser, Start at login, Change folder, Quit, Quit and stop servers
 *   - only the manager loads inside the window; other links open in the default browser
 *   - NM_SELFTEST=1: start, load the manager, print SELFTEST ok|fail, quit (used in checks)
 * Security: contextIsolation + sandbox, no nodeIntegration, no preload API.
 */

'use strict';

const { app, BrowserWindow, Tray, Menu, nativeImage, dialog, shell, Notification, ipcMain, net } = require('electron');
const updates = require('./lib/updates');
const pkg = require('./package.json');
const path = require('path');
const config = require('./lib/config');
const scripts = require('./lib/scripts');
const health = require('./lib/health');
const errors = require('./lib/errors');
const setup = require('./lib/setup');
const fs = require('fs');

// Unexpected errors: logged to <userData>/logs/main.log instead of Electron's raw dialog.
// "Own files gone" = an upgrade is replacing the app: quit quietly.
function onUnexpected(err) {
    if (errors.isAppReplaced(err, app.getAppPath())) {
        app.exit(0);
        return;
    }
    errors.log(path.join(app.getPath('userData'), 'logs', 'main.log'), err);
}
process.on('uncaughtException', onUnexpected);
process.on('unhandledRejection', onUnexpected);

const IS_MAC = process.platform === 'darwin';
const SELFTEST = process.env.NM_SELFTEST === '1' || process.env.NM_SELFTEST === 'settings';
const STARTED_HIDDEN = process.argv.includes('--hidden');   // "Start at login"

let win = null;
let tray = null;
let base = null;
let managerUrl = null;
let quitting = false;
let busy = '';             // label of the running action, '' when idle
let lastHealth = null;     // {ok, php} | null
let settingsFile = '';

// -- one instance ---------------------------------------------------------------------------
// Check runs (NM_SELFTEST / NM_SETUP_TEST) do not take the lock: they must not hand over to a
// copy of the app that is already running.
const CHECK_RUN = SELFTEST || !!process.env.NM_SETUP_TEST;
if (!CHECK_RUN && !app.requestSingleInstanceLock()) {
    app.quit();
} else {
    app.on('second-instance', () => showWindow());
    app.whenReady().then(main).catch((e) => fail(e));
}

app.on('window-all-closed', () => { /* stay in the tray */ });
app.on('activate', () => showWindow());   // macOS: click on the Dock icon
app.on('before-quit', () => { quitting = true; });

async function main() {
    settingsFile = path.join(app.getPath('userData'), 'settings.json');
    const settings = config.load(settingsFile);
    // NM_BASE (optional) names the install for this run - used by checks of the built app.
    const envBase = config.isManagerFolder(process.env.NM_BASE || '') ? process.env.NM_BASE : '';
    base = envBase || config.resolveBase({ isPackaged: app.isPackaged, appDir: __dirname, stored: settings.base });
    const bundleDir = findBundle();
    if (process.env.NM_SETUP_TEST) {   // checks of the installer: set up into a folder, no dialogs, no servers
        await setupTest(bundleDir, process.env.NM_SETUP_TEST);
        return;
    }
    if (!base) {
        // A running manager tells where it is installed: no question needed.
        base = config.fromHealth(await health.check(health.managerUrl('')));
        if (base) config.save(settingsFile, { base });
    }
    if (!base && bundleDir) {
        // A new machine: set Nginx Manager up from the files in the installer.
        base = await setupNew(bundleDir);
        if (base === false) { app.quit(); return; }
    }
    if (!base) {
        base = await pickFolder({ explain: true });
        if (!base) { app.quit(); return; }
    }
    if (setup.installedVersion(base) !== null) {
        // A folder the app created (also by an older version): keep the uninstaller's record
        // current, so the uninstall page can offer to delete it.
        const remember = setup.rememberCommand(process.platform, base);
        if (remember) scripts.run(remember, { timeoutMs: 15000 });
    }
    if (bundleDir && setup.needsUpdate(base, app.getVersion())) {
        // The app was updated: refresh the manager's code in the folder it set up (data untouched).
        setup.apply(bundleDir, base, app.getVersion());
        notify('Nginx Manager updated', 'The manager in ' + base + ' was updated to ' + app.getVersion() + '.');
    }
    managerUrl = health.managerUrl(base);

    createTray();
    createWindow(settings);
    if (!STARTED_HIDDEN || SELFTEST) showWindow();

    lastHealth = await health.check(managerUrl);
    if (!lastHealth) {
        await runAction('start', { quiet: true });
    }
    if (lastHealth) {
        win.loadURL(managerUrl);
    } else {
        loadingPage('error', 'The manager did not answer at ' + managerUrl + '.');
    }
    setInterval(refreshStatus, 10000);

    registerSettingsIpc();
    if (updateMode !== 'dev' && !SELFTEST) {
        // Quietly look for a new version shortly after start and every 6 hours.
        setTimeout(() => checkForUpdates({ quiet: true }), 15000);
        setInterval(() => checkForUpdates({ quiet: true }), 6 * 3600 * 1000);
    }

    if (SELFTEST) selfTest();
}

// -- window ---------------------------------------------------------------------------------
function createWindow(settings) {
    const b = settings.bounds || {};
    win = new BrowserWindow({
        width: b.width || 1280,
        height: b.height || 820,
        x: b.x, y: b.y,
        minWidth: 900, minHeight: 600,
        show: false,
        title: 'Nginx Manager',
        icon: iconPath('icon.png'),
        autoHideMenuBar: true,
        backgroundColor: '#f0f0f1',
        webPreferences: { contextIsolation: true, nodeIntegration: false, sandbox: true },
    });
    win.removeMenu();
    loadingPage('starting');

    // Only the manager inside; everything else in the default browser.
    win.webContents.setWindowOpenHandler(({ url }) => {
        if (health.isManagerUrl(url, managerUrl)) {
            win.loadURL(url);
        } else {
            openExternal(url);
        }
        return { action: 'deny' };
    });
    win.webContents.on('will-navigate', (event, url) => {
        if (url.startsWith('file:') || health.isManagerUrl(url, managerUrl)) return;
        event.preventDefault();
        openExternal(url);
    });

    let saveTimer = null;
    const saveBounds = () => {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(() => {
            if (win && !win.isDestroyed() && !win.isMinimized()) config.save(settingsFile, { bounds: win.getBounds() });
        }, 500);
    };
    win.on('resize', saveBounds);
    win.on('move', saveBounds);

    win.on('close', (event) => {
        if (quitting) return;
        event.preventDefault();            // hide to the tray; the servers keep running
        win.hide();
        if (IS_MAC && app.dock) app.dock.hide();
        const s = config.load(settingsFile);
        if (!s.notifiedTray) {
            notify('Nginx Manager is still running', 'nginx and PHP keep running. Use the tray icon to open the manager or stop the servers.');
            config.save(settingsFile, { notifiedTray: true });
        }
    });
}

function loadingPage(state, msg = '') {
    if (!win) return;
    const query = { state };
    if (msg) query.msg = msg;
    win.loadFile(path.join(__dirname, 'assets', 'loading.html'), { query });
}

function showWindow() {
    if (!win) return;
    if (IS_MAC && app.dock) app.dock.show();
    if (win.isMinimized()) win.restore();
    win.show();
    win.focus();
}

// -- tray -----------------------------------------------------------------------------------
function createTray() {
    const img = nativeImage.createFromPath(iconPath(IS_MAC ? 'trayTemplate.png' : 'tray.png'));
    if (IS_MAC) img.setTemplateImage(true);
    tray = new Tray(img);
    tray.setToolTip('Nginx Manager');
    if (!IS_MAC) tray.on('click', () => showWindow());   // macOS: a click opens the menu
    refreshMenu();
}

function refreshMenu() {
    if (!tray) return;
    const status = busy
        ? busy + '...'
        : (lastHealth ? 'Running - PHP ' + lastHealth.php : 'Stopped');
    const loginItem = !IS_MAC && !app.isPackaged ? null : app.getLoginItemSettings();
    const idle = busy === '';
    tray.setToolTip('Nginx Manager - ' + status);
    tray.setContextMenu(Menu.buildFromTemplate([
        { label: 'Open Nginx Manager', click: () => showWindow() },
        { label: status, enabled: false },
        { type: 'separator' },
        { label: 'Start all', enabled: idle, click: () => runAction('start') },
        { label: 'Stop all', enabled: idle, click: () => runAction('stop') },
        { label: 'Restart all', enabled: idle, click: () => runAction('restart') },
        { label: 'Reload nginx', enabled: idle, click: () => runAction('reload') },
        { type: 'separator' },
        { label: 'Open in browser', click: () => shell.openExternal(managerUrl) },
        { type: 'separator' },
        ...(updateStatus.state === 'available' || updateStatus.state === 'ready'
            ? [{ label: 'Update available: ' + updateStatus.version, click: () => openSettings() }] : []),
        { label: 'Check for updates...', click: () => { openSettings(); checkForUpdates(); } },
        { label: 'Settings...', click: () => openSettings() },
        {
            label: 'Start at login', type: 'checkbox', checked: !!(loginItem && loginItem.openAtLogin),
            enabled: app.isPackaged, click: (item) => setStartAtLogin(item.checked),
        },
        { label: 'Change Nginx Manager folder...', click: () => changeFolder() },
        { type: 'separator' },
        { label: 'Quit (servers keep running)', click: () => { quitting = true; app.quit(); } },
        { label: 'Quit and stop servers', enabled: idle, click: () => quitAndStop() },
    ]));
}

async function refreshStatus() {
    if (busy) return;
    lastHealth = await health.check(managerUrl);
    refreshMenu();
}

// -- actions --------------------------------------------------------------------------------
const LABELS = { start: 'Starting servers', stop: 'Stopping servers', restart: 'Restarting servers', reload: 'Reloading nginx' };

/** Runs a server script, then waits until /health shows the expected result. */
async function runAction(action, { quiet = false } = {}) {
    if (busy) return false;
    busy = LABELS[action];
    refreshMenu();
    try {
        const r = await scripts.run(scripts.commandFor(process.platform, action, base), { cwd: base });
        let ok = r.code === 0;
        if (action === 'start' || action === 'restart') {
            // Windows: the script relaunches itself elevated and returns at once - wait for the UI.
            ok = await health.waitFor(managerUrl, true, 180000, 1000);
        } else if (action === 'stop') {
            ok = await health.waitFor(managerUrl, false, 60000, 1000);
        }
        lastHealth = await health.check(managerUrl);
        if (ok && (action === 'start' || action === 'restart') && win && !win.webContents.getURL().startsWith('http')) {
            win.loadURL(managerUrl);
        }
        if (!quiet || !ok) {
            notify(ok ? LABELS[action].replace(/ing /, 'ed ').replace('Stoped', 'Stopped') : LABELS[action] + ' failed',
                ok ? '' : lastLines(r.output));
        }
        if (!ok && action === 'start' && win) loadingPage('error', lastLines(r.output));
        return ok;
    } finally {
        busy = '';
        refreshMenu();
    }
}

async function quitAndStop() {
    await runAction('stop');
    quitting = true;
    app.quit();
}

function setStartAtLogin(on) {
    app.setLoginItemSettings({ openAtLogin: on, openAsHidden: true, args: ['--hidden'] });
    config.save(settingsFile, { startAtLogin: on });
    refreshMenu();
}

async function changeFolder() {
    const picked = await pickFolder();
    if (picked && picked !== base) {
        app.relaunch();
        quitting = true;
        app.quit();
    }
}

/** Asks for the Nginx Manager folder; remembers it. @returns {Promise<string|null>} */
async function pickFolder({ explain = false } = {}) {
    if (explain) {
        // The Windows folder picker shows no message of its own - explain first.
        const answer = await dialog.showMessageBox({
            type: 'info',
            title: 'Nginx Manager',
            message: 'Where is your Nginx Manager folder?',
            detail: 'Choose the folder you installed Nginx Manager in - the one that contains '
                + (IS_MAC ? 'start-all.sh' : 'start-all.ps1') + ' and the "manager" folder '
                + '(for example ' + (IS_MAC ? '/Users/you/NginxManager' : 'C:\\NginxManager') + ').\n\n'
                + 'The app remembers it. You can change it later from the tray icon.',
            buttons: ['Choose folder...', 'Quit'],
            defaultId: 0,
            cancelId: 1,
        });
        if (answer.response !== 0) return null;
    }
    for (;;) {
        const r = await dialog.showOpenDialog({
            title: 'Choose the Nginx Manager folder',
            defaultPath: app.getPath('home'),
            message: 'Choose the Nginx Manager folder (the one with start-all and the manager folder).',
            properties: ['openDirectory'],
        });
        if (r.canceled || !r.filePaths.length) return null;
        if (config.isManagerFolder(r.filePaths[0])) {
            config.save(settingsFile, { base: r.filePaths[0] });
            return r.filePaths[0];
        }
        await dialog.showMessageBox({ type: 'warning', message: 'That is not an Nginx Manager folder.',
            detail: 'Pick the folder that contains start-all and manager/bin/manager.php.' });
    }
}

// -- settings window + updates -------------------------------------------------------------------

const updateMode = updates.mode(process.platform, app.isPackaged);
let updateStatus = { state: updateMode === 'dev' ? 'dev' : 'idle' };
let settingsWin = null;
let autoUpdater = null;

function setUpdateStatus(status) {
    updateStatus = status;
    const payload = { ...status, mode: updateMode, text: updates.statusText(status) };
    if (settingsWin && !settingsWin.isDestroyed()) settingsWin.webContents.send('nm:update-status', payload);
    refreshMenu();
}

/** electron-updater, set up once (Windows, installed app only). */
function getAutoUpdater() {
    if (autoUpdater) return autoUpdater;
    autoUpdater = require('electron-updater').autoUpdater;
    autoUpdater.autoDownload = false;            // the user clicks "Update now"
    autoUpdater.autoInstallOnAppQuit = false;
    autoUpdater.on('checking-for-update', () => setUpdateStatus({ state: 'checking' }));
    autoUpdater.on('update-available', (info) => setUpdateStatus({ state: 'available', version: info.version }));
    autoUpdater.on('update-not-available', () => setUpdateStatus({ state: 'none', current: app.getVersion() }));
    autoUpdater.on('download-progress', (p) => setUpdateStatus({ state: 'downloading', percent: p.percent, version: updateStatus.version }));
    autoUpdater.on('update-downloaded', (info) => setUpdateStatus({ state: 'ready', version: info.version }));
    autoUpdater.on('error', (e) => setUpdateStatus({ state: 'error', message: String((e && e.message) || e).split('\n')[0] }));
    return autoUpdater;
}

async function checkForUpdates({ quiet = false } = {}) {
    if (updateMode === 'dev') { setUpdateStatus({ state: 'dev' }); return; }
    if (['checking', 'downloading', 'ready'].includes(updateStatus.state)) return;
    try {
        if (updateMode === 'auto') {
            await getAutoUpdater().checkForUpdates();
        } else {
            // macOS (unsigned app): ask GitHub's public API for the newest release.
            setUpdateStatus({ state: 'checking' });
            const pub = pkg.build.publish[0];
            const res = await net.fetch(`https://api.github.com/repos/${pub.owner}/${pub.repo}/releases/latest`,
                { headers: { Accept: 'application/vnd.github+json', 'User-Agent': 'nginx-manager-app' } });
            if (!res.ok) throw new Error('GitHub answered ' + res.status);
            const latest = String((await res.json()).tag_name || '').replace(/^v/i, '');
            setUpdateStatus(updates.isNewer(latest, app.getVersion())
                ? { state: 'available', version: latest }
                : { state: 'none', current: app.getVersion() });
        }
    } catch (e) {
        setUpdateStatus({ state: 'error', message: String((e && e.message) || e).split('\n')[0] });
    }
    if (quiet && updateStatus.state === 'available') {
        notify('Nginx Manager ' + updateStatus.version + ' is available', 'Open Settings from the tray icon to update.');
    }
}

async function installUpdate() {
    if (updateStatus.state === 'ready' && updateMode === 'auto') {
        // Restart into the new version. nginx and PHP keep running; on the next start the app
        // refreshes the manager's code in its folder (data untouched - lib/setup.js).
        quitting = true;
        getAutoUpdater().quitAndInstall(false, true);
    } else if (updateStatus.state === 'available' && updateMode === 'auto') {
        setUpdateStatus({ state: 'downloading', percent: 0, version: updateStatus.version });
        try {
            await getAutoUpdater().downloadUpdate();
        } catch (e) {
            setUpdateStatus({ state: 'error', message: String((e && e.message) || e).split('\n')[0] });
        }
    } else if (updateStatus.state === 'available') {
        shell.openExternal(updates.releasePage(pkg, updateStatus.version));
    }
}

function openSettings() {
    if (settingsWin && !settingsWin.isDestroyed()) { settingsWin.show(); settingsWin.focus(); return; }
    settingsWin = new BrowserWindow({
        width: 580, height: 640, resizable: false, minimizable: false, maximizable: false,
        title: 'Nginx Manager - App settings', icon: iconPath('icon.png'), backgroundColor: '#f0f0f1',
        webPreferences: { preload: path.join(__dirname, 'preload.js'), contextIsolation: true, nodeIntegration: false, sandbox: true },
    });
    settingsWin.removeMenu();
    settingsWin.webContents.on('will-navigate', (e) => e.preventDefault());
    settingsWin.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
    settingsWin.loadFile(path.join(__dirname, 'assets', 'settings.html'));
}

function registerSettingsIpc() {
    // Only the Settings window may call these.
    const fromSettings = (event) => settingsWin && !settingsWin.isDestroyed() && event.sender === settingsWin.webContents;
    const guard = (fn) => (event, ...args) => (fromSettings(event) ? fn(...args) : null);
    ipcMain.handle('nm:info', guard(() => ({
        version: app.getVersion(),
        base,
        isPackaged: app.isPackaged,
        startAtLogin: app.isPackaged ? app.getLoginItemSettings().openAtLogin : false,
        status: { ...updateStatus, mode: updateMode, text: updates.statusText(updateStatus) },
    })));
    ipcMain.handle('nm:check-updates', guard(() => checkForUpdates()));
    ipcMain.handle('nm:install-update', guard(() => installUpdate()));
    ipcMain.handle('nm:start-at-login', guard((on) => setStartAtLogin(!!on)));
    ipcMain.handle('nm:change-folder', guard(() => changeFolder()));
}

// -- first run on a new machine ---------------------------------------------------------------

/** The manager files shipped in the installer (resources/nginx-manager), or null. */
function findBundle() {
    const dir = app.isPackaged
        ? path.join(process.resourcesPath, 'nginx-manager')
        : path.join(__dirname, 'bundle', 'nginx-manager');      // after "npm run bundle"
    return fs.existsSync(path.join(dir, 'manager', 'bin', 'manager.php')) ? dir : null;
}

/**
 * Offers to set Nginx Manager up from the installer's files.
 * @returns {Promise<string|null|false>} the folder; null = the user will pick an existing one; false = quit
 */
async function setupNew(bundleDir) {
    const target = setup.defaultFolder(process.platform, app.getPath('home'));
    const answer = await dialog.showMessageBox({
        type: 'question',
        title: 'Nginx Manager',
        message: 'Set up Nginx Manager on this computer?',
        detail: 'Nginx Manager (the manager' + (IS_MAC ? '' : ', nginx and PHP 8.4') + ') will be set up in:\n'
            + target + '\n\nYour sites, certificates and settings will be kept in that folder.'
            + (IS_MAC ? '\n\nOn macOS nginx and PHP come from Homebrew - start-all.sh tells you what to install.' : ''),
        buttons: ['Set up here', 'Choose another folder...', 'I already have Nginx Manager...', 'Quit'],
        defaultId: 0,
        cancelId: 3,
    });
    if (answer.response === 3) return false;
    if (answer.response === 2) return null;   // pickFolder() next
    let dest = target;
    if (answer.response === 1) {
        const r = await dialog.showOpenDialog({ title: 'Where should Nginx Manager be set up?',
            defaultPath: app.getPath('home'), properties: ['openDirectory', 'createDirectory'] });
        if (r.canceled || !r.filePaths.length) return false;
        dest = r.filePaths[0];
        // A folder that already holds other things gets its own "NginxManager" folder inside.
        if (!config.isManagerFolder(dest) && fs.readdirSync(dest).length > 0) dest = path.join(dest, 'NginxManager');
    }
    try {
        setup.apply(bundleDir, dest, app.getVersion());
    } catch (e) {
        await dialog.showMessageBox({ type: 'error', message: 'Nginx Manager could not be set up in ' + dest,
            detail: String(e.message || e) + '\n\nChoose another folder (one you can write to).' });
        return setupNew(bundleDir);
    }
    config.save(settingsFile, { base: dest });
    // Tell the uninstaller which folder the app created (only that one may be offered for deletion).
    const remember = setup.rememberCommand(process.platform, dest);
    if (remember) await scripts.run(remember, { timeoutMs: 15000 });
    await checkPhpRuns(dest);
    return dest;
}

/** Windows: PHP needs the Microsoft Visual C++ runtime; many new PCs do not have it. */
async function checkPhpRuns(dest, interactive = true) {
    if (IS_MAC) return true;
    const phpRoot = path.join(dest, 'php');
    const versions = fs.existsSync(phpRoot) ? fs.readdirSync(phpRoot) : [];
    for (const v of versions) {
        const exe = path.join(phpRoot, v, 'php.exe');
        if (!fs.existsSync(exe)) continue;
        const r = await scripts.run({ file: exe, args: ['-n', '-v'] }, { timeoutMs: 30000 });
        if (r.code === 0) return true;
        if (!interactive) return false;
        const answer = await dialog.showMessageBox({
            type: 'warning',
            title: 'Nginx Manager',
            message: 'PHP needs the Microsoft Visual C++ Redistributable (x64)',
            detail: 'PHP ' + v + ' could not run on this computer - the Microsoft Visual C++ 2015-2022 '
                + 'Redistributable (x64) is probably missing. Install it from Microsoft, then start Nginx Manager again.',
            buttons: ['Download from Microsoft', 'Continue'],
            defaultId: 0,
        });
        if (answer.response === 0) shell.openExternal('https://aka.ms/vs/17/release/vc_redist.x64.exe');
        return false;
    }
    return true;
}

/** NM_SETUP_TEST=<folder>: set up there without dialogs, check PHP, print the result, quit. */
async function setupTest(bundleDir, dest) {
    if (!bundleDir) { console.log('SETUP fail no bundle'); app.exit(1); return; }
    const r = setup.apply(bundleDir, dest, app.getVersion());
    const phpOk = await checkPhpRuns(dest, false);
    console.log(`SETUP ok copied=${r.copied} skipped=${r.skipped} php=${phpOk ? 'runs' : 'FAILS'} folder=${dest}`);
    app.exit(phpOk ? 0 : 1);
}

// -- helpers --------------------------------------------------------------------------------
function iconPath(name) {
    return path.join(__dirname, 'assets', name);
}

function openExternal(url) {
    if (/^https?:\/\//i.test(url)) shell.openExternal(url);   // never file:, javascript:, ...
}

function notify(title, body) {
    if (SELFTEST || !Notification.isSupported()) return;
    new Notification({ title, body: body || '', icon: iconPath('icon.png') }).show();
}

function lastLines(text, n = 12) {
    return String(text || '').trim().split(/\r?\n/).slice(-n).join('\n');
}

function fail(e) {
    console.error(e);
    if (SELFTEST) { console.log('SELFTEST fail ' + (e && e.message)); app.exit(1); }
}

/** NM_SELFTEST=1: is the manager shown in the window? Prints the result and quits. */
function selfTest() {
    const timer = setTimeout(() => { console.log('SELFTEST fail timeout'); app.exit(1); }, 200000);
    if (process.env.NM_SELFTEST === 'settings') {
        // The Settings window: does it load and show the app's data through preload.js?
        openSettings();
        settingsWin.webContents.on('did-finish-load', () => setTimeout(async () => {
            await checkForUpdates();
            const shown = await settingsWin.webContents.executeJavaScript(
                "[document.getElementById('version').textContent, document.getElementById('base').textContent, document.getElementById('status').textContent].join(' | ')");
            clearTimeout(timer);
            console.log('SELFTEST settings ' + shown);
            app.exit(0);
        }, 800));
        return;
    }
    const report = () => {
        const url = win.webContents.getURL();
        if (!url.startsWith('http')) return;   // still the loading page
        clearTimeout(timer);
        win.webContents.executeJavaScript('document.title').then((title) => {
            console.log('SELFTEST ok url=' + url + ' title=' + title + ' php=' + (lastHealth && lastHealth.php));
            app.exit(0);
        });
    };
    win.webContents.on('did-finish-load', report);
    report();
}
