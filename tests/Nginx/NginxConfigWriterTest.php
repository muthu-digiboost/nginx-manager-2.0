<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : NginxConfigWriterTest.php
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
 * File: tests/Nginx/NginxConfigWriterTest.php
 * Purpose: The generated nginx files (upstreams, project-root variables, manager vhost)
 *          are derived only from settings.json + .env and never contain absolute paths
 *          except the .env values themselves (which live in a gitignored file).
 */

declare(strict_types=1);

use NginxManager\Nginx\NginxConfigWriter;
use NginxManager\Support\Env;
use NginxManager\Support\Paths;
use NginxManager\Support\Settings;

function writerSettings(): Settings
{
    return Settings::fromArray([
        'php' => ['managerVersion' => '8.3', 'versions' => [
            ['id' => '8.3', 'port' => 9083, 'upstream' => 'php83', 'version' => '8.3.0'],
            ['id' => '8.5.12', 'port' => 9100, 'upstream' => 'php8512', 'version' => '8.5.12'],
        ]],
    ]);
}

test('upstreams generated from settings', function () {
    $out = NginxConfigWriter::upstreams(writerSettings());
    assertContains("upstream php8512 {\n    server 127.0.0.1:9100;\n}", $out);
    assertContains("upstream php83 {\n    server 127.0.0.1:9083;\n}", $out);
    assertContains('# GENERATED', $out);
});

test('project root variables generated from .env', function () {
    Env::fromArray(['PROJECTS_DIR' => '/tmp/projects', 'PROJECTS_DIR_D' => '/tmp/d']);
    $out = NginxConfigWriter::envMap(Paths::projectRoots());
    assertContains("map \$host \$projects_dir {\n    default \"/tmp/projects\";\n}", $out);
    assertContains("map \$host \$projects_dir_d {\n    default \"/tmp/d\";\n}", $out);
});

test('project root variable exists even when PROJECTS_DIR is empty', function () {
    // nginx refuses to start when a site config uses an undefined variable.
    $out = NginxConfigWriter::envMap([]);
    assertContains("map \$host \$projects_dir {\n    default \"\";\n}", $out);
});

test('manager vhost listens on MANAGER_HOST:PORT with relative root and no CORS', function () {
    Env::fromArray(['MANAGER_HOST' => '127.0.0.1', 'MANAGER_PORT' => '8081']);
    $vh = NginxConfigWriter::managerVhost(writerSettings());
    assertContains('listen       127.0.0.1:8081;', $vh);
    assertContains('fastcgi_pass   php83;', $vh);
    assertContains('root  manager/public;', $vh);
    // Declaring add_header in the server block stops the global CORS headers from
    // nginx.conf being inherited: other websites must not read manager pages.
    assertContains('add_header X-Frame-Options "SAMEORIGIN" always;', $vh);
    assertNotContains('Access-Control-Allow-Origin', $vh);
});

test('manager vhost defaults to 127.0.0.1:8080', function () {
    $vh = NginxConfigWriter::managerVhost(writerSettings());
    assertContains('listen       127.0.0.1:8080;', $vh);
});
