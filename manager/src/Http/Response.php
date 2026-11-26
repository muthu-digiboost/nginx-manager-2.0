<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : Response.php
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
 * File: manager/src/Http/Response.php
 * Purpose: What a controller returns: status, headers, body.
 * Responsibilities: html(), redirect() (303 See Other after POST), json(), send()
 * Used by: Router, controllers, public/index.php
 */

declare(strict_types=1);

namespace NginxManager\Http;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {}

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function redirect(string $to): self
    {
        return new self('', 303, ['Location' => $to]);
    }

    public static function json(array $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}',
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store'],
        );
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
