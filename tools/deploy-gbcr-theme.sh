#!/usr/bin/env bash
# Upload the Gansbaai Community Radio WordPress theme (stations/gansbaai/theme/gansbaai-radio) to its site.
#   bash tools/deploy-gbcr-theme.sh [ssh-host] [wordpress-dir] [owner]
# Defaults: vmcloud1, /home/controll1/web/gbcr.controll.co.za/public_html, controll1 (HestiaCP, signing in as root).
#
# Replaces the theme's files only (the theme's settings live in the database and stay). The first time,
# also activate it and make the pages (HANDOVER.md, "Gansbaai"): wp theme activate gansbaai-radio, the Home,
# Line-up and Contact pages, Home as the front page.
set -euo pipefail
host="${1:-vmcloud1}"
wp="${2:-/home/controll1/web/gbcr.controll.co.za/public_html}"
owner="${3:-controll1}"
repo="$(cd "$(dirname "$0")/.." && pwd)"
src="$repo/stations/gansbaai/theme/gansbaai-radio"
[ -f "$src/style.css" ] || { echo "No theme at $src"; exit 1; }
pkg="$(mktemp -d)/gansbaai-radio.tar.gz"
tar -czf "$pkg" -C "$src/.." gansbaai-radio
echo "Package: $(du -h "$pkg" | cut -f1)"
ssh "$host" "[ -f '$wp/wp-config.php' ]" || { echo "Refusing: $wp is not a WordPress folder"; exit 1; }
scp -q "$pkg" "$host:/tmp/gansbaai-radio.tar.gz"
ssh "$host" "set -e; t='$wp/wp-content/themes'; rm -rf \"\$t/gansbaai-radio.new\"; mkdir -p \"\$t/gansbaai-radio.new\";
  tar -xzf /tmp/gansbaai-radio.tar.gz -C \"\$t/gansbaai-radio.new\" --strip-components=1; rm -f /tmp/gansbaai-radio.tar.gz;
  chown -R '$owner:$owner' \"\$t/gansbaai-radio.new\"; find \"\$t/gansbaai-radio.new\" -type d -exec chmod 755 {} +; find \"\$t/gansbaai-radio.new\" -type f -exec chmod 644 {} +;
  rm -rf \"\$t/gansbaai-radio.old\"; [ -d \"\$t/gansbaai-radio\" ] && mv \"\$t/gansbaai-radio\" \"\$t/gansbaai-radio.old\"; mv \"\$t/gansbaai-radio.new\" \"\$t/gansbaai-radio\"; rm -rf \"\$t/gansbaai-radio.old\"
  grep -m1 '^Version:' \"\$t/gansbaai-radio/style.css\""
echo "Deployed to $host:$wp/wp-content/themes/gansbaai-radio"
