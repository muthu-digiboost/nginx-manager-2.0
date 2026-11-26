<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : Site.php
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
 * File: manager/src/Nginx/Site.php
 * Purpose: One site as listed by SiteRepository: its id (file name without
 *          .conf / .conf.disabled), file path, enabled flag and parsed contents.
 */

declare(strict_types=1);

namespace NginxManager\Nginx;

final class Site
{
    public function __construct(
        public readonly string $id,
        public readonly string $file,
        public readonly bool $enabled,
        public readonly ParsedSite $parsed,
    ) {}

    public function domain(): string
    {
        return $this->parsed->primaryDomain() ?? $this->id;
    }
}
