<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : bootstrap.php
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
 * File: manager/src/bootstrap.php
 * Purpose: Web request bootstrap - everything public/index.php needs before routing.
 * Responsibilities:
 *   - autoloader + helpers
 *   - error reporting (log, never display raw errors to the browser)
 *   - load .env (a missing .env is reported by index.php with setup instructions)
 *   - start a hardened session (HttpOnly, SameSite=Strict, own cookie name)
 * Used by: manager/public/index.php
 */

declare(strict_types=1);

require __DIR__ . '/autoload.php';

use NginxManager\Support\Env;
use NginxManager\Support\Paths;

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', Paths::in('logs', 'manager-php-error.log'));
date_default_timezone_set(@date_default_timezone_get() ?: 'UTC');

$GLOBALS['__envError'] = null;
try {
    // First run: create .env from the template - every value is then edited on the Settings page.
    \NginxManager\Support\EnvFile::ensure(Paths::in('.env'), Paths::in('.env.example'));
    Env::load(Paths::in('.env'));

    // Show dates in the PC's time zone (PHP on Windows defaults to UTC). Detected once from
    // Windows and remembered in .env; changeable on the Settings page.
    $tz = \NginxManager\Support\Timezone::configured();
    if (Env::get('APP_TIMEZONE') === null) {
        \NginxManager\Support\EnvFile::set(Paths::in('.env'), ['APP_TIMEZONE' => $tz]);
    }
    date_default_timezone_set($tz);
} catch (RuntimeException $e) {
    $GLOBALS['__envError'] = $e->getMessage();
}

session_name('nm2_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Strict',
]);
$sessionDir = Paths::in('temp', 'sessions');
if (!is_dir($sessionDir)) {
    @mkdir($sessionDir, 0777, true);
}
session_save_path($sessionDir);
session_start();
