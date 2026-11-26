<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : Validator.php
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
 * File: manager/src/Support/Validator.php
 * Purpose: Input rules shared by every form and route parameter.
 * Responsibilities:
 *   - domain()       lowercase host names with at least one dot, optional leading "*."
 *   - siteId()       a conf/sites file name without extension - never a path
 *   - absolutePath() Windows drive path (C:\... or C:/...)
 * Used by: controllers, SiteRepository, CertificateService, Paths
 * Notes: the manager runs as Administrator, so every value that reaches a file name or a
 *        command line is validated here first.
 */

declare(strict_types=1);

namespace NginxManager\Support;

final class Validator
{
    private const LABEL = '[a-z0-9](?:[a-z0-9-]*[a-z0-9])?';

    public static function domain(string $value): bool
    {
        $re = '/^(?:\*\.)?' . self::LABEL . '(?:\.' . self::LABEL . ')+$/';
        return strlen($value) <= 253 && preg_match($re, $value) === 1;
    }

    public static function siteId(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= 253
            && preg_match('/^[a-z0-9][a-z0-9.-]*$/', $value) === 1
            && !str_contains($value, '..');
    }

    public static function absolutePath(string $value): bool
    {
        return Platform::isWindows()
            ? preg_match('~^[A-Za-z]:[\\\\/]~', $value) === 1
            : str_starts_with($value, '/');
    }
    /** A folder that can be written into nginx config (env.conf map): no $ (variable), quotes, ; { } # or line breaks. */
    public static function nginxSafePath(string $value): bool
    {
        return !preg_match('/["\';$#{}\r\n]/', $value);
    }
}
