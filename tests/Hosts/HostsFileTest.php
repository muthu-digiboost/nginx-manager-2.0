<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : HostsFileTest.php
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
 * File: tests/Hosts/HostsFileTest.php
 * Purpose: The Windows hosts file is shared with everything else on the machine, so the
 *          manager only ever removes lines it added itself (marked "# nginx-manager").
 */

declare(strict_types=1);

use NginxManager\Hosts\HostsFile;

const HOSTS_SAMPLE = "# Copyright (c) Microsoft\r\n127.0.0.1 api.example.test\r\n# 127.0.0.1 old.example.test\r\n";

test('hosts add/has/remove only touches our marker lines', function () {
    $h = new HostsFile(tempCopy(HOSTS_SAMPLE, 'hosts'));
    assertTrue($h->has('api.example.test'));
    $h->add('demo.example.test');
    assertTrue($h->has('demo.example.test'));
    $h->remove('api.example.test');                      // not ours -> kept
    assertTrue($h->has('api.example.test'));
    $h->remove('demo.example.test');
    assertTrue(!$h->has('demo.example.test'));
});

test('hosts ignores commented entries and keeps CRLF line endings', function () {
    $path = tempCopy(HOSTS_SAMPLE, 'hosts');
    $h = new HostsFile($path);
    assertTrue(!$h->has('old.example.test'));
    $h->add('new.example.test');
    assertContains("127.0.0.1\tnew.example.test\t# nginx-manager\r\n", (string) file_get_contents($path));
    assertTrue(str_starts_with((string) file_get_contents($path), "# Copyright (c) Microsoft\r\n"));
});

test('hosts add is idempotent and rejects invalid names', function () {
    $path = tempCopy(HOSTS_SAMPLE, 'hosts');
    $h = new HostsFile($path);
    $h->add('api.example.test');                         // already mapped -> nothing added
    assertSame(1, substr_count((string) file_get_contents($path), 'api.example.test'));
    assertThrows(InvalidArgumentException::class, fn() => $h->add('evil.test 1.2.3.4 bank.test'));
    assertThrows(InvalidArgumentException::class, fn() => $h->add('*.example.test'));
});

test('hosts reports a friendly error when the file is not writable', function () {
    $h = new HostsFile(tempDir() . '/missing-dir/hosts');
    assertThrows(RuntimeException::class, fn() => $h->add('demo.example.test'), 'Administrator');
});

test('macOS: the hosts file is replaced through the admin password dialog', function () {
    $cmd = HostsFile::adminCopyCommand('/Users/me/nm "2"/temp/hosts.tmp', '/etc/hosts');
    assertSame('osascript', $cmd[0]);
    assertSame('-e', $cmd[1]);
    assertSame('do shell script "cp " & quoted form of "/Users/me/nm \"2\"/temp/hosts.tmp" & " " & quoted form of "/etc/hosts"'
        . ' with prompt "Nginx Manager needs to update /etc/hosts." with administrator privileges', $cmd[2]);
});
