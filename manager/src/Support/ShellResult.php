<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : ShellResult.php
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
 * File: manager/src/Support/ShellResult.php
 * Purpose: Value object for the outcome of an external command.
 * Used by: Shell and everything that runs nginx/openssl/certutil/php.exe
 */

declare(strict_types=1);

namespace NginxManager\Support;

final class ShellResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
    ) {}

    public function ok(): bool
    {
        return $this->exitCode === 0;
    }
}
