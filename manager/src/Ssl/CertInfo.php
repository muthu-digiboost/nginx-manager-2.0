<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : CertInfo.php
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
 * File: manager/src/Ssl/CertInfo.php
 * Purpose: One certificate as shown on the SSL Certificates page.
 * Notes: $domain is the folder name under ssl/ (wildcard domains: "wildcard.<domain>").
 */

declare(strict_types=1);

namespace NginxManager\Ssl;

final class CertInfo
{
    /**
     * @param list<string> $sans    DNS names the certificate covers
     * @param list<string> $usedBy  site ids whose config points to this certificate
     */
    public function __construct(
        public readonly string $domain,
        public readonly string $commonName,
        public readonly array $sans,
        public readonly ?int $notAfter,
        public readonly ?int $daysLeft,
        public readonly ?string $serialHex,
        public readonly bool $trusted,
        public readonly array $usedBy,
    ) {}

    /** ok | soon (< 30 days) | expired | unknown */
    public function status(): string
    {
        return match (true) {
            $this->daysLeft === null => 'unknown',
            $this->daysLeft < 0      => 'expired',
            $this->daysLeft < 30     => 'soon',
            default                  => 'ok',
        };
    }
}
