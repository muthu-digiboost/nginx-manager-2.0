<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : CsrfException.php
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
 * File: manager/src/Http/CsrfException.php
 * Purpose: Thrown by Csrf::verify() when a POST carries a missing or wrong token;
 *          public/index.php turns it into a 419 "page expired" response.
 */

declare(strict_types=1);

namespace NginxManager\Http;

use RuntimeException;

final class CsrfException extends RuntimeException {}
