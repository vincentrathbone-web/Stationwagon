#!/usr/bin/env bash
# Upload the prototypes to a cPanel account over SSH.
#   prototypes/tools/deploy-cpanel.sh <ssh-host> <remote-dir>
# Example:
#   prototypes/tools/deploy-cpanel.sh controll public_html/wordwave.controll.co.za/stationwagon-poc
#
# - Packs prototypes/ without runtime data, tests or local tools.
# - Gives the server its own control key in config.local.php. The key is made once and kept in
#   prototypes/tools/deploy/<ssh-host>.config.local.php (gitignored), so re-deploys keep it.
# - Replaces the code on the server but keeps its data/ folder (timeline, library, live pieces).
set -euo pipefail
host="${1:?ssh host alias, e.g. controll}"
dir="${2:?remote folder relative to the home dir}"
here="$(cd "$(dirname "$0")/.." && pwd)"
case "$dir" in
  *stationwagon*) ;;
  *) echo "Refusing: the remote folder must have 'stationwagon' in its name (it gets emptied on deploy)"; exit 1 ;;
esac
keyfile="$here/tools/deploy/$host.config.local.php"

mkdir -p "$here/tools/deploy"
if [ ! -f "$keyfile" ]; then
  key="sw-$(head -c 18 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  printf "<?php\n// Server-only settings for %s. Not committed.\nreturn [\n    'control_key' => '%s',\n];\n" "$host" "$key" > "$keyfile"
  echo "Made a new control key for $host in $keyfile"
fi

# Everything except runtime data (but keep data/.htaccess, which hides the state files), tests,
# local tools and any local config
pkg="$(mktemp -d)/stationwagon-poc.tar.gz"
(cd "$here" && {
  find . \( -path ./data -o -path ./tests -o -path ./tools -o -name config.local.php \) -prune -o -type f -print
  echo ./data/.htaccess
}) | tar -czf "$pkg" -C "$here" -T -
echo "Package: $(du -h "$pkg" | cut -f1)"

scp -q "$pkg" "$host:stationwagon-poc.tar.gz"
scp -q "$keyfile" "$host:stationwagon-poc.config.local.php"
ssh "$host" "set -e
  mkdir -p '$dir/data'
  find '$dir' -mindepth 1 -maxdepth 1 ! -name data -exec rm -rf {} +
  tar -xzf ~/stationwagon-poc.tar.gz -C '$dir'
  mv ~/stationwagon-poc.config.local.php '$dir/config.local.php'
  chmod 640 '$dir/config.local.php'
  chmod 775 '$dir/data'
  rm -f ~/stationwagon-poc.tar.gz
  echo \"Deployed to ~/$dir: \$(find '$dir' -type f | wc -l) files\""
rm -f "$pkg"
