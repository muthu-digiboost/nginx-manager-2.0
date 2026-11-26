<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : manager.php
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
 * File: manager/bin/manager.php
 * Purpose: Command-line entry point used by the PowerShell scripts (and you).
 * Usage:   php\<ver>\php.exe manager\bin\manager.php <command> [args]
 * Commands:
 *   config:write   regenerate conf/generated/*.conf + conf/manager/manager.conf from
 *                  data/settings.json and .env (called by start-all.ps1, install-php.ps1)
 *   ext:enable     <php id> <ext>: enable an extension safely (used by install-extension.ps1)
 *   job:run        macOS background jobs (started by the web UI)
 *   server:*       macOS start / stop / restart / reload (called by the *.sh launchers)
 *   help           list commands
 * Exit code: 0 success, 1 failure (message on stderr)
 * Notes: later features register more commands in the $commands table below.
 */

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use NginxManager\App;
use NginxManager\Nginx\NginxConfigWriter;
use NginxManager\Php\ExtensionManager;
use NginxManager\Support\Env;
use NginxManager\Support\Paths;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

/** @var array<string, array{0:string, 1:callable(App, list<string>):int}> */
$commands = [
    'config:write' => ['Regenerate nginx config derived from settings + .env', static function (App $app): int {
        foreach (NginxConfigWriter::writeAll($app->settings()) as $file) {
            echo "wrote $file\n";
        }
        return 0;
    }],
    'ext:enable' => ['ext:enable <php id> <extension> - enable an extension (php.ini checked, rolled back on error)', static function (App $app, array $args): int {
        [$phpId, $ext] = [$args[0] ?? '', $args[1] ?? ''];
        // No restart here: the caller (install-extension.ps1) restarts PHP itself.
        $manager = new ExtensionManager($app->settings(), $app->shell(), $app->jobs(), static fn(): bool => false);
        $res = $manager->setEnabled($phpId, [$ext => true]);
        echo $res['output'], "\n";
        return $res['ok'] ? 0 : 1;
    }],
    'ssl:localhost' => ['ssl:localhost - create the default localhost certificate if missing (no openssl.exe needed)', static function (App $app): int {
        if (is_file(Paths::in('ssl', 'localhost', 'ssl.crt', 'server.crt'))) {
            echo "ssl/localhost already exists\n";
            return 0;
        }
        $log = $app->certificates()->generate('localhost', [], false);
        foreach ($log as [$level, $msg]) {
            echo strtoupper($level), '  ', $msg, "\n";
        }
        return end($log)[0] === 'error' ? 1 : 0;
    }],
    'job:run' => ['job:run <job> --JobId <id> --Name value ... - run a background job (macOS)', static function (App $app, array $args): int {
        return \NginxManager\Mac\JobCommand::run($app, $args);
    }],

    // -- macOS: called by start-all.sh, stop-all.sh, restart-all.sh, reload-nginx.sh --------
    'server:start' => ['server:start [--open] - start nginx and the Auto-start PHP versions (macOS)', static function (App $app, array $args): int {
        $url = macServer($app)->start();
        echo "\nManager UI: $url\n";
        if (in_array('--open', $args, true)) {
            $app->shell()->run(['open', $url], null, 10);
        }
        return 0;
    }],
    'server:stop' => ['server:stop - stop nginx and every PHP version (macOS)', static function (App $app): int {
        macServer($app)->stop();
        return 0;
    }],
    'server:restart' => ['server:restart - stop, then start (macOS)', static function (App $app): int {
        macServer($app)->stop();
        echo "\nManager UI: ", macServer(new App())->start(), "\n";
        return 0;
    }],
    'server:reload' => ['server:reload - test the nginx config and reload (macOS)', static function (App $app): int {
        macServer($app)->reload();
        return 0;
    }],
];

/** The macOS server control with its real Homebrew and php-cgi helpers. */
function macServer(App $app): \NginxManager\Mac\Server
{
    if (\NginxManager\Support\Platform::isWindows()) {
        throw new RuntimeException('On Windows use start-all.ps1, stop-all.ps1, restart-all.ps1 and reload-nginx.ps1.');
    }
    return new \NginxManager\Mac\Server($app, new \NginxManager\Mac\Brew($app->shell()), new \NginxManager\Mac\PhpCgi($app->shell()));
}

$name = $argv[1] ?? 'help';
if ($name === 'help' || !isset($commands[$name])) {
    echo "Nginx Manager 2.0 CLI\n\nCommands:\n";
    foreach ($commands as $cmd => [$description]) {
        printf("  %-16s %s\n", $cmd, $description);
    }
    exit($name === 'help' ? 0 : 1);
}

try {
    Env::load(Paths::in('.env'));
    exit($commands[$name][1](new App(), array_slice($argv, 2)));
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
