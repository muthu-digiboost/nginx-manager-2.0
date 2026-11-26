<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : SiteConfigTest.php
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
 * File: tests/Nginx/SiteConfigTest.php
 * Purpose: Parser, builder and structured editor for conf/sites/*.conf.
 * Key promise under test: a structured edit changes ONLY the targeted value and keeps
 * every other byte of a hand-edited config (comments, includes, deny rules, quoting).
 */

declare(strict_types=1);

use NginxManager\Nginx\SiteConfigBuilder;
use NginxManager\Nginx\SiteConfigEditor;
use NginxManager\Nginx\SiteConfigParser;
use NginxManager\Nginx\SiteSpec;
use NginxManager\Nginx\UnsupportedConfigException;

function httpSpec(string $framework = 'general', array $aliases = []): SiteSpec
{
    return new SiteSpec('demo.example.test', $aliases, '$projects_dir/demo/public', '8.3', 'php83', $framework, false, null, null);
}

// -- Parser ----------------------------------------------------------------------

test('parses SSL symfony site', function () {
    $p = SiteConfigParser::parse(fixture('sites/api.example.test.conf'));
    assertSame(['api.example.test'], $p->serverNames);
    assertSame('$projects_dir/demo/api/public', $p->root);
    assertSame('../ssl/api.example.test/ssl.crt/server.crt', $p->certPath);
    assertSame('../ssl/api.example.test/ssl.key/server.key', $p->keyPath);
    assertSame('php83', $p->upstream);
    assertTrue($p->ssl);
    assertTrue($p->hasRedirectBlock);
    assertSame('symfony', $p->framework);
    assertSame('logs/api.example.test-error.log', $p->errorLog);
    assertSame('logs/api.example.test-access.log', $p->accessLog);
});

test('parses hand-edited site with include, quotes and comments containing braces', function () {
    $p = SiteConfigParser::parse(fixture('sites/custom.example.test.conf'));
    assertSame('php8512', $p->upstream);
    assertSame('$projects_dir/demo/custom', $p->root);
    assertSame(['custom.example.test'], $p->serverNames);
    assertSame('general', $p->framework);
});

test('parser maps v1 framework "generic" to laravel', function () {
    $p = SiteConfigParser::parse("#  Framework: generic | Project: x\nserver { listen 80; server_name a.test; root x; }");
    assertSame('laravel', $p->framework);
});

// -- Builder ---------------------------------------------------------------------

test('builder output round-trips through parser and has no drive letters', function () {
    $conf = SiteConfigBuilder::build(httpSpec('laravel', ['www.demo.example.test']));
    $p = SiteConfigParser::parse($conf);
    assertSame(['demo.example.test', 'www.demo.example.test'], $p->serverNames);
    assertSame('$projects_dir/demo/public', $p->root);
    assertSame('php83', $p->upstream);
    assertTrue(!$p->ssl);
    assertTrue(!$p->hasRedirectBlock);
    assertSame('laravel', $p->framework);
    assertSame(0, preg_match('~(?<![A-Za-z0-9])[A-Za-z]:[/\\\\]~', $conf));
    assertContains('try_files $uri $uri/ /index.php?$query_string;', $conf);
});

test('builder writes framework specific rules', function () {
    assertContains('try_files $uri /index.php$is_args$args;', SiteConfigBuilder::build(httpSpec('symfony')));
    assertContains('try_files $uri $uri/ /index.php?/$request_uri;', SiteConfigBuilder::build(httpSpec('codeigniter')));
    $wp = SiteConfigBuilder::build(httpSpec('wordpress'));
    assertContains('try_files $uri $uri/ /index.php?$args;', $wp);
    assertContains('location ~* /(?:uploads|files)/.*\.php$', $wp);
    assertThrows(InvalidArgumentException::class, fn() => SiteConfigBuilder::build(httpSpec('rails')));
});

test('builder SSL output has cert lines and redirect block', function () {
    $spec = new SiteSpec('*.example.test', [], '$projects_dir/x', '8.4', 'php84', 'general', true,
        '../ssl/wildcard.example.test/ssl.crt/server.crt', '../ssl/wildcard.example.test/ssl.key/server.key');
    $conf = SiteConfigBuilder::build($spec);
    $p = SiteConfigParser::parse($conf);
    assertTrue($p->ssl);
    assertTrue($p->hasRedirectBlock);
    assertSame('../ssl/wildcard.example.test/ssl.crt/server.crt', $p->certPath);
    assertContains('logs/wildcard.example.test-error.log', $conf);
});

