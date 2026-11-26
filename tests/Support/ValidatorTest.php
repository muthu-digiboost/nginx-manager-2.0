<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : ValidatorTest.php
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
 * File: tests/Support/ValidatorTest.php
 * Purpose: Input validation rules shared by every controller (domains, site ids, paths).
 */

declare(strict_types=1);

use NginxManager\Support\Validator;

test('domain validation', function () {
    assertTrue(Validator::domain('api.example.test'));
    assertTrue(Validator::domain('*.example.test'));
    assertTrue(Validator::domain('my-app.local.example.test'));
    assertTrue(!Validator::domain('localhost'));          // needs a dot
    assertTrue(!Validator::domain('a..b.com'));
    assertTrue(!Validator::domain('-a.example.test'));
    assertTrue(!Validator::domain('API.example.test'));   // lowercase only
    assertTrue(!Validator::domain('a.example.test;'));
});

test('site id rejects traversal and separators', function () {
    assertTrue(Validator::siteId('api.example.test'));
    assertTrue(Validator::siteId('wildcard.example.test'));
    assertTrue(!Validator::siteId('../nginx'));
    assertTrue(!Validator::siteId('a/b'));
    assertTrue(!Validator::siteId('a\\b'));
    assertTrue(!Validator::siteId(''));
});

test('absolute windows path', function () {
    assertTrue(Validator::absolutePath('Q:/git/app'));
    assertTrue(Validator::absolutePath('Q:\\git\\app'));
    assertTrue(!Validator::absolutePath('git/app'));
    assertTrue(!Validator::absolutePath('https://x'));
});

test('project folders must be safe inside an nginx map value', function () {
    // Regression (final review #11): "$" is expanded by nginx as a variable and broke env.conf.
    assertTrue(Validator::nginxSafePath('Q:/git/my projects'));
    foreach (['Q:/a$b', 'Q:/a;b', 'Q:/a"b', 'Q:/a{b', 'Q:/a}b', 'Q:/a#b', "Q:/a\nb"] as $bad) {
        assertTrue(!Validator::nginxSafePath($bad), $bad);
    }
});
