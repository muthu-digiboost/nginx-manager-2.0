<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : Csrf.php
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
 * File: manager/src/Http/Csrf.php
 * Purpose: Cross-site request forgery protection for every POST.
 * Responsibilities:
 *   - one random token per session (Csrf::token(), printed by csrf_field())
 *   - verify(): constant-time compare, throws CsrfException on mismatch
 * Used by: public/index.php (all POSTs), helpers.php
 * Notes: the manager runs as Administrator; together with the Host check in index.php
 *        and the 127.0.0.1-only listener this stops other websites from driving it.
 */

declare(strict_types=1);

namespace NginxManager\Http;

final class Csrf
{
    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function verify(?string $token): void
    {
        $expected = $_SESSION['_csrf'] ?? '';
        if ($expected === '' || !is_string($token) || !hash_equals($expected, $token)) {
            throw new CsrfException('Your form expired or came from another site. Reload the page and try again.');
        }
    }
}
