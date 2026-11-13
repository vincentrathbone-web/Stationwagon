#!/usr/bin/env bash
# Put a station's own logo (round 83) on its server: the version from its branding drawn for dark backgrounds.
# The app shows it in the header next to the Stationwagon logo (App::stationLogo), from storage/brand/logo.<svg|png|webp>,
# which deploys never touch.
#   bash tools/deploy-station-logo.sh <ssh-host> <station folder> <logo file> [owner]
# Examples (HestiaCP, signing in as root, so the folder's owner is given):
#   bash tools/deploy-station-logo.sh vmcloud1 /home/controll1/web/gbcr.controll.co.za/public_html/radio stations/gansbaai/brand/svg/gbcr-logo-horizontal.svg controll1
#   bash tools/deploy-station-logo.sh vmcloud1 /home/wordwaveco/web/wordwave.co.za/public_html/radio stations/wordwave/brand/wordwave-logo-horizontal.svg wordwaveco
# On Laragon, copy the file to app/storage/brand/logo.svg instead.
set -euo pipefail
host="${1:?ssh host alias}"
dir="${2:?the station folder (where VERSION is)}"
file="${3:?the logo file (svg, png or webp)}"
owner="${4:-}"
ext="${file##*.}"
case "$ext" in svg|png|webp) ;; *) echo "The logo must be an .svg, .png or .webp file"; exit 1 ;; esac
[ -f "$file" ] || { echo "No file at $file"; exit 1; }
ssh "$host" "[ -f '$dir/VERSION' ] && [ -f '$dir/src/Installer.php' ]" || { echo "Refusing: $dir is not a Stationwagon station"; exit 1; }
scp -q "$file" "$host:/tmp/sw-station-logo.$ext"
ssh "$host" "set -e; b='$dir/storage/brand'; mkdir -p \"\$b\"; rm -f \"\$b/logo.svg\" \"\$b/logo.png\" \"\$b/logo.webp\"
  mv /tmp/sw-station-logo.$ext \"\$b/logo.$ext\"; chmod 755 \"\$b\"; chmod 644 \"\$b/logo.$ext\"
  if [ -n '$owner' ]; then chown -R '$owner:$owner' \"\$b\"; fi
  ls -l \"\$b\""
echo "Station logo placed at $host:$dir/storage/brand/logo.$ext"
