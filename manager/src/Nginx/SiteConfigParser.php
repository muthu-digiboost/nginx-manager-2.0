<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : SiteConfigParser.php
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
 * File: manager/src/Nginx/SiteConfigParser.php
 * Purpose: Reads a conf/sites/*.conf file into a ParsedSite.
 * Responsibilities:
 *   - main block = first server block that is not a pure HTTP->HTTPS redirect
 *   - server_name, root, ssl (listen ... ssl), certificate paths and logs from the main
 *     block; fastcgi_pass from anywhere inside it (it lives in "location ~ \.php$")
 *   - framework + project from the generated header comment
 *     ("#  Framework: x | Project: y"); v1's "generic" means Laravel
 * Used by: SiteRepository, SiteConfigEditor tests, controllers
 */

declare(strict_types=1);

namespace NginxManager\Nginx;

final class SiteConfigParser
{
    public static function parse(string $conf): ParsedSite
    {
        $doc = new ConfigDocument($conf);
        $main = $doc->mainIndex();

        $first = static function (string $name, bool $top = true) use ($doc, $main): ?string {
            if ($main === null) {
                return null;
            }
            $s = $doc->statements($main, $name, $top);
            return $s ? ConfigDocument::unquote($s[0]['value']) : null;
        };
        $firstWord = static fn(?string $v): ?string => $v === null ? null : (preg_split('/\s+/', $v)[0] ?? null);

        $ssl = false;
        if ($main !== null) {
            foreach ($doc->statements($main, 'listen') as $listen) {
                if (preg_match('/(^|\s)ssl(\s|$)/', $listen['value'])) {
                    $ssl = true;
                }
            }
        }

        $hasRedirect = false;
        foreach ($doc->servers as $server) {
            $hasRedirect = $hasRedirect || $doc->isRedirectBlock($server);
        }

        $framework = null;
        $project = null;
        if (preg_match('/^#.*Framework:\s*([a-z0-9_-]+)/mi', $conf, $m)) {
            $framework = strtolower($m[1]) === 'generic' ? 'laravel' : strtolower($m[1]);
        }
        if (preg_match('/^#.*\|\s*Project:\s*(\S+)/m', $conf, $m)) {
            $project = $m[1];
        }

        $names = $first('server_name');
        return new ParsedSite(
            serverNames: $names === null ? [] : array_values(array_filter(preg_split('/\s+/', $names) ?: [])),
            root: $first('root'),
            upstream: $first('fastcgi_pass', false),
            ssl: $ssl,
            certPath: $first('ssl_certificate'),
            keyPath: $first('ssl_certificate_key'),
            framework: $framework,
            projectPath: $project,
            errorLog: $firstWord($first('error_log')),
            accessLog: $firstWord($first('access_log')),
            hasRedirectBlock: $hasRedirect,
        );
    }
}
