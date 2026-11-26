<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : GroupRepository.php
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
 * File: manager/src/Groups/GroupRepository.php
 * Purpose: Site groups - e.g. one group per client or per product - stored in
 *          data/groups.json (machine data, gitignored):
 *            { "groups": [ {"id", "name", "color"} ], "assignments": { "<site id>": "<group id>" } }
 * Responsibilities:
 *   - all / find / create / update / delete (deleting a group un-assigns its sites)
 *   - assign(site, group|null), groupOf(site), forgetSite(site) (site deleted)
 *   - seedFromSites(ids): first run only - one group per parent domain
 *     ("api.bpmc.net" -> "bpmc.net") so existing sites arrive already grouped
 * Used by: SitesController, GroupsController, bin/manager.php groups:seed
 */

declare(strict_types=1);

namespace NginxManager\Groups;

use InvalidArgumentException;
use NginxManager\Support\JsonStore;
use NginxManager\Support\Paths;

final class GroupRepository
{
    public const COLORS = ['#2271b1', '#00a32a', '#d63638', '#dba617', '#8c5fc7', '#e26f00', '#0a9396', '#646970'];

    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? Paths::in('data', 'groups.json');
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /** @return list<array{id:string, name:string, color:string}> ordered by name */
    public function all(): array
    {
        $groups = $this->data()['groups'];
        usort($groups, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $groups;
    }

    public function find(string $id): ?array
    {
        foreach ($this->data()['groups'] as $g) {
            if ($g['id'] === $id) {
                return $g;
            }
        }
        return null;
    }

    public function create(string $name, ?string $color = null): array
    {
        $data = $this->data();
        $name = $this->validName($name, $data['groups'], null);
        $color = $this->validColor($color ?? self::COLORS[count($data['groups']) % count(self::COLORS)]);

        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'group';
        $id = $slug;
        $taken = array_column($data['groups'], 'id');
        for ($n = 2; in_array($id, $taken, true); $n++) {
            $id = "$slug-$n";
        }
        $group = ['id' => $id, 'name' => $name, 'color' => $color];
        $data['groups'][] = $group;
        $this->save($data);
        return $group;
    }

    public function update(string $id, string $name, string $color): void
    {
        $data = $this->data();
        $name = $this->validName($name, $data['groups'], $id);
        $color = $this->validColor($color);
        $found = false;
        foreach ($data['groups'] as &$g) {
            if ($g['id'] === $id) {
                [$g['name'], $g['color'], $found] = [$name, $color, true];
            }
        }
        unset($g);
        if (!$found) {
            throw new InvalidArgumentException('Group not found.');
        }
        $this->save($data);
    }

    public function delete(string $id): void
    {
        $data = $this->data();
        $data['groups'] = array_values(array_filter($data['groups'], static fn($g) => $g['id'] !== $id));
        $data['assignments'] = array_filter($data['assignments'], static fn($gid) => $gid !== $id);
        $this->save($data);
    }

    public function assign(string $siteId, ?string $groupId): void
    {
        $data = $this->data();
        if ($groupId === null || $groupId === '') {
            unset($data['assignments'][$siteId]);
        } else {
            if (!in_array($groupId, array_column($data['groups'], 'id'), true)) {
                throw new InvalidArgumentException('Group not found.');
            }
            $data['assignments'][$siteId] = $groupId;
        }
        $this->save($data);
    }

    public function groupOf(string $siteId): ?string
    {
        return $this->data()['assignments'][$siteId] ?? null;
    }

    /** @return array<string, string> site id => group id */
    public function assignments(): array
    {
        return $this->data()['assignments'];
    }

    public function forgetSite(string $siteId): void
    {
        $this->assign($siteId, null);
    }

    /** @param list<string> $siteIds @return int groups created (0 when groups.json already existed) */
    public function seedFromSites(array $siteIds): int
    {
        if ($this->exists()) {
            return 0;
        }
        $this->save(['groups' => [], 'assignments' => []]);
        $created = 0;
        $byName = [];
        foreach ($siteIds as $site) {
            $labels = explode('.', $site);
            $parent = count($labels) >= 2 ? implode('.', array_slice($labels, -2)) : $site;
            if (!isset($byName[$parent])) {
                $byName[$parent] = $this->create($parent)['id'];
                $created++;
            }
            $this->assign($site, $byName[$parent]);
        }
        return $created;
    }

    // -------------------------------------------------------------------------

    private function data(): array
    {
        $data = JsonStore::read($this->path, ['groups' => [], 'assignments' => []]);
        $data['groups'] = array_values(array_filter($data['groups'] ?? [], 'is_array'));
        $data['assignments'] = is_array($data['assignments'] ?? null) ? $data['assignments'] : [];
        return $data;
    }

    private function save(array $data): void
    {
        JsonStore::write($this->path, $data);
    }

    private function validName(string $name, array $groups, ?string $exceptId): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 40) {
            throw new InvalidArgumentException('Group name must be 1 to 40 characters.');
        }
        foreach ($groups as $g) {
            if ($g['id'] !== $exceptId && strcasecmp($g['name'], $name) === 0) {
                throw new InvalidArgumentException("A group named \"$name\" already exists.");
            }
        }
        return $name;
    }

    private function validColor(string $color): string
    {
        $color = strtolower(trim($color));
        if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
            throw new InvalidArgumentException('Colour must look like #2271b1.');
        }
        return $color;
    }
}
