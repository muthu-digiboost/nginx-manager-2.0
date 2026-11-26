#!/bin/bash
#******************************************************************************
#*
#*  Project : nginx-manager-2.0
#*  File    : install-autostart.sh
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
# Starts the servers when you log in to macOS (counterpart of install-autostart.ps1).
# Usage: scripts/install-autostart.sh            install
#        scripts/install-autostart.sh --remove   uninstall
#
# Writes ~/Library/LaunchAgents/com.semansys.nginx-manager.plist, which runs start-all.sh
# at login; output goes to logs/autostart.log. Nothing needs administrator rights.

set -u
. "$(cd "$(dirname "$0")" && pwd)/lib/common.sh"

LABEL="com.semansys.nginx-manager"
PLIST="$HOME/Library/LaunchAgents/$LABEL.plist"

if [ "${1:-}" = "--remove" ]; then
    launchctl unload "$PLIST" >/dev/null 2>&1
    rm -f "$PLIST"
    echo "[+] Removed: the servers no longer start at login."
    exit 0
fi

BASE="$(nm_base)"
ROOT="$(nm_brew_root)"
mkdir -p "$HOME/Library/LaunchAgents" "$BASE/logs"

# XML-escape the install path (it may contain &, < or >).
xml() { printf '%s' "$1" | sed -e 's/&/\&amp;/g' -e 's/</\&lt;/g' -e 's/>/\&gt;/g'; }

cat > "$PLIST" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>              <string>$LABEL</string>
    <key>ProgramArguments</key>
    <array>
        <string>/bin/bash</string>
        <string>$(xml "$BASE/start-all.sh")</string>
    </array>
    <key>WorkingDirectory</key>   <string>$(xml "$BASE")</string>
    <key>EnvironmentVariables</key>
    <dict>
        <key>PATH</key>           <string>$(xml "$ROOT/bin:/usr/bin:/bin:/usr/sbin:/sbin")</string>
    </dict>
    <key>RunAtLoad</key>          <true/>
    <key>StandardOutPath</key>    <string>$(xml "$BASE/logs/autostart.log")</string>
    <key>StandardErrorPath</key>  <string>$(xml "$BASE/logs/autostart.log")</string>
</dict>
</plist>
EOF

launchctl unload "$PLIST" >/dev/null 2>&1
if launchctl load "$PLIST"; then
    echo "[+] Installed: the servers start when you log in (log: logs/autostart.log)."
    echo "    Undo with: scripts/install-autostart.sh --remove"
else
    echo "[x] launchctl could not load $PLIST"
    exit 1
fi
