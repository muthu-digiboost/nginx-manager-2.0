<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : autoload.php
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
 * File: manager/src/autoload.php
 * Purpose: PSR-4 style autoloader for the NginxManager\ namespace + global helpers.
 * Responsibilities:
 *   - map NginxManager\Foo\Bar  ->  manager/src/Foo/Bar.php
 *   - load helpers.php (e(), url(), csrf_field(), ...)
 * Used by: manager/src/bootstrap.php (web), manager/bin/manager.php (CLI), tests/bootstrap.php
 * Notes: no Composer on purpose - the manager must run from a bare portable PHP.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'NginxManager\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require __DIR__ . '/helpers.php';
