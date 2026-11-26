<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : Timezone.php
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
 * File: manager/src/Support/Timezone.php
 * Purpose: Which time zone the manager shows dates in.
 * Responsibilities:
 *   - configured(): APP_TIMEZONE from .env when it is a valid zone, otherwise the
 *     Windows time zone of this PC (tzutil /g) mapped to its IANA name, otherwise UTC
 *   - fromWindows(): Windows zone name -> IANA name for the common zones
 * Used by: bootstrap.php (date_default_timezone_set), SettingsController
 * Notes: PHP on Windows defaults to UTC, which made job times look 5.5 h off in India.
 */

declare(strict_types=1);

namespace NginxManager\Support;

final class Timezone
{
    private const WINDOWS = [
        'India Standard Time'           => 'Asia/Kolkata',
        'Sri Lanka Standard Time'       => 'Asia/Colombo',
        'Nepal Standard Time'           => 'Asia/Kathmandu',
        'Bangladesh Standard Time'      => 'Asia/Dhaka',
        'Arabian Standard Time'         => 'Asia/Dubai',
        'Arab Standard Time'            => 'Asia/Riyadh',
        'Singapore Standard Time'       => 'Asia/Singapore',
        'China Standard Time'           => 'Asia/Shanghai',
        'Tokyo Standard Time'           => 'Asia/Tokyo',
        'AUS Eastern Standard Time'     => 'Australia/Sydney',
        'GMT Standard Time'             => 'Europe/London',
        'W. Europe Standard Time'       => 'Europe/Berlin',
        'Romance Standard Time'         => 'Europe/Paris',
        'Central Europe Standard Time'  => 'Europe/Budapest',
        'Eastern Standard Time'         => 'America/New_York',
        'Central Standard Time'         => 'America/Chicago',
        'Mountain Standard Time'        => 'America/Denver',
        'Pacific Standard Time'         => 'America/Los_Angeles',
        'UTC'                           => 'UTC',
        'Coordinated Universal Time'    => 'UTC',
    ];

    public static function fromWindows(string $name): string
    {
        return self::WINDOWS[trim($name)] ?? 'UTC';
    }

    /** macOS: /etc/localtime links to ".../zoneinfo/<Area>/<City>". */
    public static function fromLocaltimeLink(string $target): string
    {
        if (preg_match('~zoneinfo/(.+)$~', $target, $m) && in_array($m[1], timezone_identifiers_list(), true)) {
            return $m[1];
        }
        return 'UTC';
    }

    /**
     * @param callable():string|null $windowsZone  returns the Windows zone name (tests inject it)
     * @param callable():string|null $macLink      returns the /etc/localtime link target (tests inject it)
     */
    public static function configured(?callable $windowsZone = null, ?callable $macLink = null): string
    {
        $set = (string) Env::get('APP_TIMEZONE', '');
        if ($set !== '' && in_array($set, timezone_identifiers_list(), true)) {
            return $set;
        }
        if (!Platform::isWindows()) {
            $macLink ??= static fn(): string => (string) @readlink('/etc/localtime');
            return self::fromLocaltimeLink($macLink());
        }
        $windowsZone ??= static fn(): string => (new Shell())->run(['tzutil', '/g'], null, 5)->output;
        return self::fromWindows($windowsZone());
    }
}
