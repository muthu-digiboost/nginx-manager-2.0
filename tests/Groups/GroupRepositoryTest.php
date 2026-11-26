<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : GroupRepositoryTest.php
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
 * File: tests/Groups/GroupRepositoryTest.php
 * Purpose: Site groups (data/groups.json): create/rename/delete, assignment, and the
 *          first-run suggestion that groups sites by their parent domain.
 */

declare(strict_types=1);

use NginxManager\Groups\GroupRepository;

function groups(): GroupRepository
{
    return new GroupRepository(tempDir() . '/groups.json');
}

test('create gives a slug id, rejects duplicate names and bad colours', function () {
    $g = groups();
    $a = $g->create('Alpha Clients', '#3b82f6');
    assertSame('alpha-clients', $a['id']);
    assertThrows(InvalidArgumentException::class, fn() => $g->create('alpha clients'), 'already exists');
    assertThrows(InvalidArgumentException::class, fn() => $g->create('Beta', 'red'));
    assertThrows(InvalidArgumentException::class, fn() => $g->create(''));
    assertSame(['Alpha Clients'], array_column($g->all(), 'name'));
});

test('same slug from different names gets a numeric suffix', function () {
    $g = groups();
    $g->create('a.test');
    assertSame('a-test-2', $g->create('a-test')['id']);
});

test('assign, groupOf, delete unassigns, forgetSite', function () {
    $g = groups();
    $id = $g->create('Clients')['id'];
    $g->assign('api.example.test', $id);
    assertSame($id, $g->groupOf('api.example.test'));
    $g->assign('www.example.test', $id);
    $g->forgetSite('www.example.test');
    assertSame(null, $g->groupOf('www.example.test'));
    $g->delete($id);
    assertSame(null, $g->groupOf('api.example.test'));
    assertSame([], $g->all());
    assertThrows(InvalidArgumentException::class, fn() => $g->assign('x.test', 'nope'));
});

test('update renames and recolours', function () {
    $g = groups();
    $id = $g->create('Old')['id'];
    $g->update($id, 'New name', '#00a32a');
    assertSame(['New name', '#00a32a'], [$g->find($id)['name'], $g->find($id)['color']]);
});

test('seed groups sites by parent domain only once', function () {
    $g = groups();
    assertSame(3, $g->seedFromSites(['api.alpha.test', 'www.alpha.test', 'one.beta.test', 'gamma-dev.test']));
    assertSame(['alpha.test', 'beta.test', 'gamma-dev.test'], array_column($g->all(), 'name'));
    assertSame('alpha-test', $g->groupOf('www.alpha.test'));
    assertSame(0, $g->seedFromSites(['api.alpha.test']));
});
