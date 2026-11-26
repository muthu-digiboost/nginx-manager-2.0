<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : Shell.php
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
 * File: manager/src/Support/Shell.php
 * Purpose: Runs external programs WITHOUT a shell and with a timeout.
 * Responsibilities:
 *   - argv arrays only (proc_open with an array + bypass_shell): user input is never
 *     concatenated into a command line, which matters because the manager is elevated
 *   - stdout+stderr captured together through a temp file (pipes cannot be read
 *     non-blocking on Windows, a file can be polled while we watch the clock)
 *   - kills the process when the timeout expires
 * Used by: NginxControl, CertificateService, PhpVersionRepository, ExtensionManager
 * Notes: not final - tests subclass it to fake command output.
 */

declare(strict_types=1);

namespace NginxManager\Support;

use RuntimeException;

class Shell
{
    /** @param list<string> $argv */
    public function run(array $argv, ?string $cwd = null, int $timeout = 60): ShellResult
    {
        if ($argv === []) {
            throw new RuntimeException('Empty command');
        }
        $out = tempnam(sys_get_temp_dir(), 'nm2');
        $spec = [
            0 => ['file', Platform::nullDevice(), 'r'],   // NUL on Windows, /dev/null on macOS
            1 => ['file', $out, 'w'],
            2 => ['redirect', 1],
        ];
        $proc = proc_open(array_map('strval', $argv), $spec, $pipes, $cwd, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            @unlink($out);
            return new ShellResult(127, 'Could not start ' . $argv[0]);
        }

        $deadline = microtime(true) + $timeout;
        $exit = -1;
        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $exit = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc);
                $exit = 124;
                break;
            }
            usleep(20000);
        }
        proc_close($proc);

        $text = (string) @file_get_contents($out);
        @unlink($out);
        if ($exit === 124) {
            $text .= "\n(timed out after {$timeout}s)";
        }
        return new ShellResult($exit, rtrim($text));
    }
}
