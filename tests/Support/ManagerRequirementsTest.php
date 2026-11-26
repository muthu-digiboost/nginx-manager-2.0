<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : ManagerRequirementsTest.php
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
 * File: tests/Support/ManagerRequirementsTest.php
 * Purpose: What the manager itself needs from a PHP version (switching the manager's PHP),
 *          minimum version and required extensions.
 */

declare(strict_types=1);

use NginxManager\App;
use NginxManager\Support\ManagerRequirements;
use NginxManager\Support\Paths;

test('a PHP version below the minimum is refused', function () {
    $p = ManagerRequirements::problems('8.0.30', ['mbstring', 'openssl']);
    assertSame(['PHP 8.1 or newer is required (this is 8.0.30).'], $p);
});

test('missing required extensions are listed separately so they can be enabled', function () {
    assertSame(['mbstring', 'openssl'], ManagerRequirements::missingExtensions(['core', 'json']));
    assertSame(['openssl'], ManagerRequirements::missingExtensions(['MBString']));
    assertSame([], ManagerRequirements::problems('8.4.28', ['mbstring', 'openssl']));
});

