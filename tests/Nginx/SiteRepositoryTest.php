<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : SiteRepositoryTest.php
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
 * File: tests/Nginx/SiteRepositoryTest.php
 * Purpose: The safe-write contract for site files: backup -> write -> nginx -t ->
 *          rollback on failure; ids can never escape conf/sites.
 * Notes: runs against a temp {base}; nginx is faked (FakeNginx) so no binary is needed.
 */

declare(strict_types=1);

use NginxManager\Nginx\NginxControl;
use NginxManager\Nginx\SiteConfigBuilder;
use NginxManager\Nginx\SiteRepository;
use NginxManager\Nginx\SiteSpec;
use NginxManager\Support\Paths;
use NginxManager\Support\Shell;
use NginxManager\Support\ShellResult;

final class FakeNginx extends NginxControl
{
    public bool $valid = true;
    public int $tests = 0;

    public function __construct() { parent::__construct(new Shell()); }

    public function test(): ShellResult
    {
        $this->tests++;
        return $this->valid ? new ShellResult(0, 'syntax is ok') : new ShellResult(1, 'emerg: unexpected "}"');
    }

    public function isRunning(): bool { return true; }
}

function repoFixture(): array
{
    $base = tempDir();
    Paths::overrideBase($base);
    mkdir($base . '/conf/sites', 0777, true);
    file_put_contents($base . '/conf/sites/api.example.test.conf', fixture('sites/api.example.test.conf'));
    file_put_contents($base . '/conf/sites/_template.conf.example', 'x');
    $nginx = new FakeNginx();
    return [$base, $nginx, new SiteRepository($nginx)];
}

test('repository lists sites and skips templates', function () {
    [, , $repo] = repoFixture();
    $ids = array_map(fn($s) => $s->id, $repo->all());
    assertSame(['api.example.test'], $ids);
    assertTrue($repo->find('api.example.test')->enabled);
});

test('repository rolls back when nginx -t fails and keeps a backup', function () {
    [$base, $nginx, $repo] = repoFixture();
    $original = fixture('sites/api.example.test.conf');
    $nginx->valid = false;
    $result = $repo->save('api.example.test', 'server { broken');
    assertSame(1, $result->exitCode);
    assertContains('unexpected', $result->output);
    assertSame($original, file_get_contents($base . '/conf/sites/api.example.test.conf'));
    assertSame(1, count($repo->backups('api.example.test')));
});

test('repository saves valid content', function () {
    [$base, , $repo] = repoFixture();
    $new = str_replace('php83', 'php84', fixture('sites/api.example.test.conf'));
    assertTrue($repo->save('api.example.test', $new)->ok());
    assertSame($new, file_get_contents($base . '/conf/sites/api.example.test.conf'));
    assertSame('php84', $repo->find('api.example.test')->parsed->upstream);
});

test('repository refuses ids outside conf/sites', function () {
    [, , $repo] = repoFixture();
    assertSame(null, $repo->find('../nginx'));
    assertSame(null, $repo->find('nope.example.test'));
    assertThrows(RuntimeException::class, fn() => $repo->save('../nginx', 'x'));
});

test('repository creates a site, refuses duplicates, removes it again when nginx -t fails', function () {
    [$base, $nginx, $repo] = repoFixture();
    $spec = new SiteSpec('new.example.test', [], '$projects_dir/new', '8.3', 'php83', 'general', false, null, null);
    assertTrue($repo->create($spec)->ok());
    assertTrue(is_file($base . '/conf/sites/new.example.test.conf'));
    assertThrows(RuntimeException::class, fn() => $repo->create($spec), 'already exists');

    $nginx->valid = false;
    $bad = new SiteSpec('bad.example.test', [], '$projects_dir/bad', '8.3', 'php83', 'general', false, null, null);
    assertSame(1, $repo->create($bad)->exitCode);
    assertTrue(!is_file($base . '/conf/sites/bad.example.test.conf'));
});

test('wildcard domains get a file-system safe id', function () {
    [$base, , $repo] = repoFixture();
    $spec = new SiteSpec('*.example.test', [], '$projects_dir/w', '8.3', 'php83', 'general', false, null, null);
    $repo->create($spec);
    assertTrue(is_file($base . '/conf/sites/wildcard.example.test.conf'));
    assertSame('wildcard.example.test', SiteRepository::idFor('*.example.test'));
});

test('disable and enable rename the file; enabling a broken site is rolled back', function () {
    [$base, $nginx, $repo] = repoFixture();
    $repo->setEnabled('api.example.test', false);
    assertTrue(is_file($base . '/conf/sites/api.example.test.conf.disabled'));
    assertTrue(!$repo->find('api.example.test')->enabled);

    $nginx->valid = false;
    assertSame(1, $repo->setEnabled('api.example.test', true)->exitCode);
    assertTrue(is_file($base . '/conf/sites/api.example.test.conf.disabled'));
});

test('delete moves the file to backups/deleted', function () {
    [$base, , $repo] = repoFixture();
    $repo->delete('api.example.test');
    assertSame(null, $repo->find('api.example.test'));
    assertSame(1, count(glob($base . '/backups/deleted/api.example.test-*.conf')));
});

test('backup content can be read back by name, names are validated', function () {
    [, , $repo] = repoFixture();
    $repo->save('api.example.test', fixture('sites/api.example.test.conf'));
    $name = $repo->backups('api.example.test')[0];
    assertContains('server {', $repo->backupContent('api.example.test', $name));
    assertThrows(RuntimeException::class, fn() => $repo->backupContent('api.example.test', '../../.env'));
});
