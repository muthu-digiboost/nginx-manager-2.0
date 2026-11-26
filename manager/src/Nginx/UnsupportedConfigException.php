<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : UnsupportedConfigException.php
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
 * File: manager/src/Nginx/UnsupportedConfigException.php
 * Purpose: Thrown by SiteConfigEditor when a hand-edited config does not have the shape
 *          a structured edit needs (missing or ambiguous directive). The UI then points
 *          the user to the raw Config editor instead of guessing.
 */

declare(strict_types=1);

namespace NginxManager\Nginx;

use RuntimeException;

final class UnsupportedConfigException extends RuntimeException {}
