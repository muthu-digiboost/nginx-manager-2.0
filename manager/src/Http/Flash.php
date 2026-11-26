<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : Flash.php
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
 * File: manager/src/Http/Flash.php
 * Purpose: One-time messages shown on the next page after a redirect (POST -> 303 -> GET).
 * Responsibilities: add(type, message, details?) / pull()
 *   type: success | error | warn | info ; details: optional preformatted text
 *   (e.g. the output of "nginx -t") shown in a collapsible block
 * Used by: controllers, views/partials/flash.php
 */

declare(strict_types=1);

namespace NginxManager\Http;

final class Flash
{
    public static function add(string $type, string $message, string $details = ''): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message, 'details' => $details];
    }

    /** @return list<array{type:string, message:string, details:string}> */
    public static function pull(): array
    {
        $messages = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return is_array($messages) ? $messages : [];
    }
}
