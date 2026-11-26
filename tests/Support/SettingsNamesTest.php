<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : SettingsNamesTest.php
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
 * File: tests/Support/SettingsNamesTest.php
 * Purpose: Extra project folders get their name automatically (no name box in Settings):
 *          drive letter first, then drive + folder name, then a number.
 */

declare(strict_types=1);

use NginxManager\Controllers\SettingsController;

test('extra project folder names are derived from the folder', function () {
    assertSame('D', SettingsController::autoName('D:/projects', []));
    assertSame('DWORK', SettingsController::autoName('D:/work', ['D']));
    assertSame('D2', SettingsController::autoName('D:/work', ['D', 'DWORK']));
    assertSame('F', SettingsController::autoName('F:/projects/client-a', ['D']));
});
