<!--
******************************************************************************
*
*  Project : nginx-manager-2.0
*  File    : 2026-11-26-macos-support.md
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

# macOS support - Implementation Plan

**Goal:** the same Nginx Manager runs on Windows (unchanged) and macOS.
**Approach (user: "just dive in, I will test on Mac later"):**
- Windows keeps its tested PowerShell scripts.
- macOS work is done by PHP (`manager/bin/manager.php` commands), started by small
  bash 3.2 launchers (`*.sh`). PHP code is unit-tested on Windows with
  `Platform::override('Darwin')` and a fake Shell. No Mac is available: the first real
  run is the user's test.
- macOS runtime: Homebrew. nginx = `brew install nginx`; PHP = `shivammathur/php` tap
  (php@5.6 ... php@8.5); extensions = `shivammathur/extensions` tap.
- macOS privileges: everything runs as the logged-in user (nginx may bind 80/443 on
  macOS 10.14+). /etc/hosts writes ask for the admin password with the system dialog
  (osascript); certificates are trusted in the user's login keychain (`security`).

## Global constraints
- Banner on every new file; no absolute paths in committed files; UTF-8 without BOM.
- bash scripts: bash 3.2 (macOS /bin/bash), BSD tools only, LF line endings.
- Windows behaviour must not change: full PHP + Pester suites stay green.

## Tasks
1. **Platform core** - `Support/Platform` (os, isMac/isWindows, override), listening
   ports (Windows netstat / macOS `netstat -an -p tcp`), hosts file path, time zone
   detection (macOS: /etc/localtime link), nginx binary + running check, openssl lookup.
2. **PHP paths per version** - `Php/PhpPaths` (php, php-cgi, php.ini, extension dir,
   extension file naming .dll / .so) used by repository, ExtensionManager, CLI.
3. **Trust store + hosts** - `Ssl/TrustStore` (Windows certutil / macOS security);
   macOS hosts writes through an admin prompt.
4. **Jobs on macOS** - JobRunner launches `php manager.php job:run <script> ...` detached
   (nohup); job state/log written by PHP (`Php/JobLog`).
5. **Services** - `Mac/PhpCgi` (start/stop php-cgi on 127.0.0.1:port), `Mac/NginxProcess`,
   `Mac/Server` (start/stop/restart/reload all; first-run settings from brew PHP),
   `Mac/Brew` (prefixes, installed php@X.Y).
6. **PHP install/update** - brew tap/install/upgrade jobs; catalogue of branches;
   updates from `brew outdated`; PHP Versions page adapted (no TS/NTS, no VC runtime).
7. **Extensions** - .so naming, install from `shivammathur/extensions` as a job.
8. **Manager PHP switch** - PHP port of switch-manager-php.ps1 (same checks/rollback).
9. **Launchers** - start-all.sh, stop-all.sh, restart-all.sh, reload-nginx.sh,
   scripts/install-autostart.sh (LaunchAgent).
10. **UI wording + README** - platform-aware texts, macOS section in README.
