<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : JobRunnerTest.php
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
 * File: tests/Php/JobRunnerTest.php
 * Purpose: Background jobs - only allow-listed scripts with validated arguments can be
 *          launched (the manager is elevated), ids can't escape data/jobs, and the log is
 *          readable incrementally for the polling job page.
 */

declare(strict_types=1);

use NginxManager\Php\JobRunner;
use NginxManager\Support\Paths;

final class RecordingJobRunner extends JobRunner
{
    /** @var list<list<string>> */
    public array $launched = [];

    protected function launch(array $argv): void
    {
        $this->launched[] = $argv;
    }
}

function jobRunner(): RecordingJobRunner
{
    Paths::overrideBase(tempDir());
    return new RecordingJobRunner();
}

test('start writes a queued job and launches the allow-listed script detached', function () {
    $r = jobRunner();
    $id = $r->start('php-service.ps1', ['Action' => 'restart', 'Id' => '8.3,8.4'], 'Restart PHP 8.3, 8.4');
    assertTrue(preg_match('/^\d{8}-\d{6}-[0-9a-f]{6}$/', $id) === 1);
    $job = $r->status($id);
    assertSame('queued', $job['state']);
    assertSame('Restart PHP 8.3, 8.4', $job['title']);
    $argv = $r->launched[0];
    assertSame('powershell.exe', basename($argv[array_search('-NoProfile', $argv, true) - 1]));
    assertTrue(in_array(Paths::in('scripts', 'php-service.ps1'), $argv, true));
    $tail = array_slice($argv, -6);
    assertSame(['-JobId', $id, '-Action', 'restart', '-Id', '8.3,8.4'], $tail);
});

test('jobs are launched through Start-Process so they inherit no handles from php-cgi', function () {
    // Regression (final review #1): a job spawned directly from php-cgi inherited its
    // FastCGI listening socket, so restarting the manager's own PHP left nothing listening.
    $argv = ['powershell.exe', '-NoProfile', '-File', 'Q:/My Tools/nm2/scripts/php-service.ps1', '-JobId', '20261126-000000-abcdef', '-Id', "8.3"];
    $cmd = JobRunner::launcherCommand($argv);
    assertSame('powershell.exe', $cmd[0]);
    assertSame('-EncodedCommand', $cmd[count($cmd) - 2]);
    $script = mb_convert_encoding(base64_decode($cmd[count($cmd) - 1]), 'UTF-8', 'UTF-16LE');
    assertContains("Start-Process -FilePath 'powershell.exe' -WindowStyle Hidden", $script);
    assertContains('"Q:/My Tools/nm2/scripts/php-service.ps1"', $script);   // path with a space stays one argument
    assertContains('-JobId 20261126-000000-abcdef -Id 8.3', $script);
});

test('launcher refuses quotes in job arguments', function () {
    assertThrows(InvalidArgumentException::class, fn() => JobRunner::launcherCommand(['powershell.exe', '-File', 'x"; calc']));
});

test('only allow-listed scripts can be started', function () {
    $r = jobRunner();
    assertThrows(InvalidArgumentException::class, fn() => $r->start('evil.ps1', [], 'x'));
    assertThrows(InvalidArgumentException::class, fn() => $r->start('../start-all.ps1', [], 'x'));
});

test('arguments are validated', function () {
    $r = jobRunner();
    assertThrows(InvalidArgumentException::class, fn() => $r->start('php-service.ps1', ['Action' => 'start; calc'], 'x'));
    assertThrows(InvalidArgumentException::class, fn() => $r->start('php-service.ps1', ['bad key' => 'x'], 'x'));
    assertThrows(InvalidArgumentException::class, fn() => $r->start('php-service.ps1', ['Id' => '"8.3"'], 'x'));
});

test('status and log reject malformed ids', function () {
    $r = jobRunner();
    assertSame(null, $r->status('../../settings'));
    assertSame(null, $r->status('20261126-120000-zzzzzz'));
    assertSame('', $r->log('..\\x', 0)['text']);
});

test('job log is returned incrementally from an offset', function () {
    $r = jobRunner();
    $id = $r->start('php-service.ps1', ['Action' => 'start', 'Id' => '8.4'], 't');
    $log = Paths::in('data', 'jobs', $id . '.log');
    file_put_contents($log, "abc");
    $first = $r->log($id, 0);
    assertSame(['abc', 3, 'queued'], [$first['text'], $first['offset'], $first['state']]);
    file_put_contents($log, "d", FILE_APPEND);
    $second = $r->log($id, 3);
    assertSame(['d', 4], [$second['text'], $second['offset']]);
});

test('recent lists newest jobs first', function () {
    $r = jobRunner();
    $a = $r->start('php-service.ps1', ['Action' => 'start', 'Id' => '8.4'], 'first');
    $b = $r->start('php-service.ps1', ['Action' => 'stop', 'Id' => '8.4'], 'second');
    $ids = array_column($r->recent(5), 'id');
    assertSame(2, count($ids));
    assertTrue(in_array($a, $ids, true) && in_array($b, $ids, true));
});

// Regression (final review 2, #1): a job must never stay "queued" forever without a message.
final class FailingLaunchJobRunner extends JobRunner
{
    protected function launch(array $argv): void
    {
        throw new RuntimeException('powershell.exe could not be started');
    }
}

