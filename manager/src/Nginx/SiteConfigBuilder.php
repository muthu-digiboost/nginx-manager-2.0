<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : SiteConfigBuilder.php
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
 * File: manager/src/Nginx/SiteConfigBuilder.php
 * Purpose: Generates a complete, new site config from a SiteSpec.
 * Responsibilities:
 *   - same proven layout as v1's generator (header comment, per-framework try_files,
 *     WordPress hardening, per-site logs, HTTP->HTTPS redirect block when SSL)
 *   - v2 rules: root via $projects_dir variables, certificates relative to conf/,
 *     never an absolute path
 * Used by: SiteRepository::create(), "New Site" form
 */

declare(strict_types=1);

namespace NginxManager\Nginx;

use InvalidArgumentException;

final class SiteConfigBuilder
{
    /** framework key => label shown in the UI */
    public const FRAMEWORKS = [
        'general'     => 'General (custom MVC / plain PHP)',
        'laravel'     => 'Laravel',
        'symfony'     => 'Symfony',
        'wordpress'   => 'WordPress',
        'codeigniter' => 'CodeIgniter',
    ];

    private const TRY_FILES = [
        'general'     => 'try_files $uri $uri/ /index.php;',
        'laravel'     => 'try_files $uri $uri/ /index.php?$query_string;',
        'symfony'     => 'try_files $uri /index.php$is_args$args;',
        'wordpress'   => 'try_files $uri $uri/ /index.php?$args;',
        'codeigniter' => 'try_files $uri $uri/ /index.php?/$request_uri;',
    ];

    private const WORDPRESS_EXTRA = <<<'CONF'

    # WordPress: never execute PHP uploaded into the media folders
    location ~* /(?:uploads|files)/.*\.php$ {
        deny all;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|webp|woff|woff2|ttf|eot)$ {
        expires max;
        log_not_found off;
    }

CONF;

    public static function build(SiteSpec $s): string
    {
        if (!isset(self::FRAMEWORKS[$s->framework])) {
            throw new InvalidArgumentException("Unknown framework: {$s->framework}");
        }
        if ($s->ssl && ($s->certPath === null || $s->keyPath === null)) {
            throw new InvalidArgumentException('SSL needs a certificate and key path.');
        }

        $label = self::logLabel($s->domain);
        $names = implode(' ', array_merge([$s->domain], $s->aliases));
        $mode = $s->ssl ? 'SSL enabled' : 'HTTP only';

        $out  = "# ──────────────────────────────────────────────\n";
        $out .= "#  {$s->domain} → PHP {$s->phpId} ({$mode})\n";
        $out .= "#  Framework: {$s->framework} | Project: {$s->root}\n";
        $out .= "#  Managed by Nginx Manager 2.0 (Sites page) - hand edits are kept.\n";
        $out .= "# ──────────────────────────────────────────────\n";
        $out .= "server {\n";
        $out .= '    listen       ' . ($s->ssl ? '443 ssl' : '80') . ";\n";
        $out .= "    server_name  {$names};\n";
        if ($s->ssl) {
            $out .= "\n    ssl_certificate      {$s->certPath};\n";
            $out .= "    ssl_certificate_key  {$s->keyPath};\n";
        }
        $out .= "\n    root  {$s->root};\n";
        $out .= "    index index.php index.html;\n\n";
        $out .= "    location / {\n        " . self::TRY_FILES[$s->framework] . "\n    }\n\n";
        $out .= "    location ~ \\.php\$ {\n";
        $out .= "        fastcgi_pass   {$s->upstream};\n";
        $out .= "        fastcgi_index  index.php;\n";
        $out .= "        fastcgi_param  SCRIPT_FILENAME \$document_root\$fastcgi_script_name;\n";
        $out .= "        include        fastcgi_params;\n";
        $out .= "    }\n";
        if ($s->framework === 'wordpress') {
            $out .= rtrim(self::WORDPRESS_EXTRA) . "\n";
        }
        $out .= "\n    location ~ /\\.(ht|git|env) {\n        deny all;\n    }\n\n";
        $out .= "    error_log   logs/{$label}-error.log;\n";
        $out .= "    access_log  logs/{$label}-access.log;\n";
        $out .= "}\n";
        if ($s->ssl) {
            $out .= "\n" . self::redirectBlock($names);
        }
        return $out;
    }

    public static function redirectBlock(string $serverNames): string
    {
        return "# HTTP → HTTPS redirect\nserver {\n    listen      80;\n    server_name {$serverNames};\n    return 301  https://\$host\$request_uri;\n}\n";
    }

    /** "*.example.test" -> "wildcard.example.test" (log and file names). */
    public static function logLabel(string $domain): string
    {
        return str_replace('*', 'wildcard', $domain);
    }
}
