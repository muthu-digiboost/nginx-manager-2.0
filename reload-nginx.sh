#!/bin/bash
#******************************************************************************
#*
#*  Project : nginx-manager-2.0
#*  File    : reload-nginx.sh
#*  Date    : 26/11/2026
#*  Author  : Semansys Development Team
#*
#*  Copyright (c) 2026
#*  Tel: +91 97863 37914
#*  Email: dev.digiboost.muthu@gmail.com
#*  All rights reserved, see the LICENSE file for more information.
#*
#******************************************************************************
#
# Tests the nginx config and reloads nginx only if the test passes.
# Usage: ./reload-nginx.sh
#
# macOS counterpart of reload-nginx.ps1 (Windows). Needs Homebrew, nginx (brew install nginx) and
# PHP 8.1+ (brew tap shivammathur/php && brew install shivammathur/php/php@8.4).
# The work itself is done by manager/bin/manager.php server:reload.

set -u
. "$(cd "$(dirname "$0")" && pwd)/scripts/lib/common.sh"
nm_require || exit 1
nm_cli server:reload "$@"