test('empty and apostrophe arguments survive the launcher', function () {
    $cmd = JobRunner::launcherCommand(['powershell.exe', '-File', "Q:/Bob's Tools/x.ps1", '-Sha256', '', '-Id', '8.4']);
    $script = mb_convert_encoding(base64_decode($cmd[count($cmd) - 1]), 'UTF-8', 'UTF-16LE');
    assertContains("\"Q:/Bob''s Tools/x.ps1\"", $script);      // '' = one ' inside the PowerShell literal
    assertContains('-Sha256 "" -Id 8.4', $script);
});

test('a job whose launch fails is marked failed with the reason', function () {
    Paths::overrideBase(tempDir());
    $r = new FailingLaunchJobRunner();
    $id = $r->start('php-service.ps1', ['Action' => 'start', 'Id' => '8.4'], 't');
    $job = $r->status($id);
    assertSame('failed', $job['state']);
    assertContains('could not be started', $job['message']);
});

test('a job still queued after a minute is reported as not started', function () {
    $r = jobRunner();
    $id = $r->start('php-service.ps1', ['Action' => 'start', 'Id' => '8.4'], 't');
    $file = Paths::in('data', 'jobs', $id . '.json');
    $job = json_decode((string) file_get_contents($file), true);
    $job['createdAt'] = date('c', time() - 120);
    file_put_contents($file, json_encode($job));
    assertSame('failed', $r->status($id)['state']);
    assertContains('did not start', $r->log($id, 0)['message']);
});

// -- macOS: jobs are PHP CLI commands started with nohup ----------------------------------------

test('macOS: a job runs "php manager.php job:run <job>" detached with nohup', function () {
    $r = jobRunner();
    \NginxManager\Support\Platform::override('Darwin');
    $id = $r->start('php-service.ps1', ['Action' => 'restart', 'Id' => '8.4'], 't');
    $argv = $r->launched[0];
    assertSame([Paths::in('manager', 'bin', 'manager.php'), 'job:run', 'php-service.ps1', '--JobId', $id, '--Action', 'restart', '--Id', '8.4'],
        array_slice($argv, 1));
    $cmd = JobRunner::macLauncherCommand(['php', 'x.php']);
    assertSame(['/bin/bash', '-c'], array_slice($cmd, 0, 2));
    assertSame(['nm-job', '/dev/null', 'php', 'x.php'], array_slice($cmd, 3));   // output: /dev/null by default
    // inherited descriptors (php-cgi's listening socket!) are closed before nohup
    assertContains('exec $i>&-', $cmd[2]);
    assertContains('nohup "$@" >>"$log" 2>&1 &', $cmd[2]);
    // php-cgi keeps its errors: a log file (with spaces) is passed as an argument, not in the script
    $logged = JobRunner::macLauncherCommand(['php-cgi'], '/Users/me/my sites/logs/php-cgi.log');
    assertSame(['nm-job', '/Users/me/my sites/logs/php-cgi.log', 'php-cgi'], array_slice($logged, 3));
});

test('macOS: the CLI php next to php-cgi runs the jobs', function () {
    assertSame('/opt/homebrew/opt/php@8.4/bin/php', JobRunner::cliPhpFor('/opt/homebrew/opt/php@8.4/bin/php-cgi', fn($p) => true));
    assertSame('php', JobRunner::cliPhpFor('/somewhere/php-cgi', fn($p) => false));
});

test('job state and log can be written from PHP (macOS jobs)', function () {
    $r = jobRunner();
    $id = $r->start('php-service.ps1', ['Action' => 'start', 'Id' => '8.4'], 't');
    $r->appendLog($id, 'hello');
    $r->setState($id, 'done', 'all good');
    $log = $r->log($id, 0);
    assertTrue((bool) preg_match('/^\[\d\d:\d\d:\d\d\] hello\n$/', $log['text']));
    assertSame(['done', 'all good'], [$log['state'], $log['message']]);
});

test('job:run args are parsed from --Name value pairs', function () {
    assertSame(['JobId' => 'x', 'Id' => '8.4', 'Sha256' => ''],
        \NginxManager\Mac\JobCommand::parseArgs(['--JobId', 'x', '--Id', '8.4', '--Sha256', '']));
    assertThrows(InvalidArgumentException::class, fn() => \NginxManager\Mac\JobCommand::parseArgs(['--Id']));
});

test('every job the UI can start has a macOS implementation', function () {
    foreach (JobRunner::SCRIPTS as $script) {
        $class = \NginxManager\Mac\JobCommand::JOBS[$script] ?? null;
        assertTrue($class !== null && class_exists($class), "no macOS job for $script");
    }
});

test('macOS jobs leave the php-cgi process group first (stopping that PHP must not kill the job)', function () {
    // Final review 3, C1: php-cgi's master signals its whole process group on SIGTERM.
    $calls = 0;
    $previous = \NginxManager\Mac\JobCommand::$detach;
    \NginxManager\Mac\JobCommand::$detach = function () use (&$calls) { $calls++; };
    try {
        \NginxManager\Support\Platform::override('Darwin');
        try {
            \NginxManager\Mac\JobCommand::run(new \NginxManager\App(), ['no-such-job.ps1', '--JobId', '']);
        } catch (InvalidArgumentException) {
        }
        assertSame(1, $calls);
    } finally {
        \NginxManager\Mac\JobCommand::$detach = $previous;
    }
});
