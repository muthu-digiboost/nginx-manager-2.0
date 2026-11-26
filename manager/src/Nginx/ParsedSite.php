<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : ParsedSite.php
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
 * File: manager/src/Nginx/ParsedSite.php
 * Purpose: What the UI needs to know about one site config, read by SiteConfigParser.
 * Notes: values are exactly as written in the file (e.g. root "$projects_dir/x",
 *        cert "../ssl/..."); use Paths::resolveRoot() for absolute display paths.
 */

declare(strict_types=1);

namespace NginxManager\Nginx;

final class ParsedSite
{
    /** @param list<string> $serverNames */
    public function __construct(
        public readonly array $serverNames,
        public readonly ?string $root,
        public readonly ?string $upstream,
        public readonly bool $ssl,
        public readonly ?string $certPath,
        public readonly ?string $keyPath,
        public readonly ?string $framework,
        public readonly ?string $projectPath,
        public readonly ?string $errorLog,
        public readonly ?string $accessLog,
        public readonly bool $hasRedirectBlock,
    ) {}

    public function primaryDomain(): ?string
    {
        return $this->serverNames[0] ?? null;
    }
}
