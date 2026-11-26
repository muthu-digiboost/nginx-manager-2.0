<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : DesktopApp.php
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
 * File: manager/src/Support/DesktopApp.php
 * Purpose: Knows when the manager is shown inside the desktop app. The app adds
 *          "NginxManagerApp/<version>" to its user agent and handles two links of its own:
 *          nginx-manager://check-updates (opens the app's Settings and checks for a new
 *          version) and nginx-manager://settings. In a normal browser those links would do
 *          nothing, so pages show them only inside the app.
 * Used by: SettingsController (the "Desktop app" card on the Settings page).
 */

declare(strict_types=1);

namespace NginxManager\Support;

final class DesktopApp
{
    public const CHECK_UPDATES_URL = 'nginx-manager://check-updates';
    public const SETTINGS_URL = 'nginx-manager://settings';

    /** "2.0.12" when the request comes from the desktop app, null from any other browser. */
    public static function versionFromUserAgent(?string $userAgent): ?string
    {
        return preg_match('~\bNginxManagerApp/(\d+(?:\.\d+){1,3})\b~', (string) $userAgent, $m) ? $m[1] : null;
    }
}
