<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : View.php
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
 * File: manager/src/Support/View.php
 * Purpose: Renders plain-PHP templates from manager/views inside the shared layout.
 * Responsibilities:
 *   - render(template, data, layout): template output becomes $content in the layout
 *   - share(key, value): values every view/layout can use (e.g. the sidebar's
 *     update badge)
 * Used by: controllers
 * Notes: templates must escape with e(); variables come from extract() of $data.
 */

declare(strict_types=1);

namespace NginxManager\Support;

use RuntimeException;

final class View
{
    /** @var array<string, mixed> */
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $content = self::partial($template, $data);
        if ($layout === null) {
            return $content;
        }
        return self::partial($layout, $data + ['content' => $content]);
    }

    public static function partial(string $template, array $data = []): string
    {
        // Templates belong to the code, not to the install folder's data.
        $file = dirname(__DIR__, 2) . '/views/' . $template . '.php';
        if (!preg_match('/^[a-z0-9\/_-]+$/', $template) || !is_file($file)) {
            throw new RuntimeException("View not found: $template");
        }
        $vars = $data + self::$shared;
        return (static function (string $__file, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                require $__file;
                return (string) ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        })($file, $vars);
    }
}
