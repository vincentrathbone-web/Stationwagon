#!/usr/bin/env bash
# Builds Stationwagon Studio for Windows (desktop/): the NSIS installer, signed for the updater with
# the key in ~/.tauri-keys/stationwagon.key (its password in tools/deploy/updater-key.local.txt, never
# committed). The installer and its .sig land in desktop/dist/ under the names a release uses:
#   Stationwagon-Studio_<version>_x64-setup.exe and Stationwagon-Studio_<version>_x64-setup.exe.sig
#   bash tools/build-desktop.sh [version] [test]
# A version overrides tauri.conf.json's. "test" makes a build for desktop/tests/update_test.mjs only:
# its updater may also use plain http (the test's stand-in server on 127.0.0.1); it goes to
# desktop/dist/test/ and is never released.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
keyfile="$HOME/.tauri-keys/stationwagon.key"
pwfile="$root/tools/deploy/updater-key.local.txt"
[ -f "$keyfile" ] || { echo "No signing key at $keyfile" >&2; exit 1; }
[ -f "$pwfile" ] || { echo "No key password file at $pwfile" >&2; exit 1; }
export TAURI_SIGNING_PRIVATE_KEY="$(cat "$keyfile")"
export TAURI_SIGNING_PRIVATE_KEY_PASSWORD="$(sed -n 's/^Key password: //p' "$pwfile" | tr -d '\r\n')"
cd "$root/desktop/src-tauri"
version="${1:-}"
test="${2:-}"
args=(build --target x86_64-pc-windows-msvc)
if [ -n "$version" ]; then args+=(--config "{\"version\":\"$version\"}"); fi
if [ "$test" = "test" ]; then args+=(--config '{"plugins":{"updater":{"dangerousInsecureTransportProtocol":true}}}'); fi
cargo tauri "${args[@]}"
version="${version:-$(sed -n 's/^  "version": "\(.*\)",$/\1/p' tauri.conf.json)}"
out="target/x86_64-pc-windows-msvc/release/bundle/nsis"
dist="$root/desktop/dist${test:+/test}"
mkdir -p "$dist"
src="$(ls "$out"/*_"$version"_x64-setup.exe | head -1)"
cp "$src" "$dist/Stationwagon-Studio_${version}_x64-setup.exe"
cp "$src.sig" "$dist/Stationwagon-Studio_${version}_x64-setup.exe.sig"
ls -l "$dist/Stationwagon-Studio_${version}_x64-setup.exe"*
