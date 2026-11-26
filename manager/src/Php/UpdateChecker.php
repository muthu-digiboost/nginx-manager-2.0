<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : UpdateChecker.php
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
 * File: manager/src/Php/UpdateChecker.php
 * Purpose: WordPress-style "updates available" for installed PHP versions.
 * Responsibilities - compare(installed, releases) sorts every installed version into:
 *   updates  branch folder (e.g. "8.3") older than the newest patch in the feed
 *   current  already on the newest patch
 *   eol      branch no longer in the feed (no more updates, e.g. 7.2, 5.6)
 *   pinned   exact-version folders (e.g. "8.5.12") - installed on purpose, never updated
 * Used by: PhpController, sidebar badge (with PhpReleaseFeed::cachedOnly())
 */

declare(strict_types=1);

namespace NginxManager\Php;

final class UpdateChecker
{
    public static function compare(array $installed, array $releases): array
    {
        $res = ['updates' => [], 'current' => [], 'eol' => [], 'pinned' => []];
        foreach ($installed as $v) {
            $id = (string) $v['id'];
            if (preg_match('/^\d+\.\d+\.\d+/', $id)) {
                $res['pinned'][] = $v;
                continue;
            }
            $release = $releases[$v['branch']] ?? null;
            if ($release === null) {
                $res['eol'][] = $v;
            } elseif ($v['version'] !== '' && version_compare($release['version'], $v['version'], '>')) {
                $flavour = ($v['threadSafety'] ?? 'ts') === 'nts' ? 'nts' : 'ts';
                $res['updates'][] = $v + ['latest' => $release['version'], 'build' => $release['builds'][$flavour] ?? null];
            } else {
                $res['current'][] = $v;
            }
        }
        return $res;
    }
}