// -- Editor ----------------------------------------------------------------------

test('editor changes upstream and keeps every other byte', function () {
    $in = fixture('sites/custom.example.test.conf');
    $out = SiteConfigEditor::apply($in, ['upstream' => 'php85']);
    assertSame(str_replace('fastcgi_pass   php8512;', 'fastcgi_pass   php85;', $in), $out);
});

test('editor changes root only in the main block', function () {
    $in = fixture('sites/api.example.test.conf');
    $out = SiteConfigEditor::apply($in, ['root' => '$projects_dir_d/new/public']);
    assertSame(str_replace('root  $projects_dir/demo/api/public;', 'root  $projects_dir_d/new/public;', $in), $out);
});

test('editor replaces server names in main and redirect blocks', function () {
    $in = fixture('sites/api.example.test.conf');
    $out = SiteConfigEditor::apply($in, ['serverNames' => ['api.example.test', 'www.api.example.test']]);
    assertSame(2, substr_count($out, 'api.example.test www.api.example.test;'));
    assertSame(['api.example.test', 'www.api.example.test'], SiteConfigParser::parse($out)->serverNames);
});

test('editor turns SSL on for an HTTP-only site', function () {
    $http = SiteConfigBuilder::build(httpSpec());
    $out = SiteConfigEditor::apply($http, ['ssl' => true,
        'certPath' => '../ssl/demo.example.test/ssl.crt/server.crt',
        'keyPath'  => '../ssl/demo.example.test/ssl.key/server.key']);
    $p = SiteConfigParser::parse($out);
    assertTrue($p->ssl);
    assertTrue($p->hasRedirectBlock);
    assertSame('../ssl/demo.example.test/ssl.crt/server.crt', $p->certPath);
    assertSame('../ssl/demo.example.test/ssl.key/server.key', $p->keyPath);
    assertSame('$projects_dir/demo/public', $p->root);
});

test('editor turns SSL off and removes the redirect block', function () {
    $out = SiteConfigEditor::apply(fixture('sites/api.example.test.conf'), ['ssl' => false]);
    $p = SiteConfigParser::parse($out);
    assertTrue(!$p->ssl);
    assertTrue(!$p->hasRedirectBlock);
    assertSame(null, $p->certPath);
    assertNotContains('return 301', $out);
    assertNotContains('ssl_certificate', $out);
    assertContains('listen       80;', $out);
    assertSame('php83', $p->upstream);
});

test('editor SSL off then on restores an equivalent config', function () {
    $in = fixture('sites/api.example.test.conf');
    $off = SiteConfigEditor::apply($in, ['ssl' => false]);
    $on = SiteConfigEditor::apply($off, ['ssl' => true,
        'certPath' => '../ssl/api.example.test/ssl.crt/server.crt',
        'keyPath'  => '../ssl/api.example.test/ssl.key/server.key']);
    $a = SiteConfigParser::parse($in);
    $b = SiteConfigParser::parse($on);
    assertSame([$a->ssl, $a->certPath, $a->keyPath, $a->hasRedirectBlock, $a->root],
               [$b->ssl, $b->certPath, $b->keyPath, $b->hasRedirectBlock, $b->root]);
});

test('editor updates the generated header line', function () {
    $out = SiteConfigEditor::apply(fixture('sites/api.example.test.conf'), ['upstream' => 'php84', 'phpLabel' => '8.4']);
    assertContains('#  api.example.test → PHP 8.4 (SSL enabled)', $out);
});

// Regression (final review #2): hand-edited file with a www -> bare-domain redirect over
// http (not detected as an HTTPS redirect) and a nested location on another PHP.
const HAND_EDITED = <<<'CONF'
server {
    listen       80;
    server_name  www.a.example.test;
    return 301   http://a.example.test$request_uri;
}
server {
    listen       80;
    server_name  a.example.test;
    root  $projects_dir/a;
    location ~ \.php$ {
        fastcgi_pass   php83;
    }
    location /legacy/ {
        location ~ \.php$ {
            fastcgi_pass   127.0.0.1:9056;
        }
    }
}
CONF;

