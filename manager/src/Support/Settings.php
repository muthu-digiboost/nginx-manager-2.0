<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : Settings.php
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
 * File: manager/src/Support/Settings.php
 * Purpose: Typed access to data/settings.json - the application state shared with the
 *          PowerShell scripts (installed PHP versions, their ports, the manager's PHP).
 * Responsibilities:
 *   - load / fromArray (tests)
 *   - phpVersions(), phpVersion($id), managerVersion(), get('dot.path')
 *   - update(callable): read-modify-write through JsonStore
 * Used by: controllers, NginxConfigWriter, PhpVersionRepository, bin/manager.php
 * Notes: machine-specific values (paths, ports of nginx/manager) are in .env, not here.
 */

declare(strict_types=1);

namespace NginxManager\Support;

final class Settings
{
    private function __construct(private array $data, private ?string $file) {}

    public static function load(): self
    {
        $file = Paths::in('data', 'settings.json');
        return new self(JsonStore::read($file, ['php' => ['versions' => []]]), $file);
    }

    public static function fromArray(array $data): self
    {
        return new self($data, null);
    }

    /** @return list<array{id:string, port:int, upstream:string, version:string, threadSafety?:string}> */
    public function phpVersions(): array
    {
        $list = $this->data['php']['versions'] ?? [];
        return array_values(array_filter($list, 'is_array'));
    }

    public function phpVersion(string $id): ?array
    {
        foreach ($this->phpVersions() as $v) {
            if (($v['id'] ?? null) === $id) {
                return $v;
            }
        }
        return null;
    }

    public function managerVersion(): string
    {
        return (string) ($this->data['php']['managerVersion'] ?? '');
    }

    public function get(string $dotPath, mixed $default = null): mixed
    {
        $node = $this->data;
        foreach (explode('.', $dotPath) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }
        return $node;
    }

    /** $mutator receives the data array by reference; the result is saved. */
    public function update(callable $mutator): void
    {
        if ($this->file === null) {
            $mutator($this->data);
            return;
        }
        // Re-read and write under one lock: picks up changes made by PowerShell scripts.
        $this->data = JsonStore::update($this->file, function (array &$d) use ($mutator) {
            $mutator($d);
        }, $this->data);
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
