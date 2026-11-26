<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : PhpCatalogTest.php
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
 * File: tests/Php/PhpCatalogTest.php
 * Purpose: Where PHP versions come from:
 *   - PhpReleaseFeed   releases.json (supported branches, latest patch, sha256)
 *   - PhpArchiveCatalog the archives/ directory listing (every old version, 5.5+)
 *   - PhpCatalog::merge one list per branch, newest first, EOL flagged
 *   - UpdateChecker     which installed versions have an update
 * Fixtures are trimmed copies of the real php.net files (tests/fixtures/php).
 */

declare(strict_types=1);

use NginxManager\Php\PhpArchiveCatalog;
use NginxManager\Php\PhpCatalog;
use NginxManager\Php\PhpReleaseFeed;
use NginxManager\Php\UpdateChecker;
use NginxManager\Support\Paths;

function releaseFeed(?callable $fetch = null): PhpReleaseFeed
{
    Paths::overrideBase(tempDir());
    return new PhpReleaseFeed('https://example.test/releases.json', $fetch ?? fn() => fixture('php/releases.json'), 0);
}

// -- release feed --------------------------------------------------------------------

test('feed lists supported branches with ts and nts x64 builds and checksums', function () {
    $r = releaseFeed()->releases();
    assertSame(['8.6', '8.4', '8.3', '7.4'], array_keys($r));
    assertSame('8.3.37', $r['8.3']['version']);
    assertSame('php-8.3.37-Win32-vs16-x64.zip', $r['8.3']['builds']['ts']['zip']);
    assertSame('php-8.3.37-nts-Win32-vs16-x64.zip', $r['8.3']['builds']['nts']['zip']);
    assertSame('vs16', $r['8.3']['builds']['ts']['compiler']);
    assertSame('vc15', $r['7.4']['builds']['ts']['compiler']);
    assertSame(64, strlen($r['7.4']['builds']['ts']['sha256']));
});

test('feed falls back to the stale cache when the network fails', function () {
    $calls = 0;
    $feed = releaseFeed(function () use (&$calls) {
        if ($calls++ > 0) {
            throw new RuntimeException('offline');
        }
        return fixture('php/releases.json');
    });
    $first = $feed->releases(true);
    $second = $feed->releases(true);
    assertSame(array_keys($first), array_keys($second));
    assertContains('offline', (string) $feed->lastError());
});

test('cachedOnly never touches the network', function () {
    $feed = releaseFeed(fn() => throw new RuntimeException('must not fetch'));
    assertSame([], $feed->cachedOnly());
});

// -- archive catalog -------------------------------------------------------------------

test('archive listing is parsed into x64 builds per version', function () {
    Paths::overrideBase(tempDir());
    $a = (new PhpArchiveCatalog('https://example.test/archives/', fn() => fixture('php/archives.html'), 0))->versions();
    // (php.net never published a Windows build of 5.6.39, so the real listing jumps 5.6.40 -> 5.5.38 here)
    assertSame(['8.3.1', '8.3.0', '7.2.34', '5.6.40', '5.5.38'], array_keys($a));
    assertSame('php-5.6.40-Win32-VC11-x64.zip', $a['5.6.40']['ts']['zip']);
    assertSame('php-5.6.40-nts-Win32-VC11-x64.zip', $a['5.6.40']['nts']['zip']);
    assertSame('vc11', $a['5.6.40']['ts']['compiler']);
    assertSame(null, $a['5.6.40']['ts']['sha256']);
});

// -- merged catalog ----------------------------------------------------------------------

test('merge gives one list per branch, newest first, with location and EOL flag', function () {
    Paths::overrideBase(tempDir());
    $releases = (new PhpReleaseFeed('x', fn() => fixture('php/releases.json'), 0))->releases();
    $archives = (new PhpArchiveCatalog('y', fn() => fixture('php/archives.html'), 0))->versions();
    $c = PhpCatalog::merge($releases, $archives);

    assertSame(['8.6', '8.4', '8.3', '7.4', '7.2', '5.6', '5.5'], array_keys($c));
    assertSame(['8.3.37', '8.3.1', '8.3.0'], array_keys($c['8.3']['versions']));
    assertSame('releases', $c['8.3']['versions']['8.3.37']['location']);
    assertSame('archives', $c['8.3']['versions']['8.3.0']['location']);
    assertSame(false, $c['8.3']['eol']);
    assertSame(true, $c['5.6']['eol']);
    assertSame('5.6.40', $c['5.6']['latest']);
    assertSame(64, strlen((string) $c['8.3']['versions']['8.3.37']['builds']['ts']['sha256']));
});

// -- updates -------------------------------------------------------------------------------

test('update checker classifies installed versions', function () {
    Paths::overrideBase(tempDir());
    $releases = (new PhpReleaseFeed('x', fn() => fixture('php/releases.json'), 0))->releases();
    $installed = [
        ['id' => '7.2', 'branch' => '7.2', 'version' => '7.2.34', 'threadSafety' => 'ts'],
        ['id' => '8.3', 'branch' => '8.3', 'version' => '8.3.0', 'threadSafety' => 'ts'],
        ['id' => '8.4', 'branch' => '8.4', 'version' => '8.4.28', 'threadSafety' => 'ts'],
        ['id' => '8.5.12', 'branch' => '8.5', 'version' => '8.5.12', 'threadSafety' => 'ts'],
    ];
    $res = UpdateChecker::compare($installed, $releases);
    assertSame(['8.3'], array_column($res['updates'], 'id'));
    assertSame('8.3.37', $res['updates'][0]['latest']);
    assertSame(['8.4'], array_column($res['current'], 'id'));
    assertSame(['7.2'], array_column($res['eol'], 'id'));
    assertSame(['8.5.12'], array_column($res['pinned'], 'id'));   // patch folders are kept as they are
});
