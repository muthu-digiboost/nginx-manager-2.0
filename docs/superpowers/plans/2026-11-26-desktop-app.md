<!--
******************************************************************************
*
*  Project : nginx-manager-2.0
*  File    : 2026-11-26-desktop-app.md
*  Date    : 26/11/2026
*  Author  : Semansys Development Team
*
*  Copyright (c) 2026
*  Tel: +91 97863 37914
*  Email: dev.digiboost.muthu@gmail.com
*  All rights reserved, see the LICENSE file for more information.
*
******************************************************************************
-->

# Desktop app (Electron) - Implementation Plan

**Goal:** an installable Nginx Manager app for Windows and macOS: its own window, servers
running in the background, a tray / menu-bar icon.
**Spec:** docs/superpowers/specs/2026-11-26-desktop-app-design.md
**Branch:** feature/desktop-app
**Tech:** Electron + electron-builder in `desktop/`; plain CommonJS, no framework, no
bundler. Logic that does not need Electron lives in `desktop/lib/*.js` and is unit-tested
with Node's `assert` and a tiny runner (`desktop/test/run.js`, works on Node 16+).

## Global constraints
- Banner on every new text file; no absolute paths; nothing machine-specific committed.
- `desktop/node_modules`, `desktop/dist` gitignored.
- Page security: contextIsolation on, nodeIntegration off, no preload API, navigation and
  new windows limited to the manager origin (everything else opens in the default browser).
- Scripts are started with argument arrays (`child_process.spawn`, `shell: false`).
- The manager (PHP) and the scripts are not changed.

## Tasks
1. **Scaffold** - `desktop/package.json` (scripts: start, test, icons, dist; electron-builder
   config: appId com.semansys.nginx-manager, productName "Nginx Manager", win nsis, mac dmg),
   `.gitignore` entries, test runner.
2. **lib/config.js** - where the install is: `resolveBase({isPackaged, appDir, stored})`,
   `isManagerFolder(dir)` (has manager/bin/manager.php and start-all.*); JSON settings file
   in userData (`load/save`: base, bounds, notifiedTray, startAtLogin). Tests.
3. **lib/scripts.js** - `commandFor(platform, action, base)` -> {file, args} for
   start / stop / restart / reload (Windows: powershell.exe -NoProfile -ExecutionPolicy
   Bypass -File <base>\start-all.ps1 -NoPause; macOS: /bin/bash <base>/start-all.sh);
   `run(...)` resolves with the exit code and output. Tests.
4. **lib/health.js + lib/navigation.js** - `managerUrl(env)` from .env MANAGER_HOST/PORT,
   `checkHealth(url)` -> {ok, php} | null, `waitForHealth(url, ms)`; `isManagerUrl(url,
   origin)`. Tests (a local http server for health).
5. **main.js** - single instance, splash page while starting, BrowserWindow (no menu,
   saved bounds, close -> hide), tray with menu + status refresh every 10 s, actions,
   start at login, folder picker when the install is not found, "Quit and stop servers".
6. **Icons** - `npm run icons`: Electron renders logo.svg to build/icon.png (1024) and
   assets/tray*.png (16/32, macOS template images). Generated PNGs are committed.
7. **Build + CI** - `npm run dist` on Windows -> NSIS installer; GitHub Actions workflow
   building Windows and macOS on push of a `desktop-v*` tag / manually.
8. **Docs** - README "Desktop app" section; this plan's "Changes during implementation".

## Verification
- `npm test` (lib modules), PHP + Pester suites unchanged.
- `npm start` with `NM_SELFTEST=1`: the app starts, reaches /health, loads the manager in
  its window, writes the result to the log and quits.
- `npm run dist` produces the Windows installer.

## Changes during implementation
- **Electron 45 + electron-builder 26** instead of 33 / 24: Electron 33 is out of support
  (the IDE reported 47 known vulnerabilities). Supported Electron needs Node >= 22.12, so
  Node 22.23.5 was installed with nvm and made active (Node 16 stays installed:
  `nvm use 16.20.2`). `npm audit`: Electron itself clean; 6 moderate findings remain in the
  build tool only (minimatch via electron-builder), not shipped in the app.
- **NM_BASE** environment variable: names the install for one run (lets the built app be
  checked without the folder dialog).
- **Automatic builds (user request):** the workflow runs on every push to `main` (not on
  tags): tests first, then Windows + macOS builds as artifacts.
- The CI Pester step sets the execution policy for its own process and fails on any error:
  run locally, a blocked script had exited 0 without running the tests.

## Verification done (Windows)
- `npm test` 12/12; PHP 175/175; Pester 46/46.
- `NM_SELFTEST=1 npm start` and the built `dist/win-unpacked/Nginx Manager.exe`:
  both loaded the Dashboard ("Nginx Manager - Dashboard", PHP 8.5.12).
- `npm run dist:win` -> `Nginx Manager Setup 2.0.0.exe` (122 MB).
- Not verified here: the start-from-stopped path (needs the UAC prompt to be accepted by a
  person), the tray menu by hand, the macOS build (needs a Mac or the GitHub workflow).
