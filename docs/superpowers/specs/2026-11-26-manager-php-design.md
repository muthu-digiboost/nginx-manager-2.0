<!--
******************************************************************************
*
*  Project : nginx-manager-2.0
*  File    : 2026-11-26-manager-php-design.md
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

# Manager PHP switch - design

## Why
The manager UI itself ran on a fixed PHP (8.3). The user wants to change it (e.g. to 8.4)
from the frontend, with incompatibilities handled automatically, the way wp-admin does.

## What it does
- PHP Versions page, card "Manager PHP": current version + "Switch to" (installed versions,
  PHP 8.1+ only, newest preselected) + "Switch manager PHP".
- Background job `scripts/switch-manager-php.ps1 -Id <id>`:
  1. target must be PHP 8.1+ (`ManagerRequirements::MIN_PHP`)
  2. required extensions (mbstring, openssl) enabled in the target php.ini when missing
  3. compatibility check with the target php.exe: `php -l` on every manager file + the test
     suite, with every error, warning and deprecation shown; any problem = nothing changes
  4. target php-cgi started
  5. settings.json managerVersion switched; the old version keeps running / auto-starting
  6. manager.conf rewritten, `nginx -t`, reload
  7. `GET /health` must report the target PHP; otherwise everything is switched back
- No app/self-update and no app version number (user: "not a 2.0 version").

## Not done
- The manager does not rewrite its own source: "resolve" = enable what is missing,
  refuse what would break, switch back on failure.
