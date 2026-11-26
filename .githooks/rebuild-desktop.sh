#!/bin/bash
#******************************************************************************
#*
#*  Project : nginx-manager-2.0
#*  File    : rebuild-desktop.sh
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
# Rebuilds the desktop app after a commit or a merge on the build branch (default: main),
# in the background, so git returns at once. Called by .githooks/post-commit and
# .githooks/post-merge (enabled with: git config core.hooksPath .githooks).
#
#   Build branch : git config nm.buildBranch <branch>   (default main)
#   Turn off     : git config nm.autoBuild false
#   Log          : desktop/dist/auto-build.log  (the installer lands in desktop/dist/)
#   Windows -> npm run dist:win (installer .exe); macOS -> npm run dist:mac (.dmg)
#   NM_HOOK_DRYRUN=1 prints what would run instead of building (used by checks).
#
# bash 3.2 compatible (macOS); runs in Git Bash on Windows.

set -u
root="$(git rev-parse --show-toplevel 2>/dev/null)" || exit 0
branch="$(git rev-parse --abbrev-ref HEAD 2>/dev/null)"
want="$(git config --get nm.buildBranch || echo main)"
[ "$(git config --get nm.autoBuild || echo true)" = "false" ] && exit 0
[ "$branch" = "$want" ] || exit 0                 # other branches: no build
[ -f "$root/desktop/package.json" ] || exit 0

case "$(uname -s)" in
    Darwin) target="dist:mac" ;;
    *)      target="dist:win" ;;
esac

if [ "${NM_HOOK_DRYRUN:-}" = "1" ]; then
    echo "nm-hook: would rebuild ($target) on $branch at $(git rev-parse --short HEAD)"
    exit 0
fi

mkdir -p "$root/desktop/dist"
log="$root/desktop/dist/auto-build.log"
echo "nm-hook: rebuilding the desktop app in the background ($target) - log: desktop/dist/auto-build.log"
(
    cd "$root/desktop" || exit 1
    echo "== $(date '+%Y-%m-%d %H:%M:%S') rebuild on $branch at $(git rev-parse --short HEAD)"
    { [ -d node_modules ] || npm ci --no-fund; } && npm run "$target" && echo "== done: see desktop/dist/" || echo "== FAILED"
) >"$log" 2>&1 </dev/null &
exit 0
