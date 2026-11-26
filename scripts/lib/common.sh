#******************************************************************************
#*
#*  Project : nginx-manager-2.0
#*  File    : common.sh
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
# Shared helpers for the macOS launchers (start-all.sh, stop-all.sh, restart-all.sh,
# reload-nginx.sh, scripts/install-autostart.sh). Sourced, never run directly.
#
#   nm_base        the install folder (parent of scripts/)
#   nm_find_php    a PHP CLI >= 8.1 to run manager/bin/manager.php:
#                  $NM_PHP, then Homebrew php, php@8.5 ... php@8.1, then php on PATH
#   nm_require     checks Homebrew, nginx and PHP and prints how to install what is missing
#   nm_cli         runs manager/bin/manager.php with the found PHP
#
# bash 3.2 compatible (the /bin/bash of macOS): no associative arrays, no mapfile.

nm_base() {
    cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd
}

nm_brew_root() {
    if [ -n "${BREW_PREFIX:-}" ]; then echo "$BREW_PREFIX"
    elif [ -d /opt/homebrew ]; then echo /opt/homebrew      # Apple silicon
    else echo /usr/local                                    # Intel
    fi
}

# Prints the path of a PHP CLI that is 8.1 or newer, or nothing.
nm_find_php() {
    local root candidate
    root="$(nm_brew_root)"
    for candidate in "${NM_PHP:-}" "$root/opt/php/bin/php" \
                     "$root/opt/php@8.5/bin/php" "$root/opt/php@8.4/bin/php" "$root/opt/php@8.3/bin/php" \
                     "$root/opt/php@8.2/bin/php" "$root/opt/php@8.1/bin/php" "$(command -v php 2>/dev/null)"; do
        [ -n "$candidate" ] && [ -x "$candidate" ] || continue
        # -n: no php.ini, so startup warnings of extensions cannot end up in the output
        if "$candidate" -n -r 'exit(version_compare(PHP_VERSION, "8.1", ">=") ? 0 : 1);' >/dev/null 2>&1; then
            echo "$candidate"
            return 0
        fi
    done
    return 1
}

nm_require() {
    local root missing=0
    root="$(nm_brew_root)"
    if [ ! -x "$root/bin/brew" ]; then
        echo "[x] Homebrew was not found. Install it from https://brew.sh and run this again."
        return 1
    fi
    if [ -z "${NGINX_BIN:-}" ] && [ ! -x "$root/bin/nginx" ] && ! command -v nginx >/dev/null 2>&1; then
        echo "[x] nginx is not installed:  brew install nginx"
        missing=1
    fi
    if ! NM_PHP_BIN="$(nm_find_php)"; then
        echo "[x] PHP 8.1 or newer is not installed:"
        echo "      brew tap shivammathur/php"
        echo "      brew install shivammathur/php/php@8.4"
        missing=1
    fi
    [ "$missing" -eq 0 ]
}

nm_cli() {
    local php base
    php="$(nm_find_php)" || { echo "[x] No PHP 8.1+ found (see above)."; return 1; }
    base="$(nm_base)"
    cd "$base" && "$php" "$base/manager/bin/manager.php" "$@"
}
