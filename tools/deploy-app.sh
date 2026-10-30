#!/usr/bin/env bash
# Upload the app (app/) to a cPanel account over SSH.
#   tools/deploy-app.sh <ssh-host> <remote-dir>
# Example:
#   tools/deploy-app.sh controll public_html/wordwave.controll.co.za/radio
#
# - Replaces the code but keeps the server's config.local.php (written by the installer) and
#   storage/ (songs, live pieces), so re-deploys never touch a running station.
# - Until the copy is installed, leaves a one-time install.key next to index.php. The web installer
#   asks for it, so nobody else can run setup first. A copy of the key is kept in
#   tools/deploy/<ssh-host>.install-key.local.txt (gitignored); setup deletes the server's copy.
set -euo pipefail
host="${1:?ssh host alias, e.g. controll}"
dir="${2:?remote folder relative to the home dir}"
repo="$(cd "$(dirname "$0")/.." && pwd)"
app="$repo/app"
# The folder gets emptied on deploy (apart from storage/ and the settings), so it must be new, empty,
# named for Stationwagon, or already hold a Stationwagon station (its VERSION and src/Installer.php)
case "$dir" in
  *stationwagon*) ;;
  *) if ! ssh "$host" "[ ! -e '$dir' ] || [ -z \"\$(ls -A '$dir')\" ] || { [ -f '$dir/VERSION' ] && [ -f '$dir/src/Installer.php' ]; }"; then
       echo "Refusing: ~/$dir holds something other than a Stationwagon station (it gets emptied on deploy)"; exit 1
     fi ;;
esac
keyfile="$repo/tools/deploy/$host.install-key.local.txt"
mkdir -p "$repo/tools/deploy"
if [ ! -f "$keyfile" ]; then
  head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n' > "$keyfile"
fi

# Code only: no settings, setup key, uploaded media or local notes
pkg="$(mktemp -d)/stationwagon-app.tar.gz"
(cd "$app" && {
  find . \( -path ./storage -o -name config.local.php -o -name 'config.local.php.*' -o -name install.key -o -name '*.local.txt' \) -prune -o -type f -print
  echo ./storage/.htaccess
}) | tar -czf "$pkg" -C "$app" -T -
echo "Package: $(du -h "$pkg" | cut -f1), version $(cat "$app/VERSION")"

scp -q "$pkg" "$host:stationwagon-app.tar.gz"
scp -q "$keyfile" "$host:stationwagon-app.install.key"
ssh "$host" "set -e
  mkdir -p '$dir/storage'
  find '$dir' -mindepth 1 -maxdepth 1 ! -name storage ! -name config.local.php -exec rm -rf {} +
  tar -xzf ~/stationwagon-app.tar.gz -C '$dir'
  chmod 775 '$dir/storage'
  if [ -f '$dir/config.local.php' ] && grep -q installed_at '$dir/config.local.php'; then
    rm -f ~/stationwagon-app.install.key
    echo 'Already installed: settings and storage kept'
    /usr/local/bin/php '$dir/cli/upgrade.php'
  else
    mv ~/stationwagon-app.install.key '$dir/install.key'
    chmod 600 '$dir/install.key'
    echo 'Not installed yet: install.key placed for the web installer'
  fi
  rm -f ~/stationwagon-app.tar.gz
  echo \"Deployed to ~/$dir: \$(find '$dir' -type f | wc -l) files\""
rm -f "$pkg"