test('server name edits leave unrelated server blocks alone', function () {
    $out = SiteConfigEditor::apply(HAND_EDITED, ['serverNames' => ['a.example.test', 'b.example.test']]);
    assertContains("server_name  www.a.example.test;", $out);
    assertContains("server_name  a.example.test b.example.test;", $out);
});

test('server name edits leave a hand-written https www redirect alone', function () {
    // Regression (final review 2, #2): a root-less "return 301 https://" block for OTHER names
    // is not the manager's HTTP->HTTPS redirect and must keep its server_name.
    $conf = "server {\n    listen 443 ssl;\n    server_name  www.a.test;\n    return 301 https://a.test\$request_uri;\n}\n"
          . "server {\n    listen 443 ssl;\n    server_name  a.test;\n    root  \$projects_dir/a;\n}\n"
          . "server {\n    listen 80;\n    server_name  a.test;\n    return 301 https://\$host\$request_uri;\n}\n";
    $out = SiteConfigEditor::apply($conf, ['serverNames' => ['a.test', 'b.test']]);
    assertContains('server_name  www.a.test;', $out);
    assertSame(2, substr_count($out, 'server_name  a.test b.test;'));   // main + its own redirect
});

test('turning HTTPS off keeps a hand-written redirect for other names', function () {
    $conf = "server {\n    listen 80;\n    server_name  www.a.test;\n    return 301 https://a.test\$request_uri;\n}\n"
          . "server {\n    listen 443 ssl;\n    server_name  a.test;\n    root  \$projects_dir/a;\n"
          . "    ssl_certificate x.crt;\n    ssl_certificate_key x.key;\n}\n"
          . "server {\n    listen 80;\n    server_name  a.test;\n    return 301 https://\$host\$request_uri;\n}\n";
    $out = SiteConfigEditor::apply($conf, ['ssl' => false]);
    assertContains('server_name  www.a.test;', $out);
    assertSame(1, substr_count($out, 'return 301'));
});

test('upstream edits only change fastcgi_pass lines using the current PHP', function () {
    $out = SiteConfigEditor::apply(HAND_EDITED, ['upstream' => 'php84']);
    assertContains('fastcgi_pass   php84;', $out);
    assertContains('fastcgi_pass   127.0.0.1:9056;', $out);
});

test('the site form passes only fields that really changed', function () {
    $p = SiteConfigParser::parse(fixture('sites/api.example.test.conf'));
    $php83 = ['id' => '8.3', 'upstream' => 'php83'];
    $php84 = ['id' => '8.4', 'upstream' => 'php84'];
    // nothing changed (e.g. only the group was edited)
    assertSame([], NginxManager\Controllers\SitesController::formChanges($p, ['api.example.test'], null, $php83));
    // PHP changed
    assertSame(['upstream' => 'php84', 'phpLabel' => '8.4'],
        NginxManager\Controllers\SitesController::formChanges($p, ['api.example.test'], null, $php84));
    // domains and root changed
    assertSame(['serverNames' => ['api.example.test', 'x.example.test'], 'root' => '$projects_dir/new'],
        NginxManager\Controllers\SitesController::formChanges($p, ['api.example.test', 'x.example.test'], '$projects_dir/new', $php83));
    // a site without PHP and no PHP chosen: no upstream change
    $static = SiteConfigParser::parse("server { listen 80; server_name s.example.test; root x; }");
    assertSame([], NginxManager\Controllers\SitesController::formChanges($static, ['s.example.test'], null, null));
});

test('editor refuses ambiguous or unknown shapes', function () {
    assertThrows(UnsupportedConfigException::class, fn() => SiteConfigEditor::apply("server { listen 80; }", ['root' => 'x']));
    assertThrows(UnsupportedConfigException::class, fn() => SiteConfigEditor::apply("server { listen 80; root a; }", ['upstream' => 'php84']));
    $two = "server { listen 80; server_name a.test; root a; }\nserver { listen 81; server_name b.test; root b; }";
    assertThrows(UnsupportedConfigException::class, fn() => SiteConfigEditor::apply($two, ['root' => 'x']));
});

test('editor rejects values that would inject directives', function () {
    $in = fixture('sites/api.example.test.conf');
    assertThrows(InvalidArgumentException::class, fn() => SiteConfigEditor::apply($in, ['root' => 'x; include evil']));
    assertThrows(InvalidArgumentException::class, fn() => SiteConfigEditor::apply($in, ['upstream' => 'php83 }']));
});
