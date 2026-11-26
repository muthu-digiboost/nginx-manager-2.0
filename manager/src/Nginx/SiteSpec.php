<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : SiteSpec.php
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
 * File: manager/src/Nginx/SiteSpec.php
 * Purpose: Everything needed to generate a NEW site config (the "New Site" form).
 * Notes:
 *   - $root is already in nginx form: "$projects_dir/app/public" (see Paths::toProjectRelative)
 *   - $certPath / $keyPath are conf-relative: "../ssl/<domain>/ssl.crt/server.crt"
 *   - $upstream is the PHP upstream name (php83), $phpId the folder id (8.3)
 */

declare(strict_types=1);

namespace NginxManager\Nginx;

final class SiteSpec
{
    /** @param list<string> $aliases */
    public function __construct(
        public readonly string $domain,
        public readonly array $aliases,
        public readonly string $root,
        public readonly string $phpId,
        public readonly string $upstream,
        public readonly string $framework,
        public readonly bool $ssl,
        public readonly ?string $certPath,
        public readonly ?string $keyPath,
    ) {}
}
