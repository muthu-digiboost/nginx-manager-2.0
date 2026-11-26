<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : TimezoneTest.php
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
 * File: tests/Support/TimezoneTest.php
 * Purpose: Windows time zone names (tzutil /g) map to PHP/IANA zones; the configured zone
 *          wins; anything unknown or invalid falls back to UTC.
 */

declare(strict_types=1);

use NginxManager\Support\Env;
use NginxManager\Support\Timezone;

test('windows zone names map to IANA', function () {
    assertSame('Asia/Kolkata', Timezone::fromWindows('India Standard Time'));
    assertSame('Europe/London', Timezone::fromWindows('GMT Standard Time'));
    assertSame('UTC', Timezone::fromWindows('Unknown Zone Name'));
});

test('APP_TIMEZONE wins when valid, invalid values fall back', function () {
    Env::fromArray(['APP_TIMEZONE' => 'Asia/Dubai']);
    assertSame('Asia/Dubai', Timezone::configured(fn() => 'India Standard Time'));
    Env::fromArray(['APP_TIMEZONE' => 'Mars/Base']);
    assertSame('Asia/Kolkata', Timezone::configured(fn() => 'India Standard Time'));
    Env::fromArray([]);
    assertSame('Asia/Kolkata', Timezone::configured(fn() => 'India Standard Time'));
});
