<!--
******************************************************************************
*
*  Project : nginx-manager-2.0
*  File    : 2026-11-26-desktop-app-design.md
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

# Nginx Manager desktop app (Electron) - design

## Why
Today the manager is a web page: start the servers, then open http://127.0.0.1:8080 in a
browser. The user wants an app: click the icon and it works, in its own window, with the
servers running in the background and a tray icon (user's choices: Electron; servers keep
running when the window closes; tray icon).

## What it is
A small Electron app in `desktop/` (Windows + macOS, one codebase). The manager itself is
unchanged - the app shows the same pages in its own window and controls the servers with
the existing scripts.

## Behaviour
- **Click the app:** if the manager does not answer on /health, the app runs the start
  script (Windows `start-all.ps1` - asks for Administrator once, as today; macOS
  `start-all.sh`), shows "Starting servers..." and then the manager in its window.
- **One instance:** clicking the icon again brings the open window to the front.
- **Close the window:** it hides to the tray; nginx and PHP keep running. The first time a
  notification says so.
- **Tray / menu bar icon** (left click opens the window):
  Open Nginx Manager · status line (nginx running? PHP x of y) · Start all · Stop all ·
  Restart all · Reload nginx · Open in browser · Start at login (on/off) ·
  Quit (servers keep running) · Quit and stop servers.
- **Window:** no menu bar, title "Nginx Manager", remembers its size and position. Only the
  manager (127.0.0.1:8080) opens inside; any other link (your sites, php.net) opens in the
  default browser.
- **Which install:** the app needs the Nginx Manager folder. Run from the repo it uses the
  parent of `desktop/`; installed, it asks once with a folder picker and remembers it
  (changeable from the tray). (my call)

## Build
- `desktop/package.json` with electron + electron-builder; `npm run dist` builds
  `Nginx Manager Setup <ver>.exe` (NSIS installer) on Windows and a `.dmg` on macOS (must be
  built on a Mac; unsigned unless an Apple certificate is added).
- Icons generated from `manager/public/assets/logo.svg` (app icon + tray icon).
- `desktop/node_modules` and `desktop/dist` are gitignored.

## Constraints
- Same rules as the project: banner on every file, no absolute paths, nothing machine-
  specific committed.
- Security: no Node in the page (contextIsolation, no nodeIntegration), navigation locked
  to the manager's own address, scripts started with argument arrays (no shell strings).
- Node 16 is installed; Electron's current tooling wants Node 18+. If installs fail on 16,
  the user updates Node (20 LTS). (my call)

## Non-goals
- No change to the web UI or the scripts' behaviour.
- No auto-update of the app itself, no Apple code signing (can be added later).
