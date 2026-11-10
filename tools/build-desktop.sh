#!/usr/bin/env bash
# Builds Stationwagon Studio for Windows (desktop/): the NSIS installer, signed for the updater with
# the key in ~/.tauri-keys/stationwagon.key (its password in tools/deploy/updater-key.local.txt, never
# committed). The installer and its .sig land in desktop/dist/ under the names a release uses:
#   Stationwagon-Studio_<version>_x64-setup.exe and Stationwagon-Studio_<version>_x64-setup.exe.sig
#   bash tools/build-desktop.sh [version] [test]
# A version overrides tauri.conf.json's. "test" makes a build for desktop/tests/update_test.mjs only:
# its updater may also use plain http (the test's stand-in server on 127.0.0.1); it goes to
# desktop/dist/test/ and is never released.
# Tools: `cargo tauri` with the MSVC toolchain when it is installed; otherwise (this PC since 2026-11-10)
# the GNU toolchain in D:/Dev/toolshed (SW_TOOLSHED: cargo, rustup, winlibs' mingw64) with the npm
# Tauri CLI matching the app's Tauri (2.12.4 in toolshed/tauri-cli; SW_TAURI_CLI, its tauri.js; an older CLI
# makes an installer that doesn't restart the app after an update). The GNU build runs from a drive letter made with subst
# (SW_SUBST, default W:), because the Windows resource compiler can't handle the space in "VS Code".
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
keyfile="$HOME/.tauri-keys/stationwagon.key"
pwfile="$root/tools/deploy/updater-key.local.txt"
[ -f "$keyfile" ] || { echo "No signing key at $keyfile" >&2; exit 1; }
[ -f "$pwfile" ] || { echo "No key password file at $pwfile" >&2; exit 1; }
export TAURI_SIGNING_PRIVATE_KEY="$(cat "$keyfile")"
export TAURI_SIGNING_PRIVATE_KEY_PASSWORD="$(sed -n 's/^Key password: //p' "$pwfile" | tr -d '\r\n')"
version="${1:-}"
test="${2:-}"
conf_version="$(sed -n 's/^  "version": "\(.*\)",$/\1/p' "$root/desktop/src-tauri/tauri.conf.json")"
version_out="${version:-$conf_version}"
extra=()
if [ -n "$version" ]; then extra+=(--config "{\"version\":\"$version\"}"); fi
if [ "$test" = "test" ]; then extra+=(--config '{"plugins":{"updater":{"dangerousInsecureTransportProtocol":true}}}'); fi

if command -v cargo >/dev/null 2>&1 && cargo tauri --version >/dev/null 2>&1; then
  target=x86_64-pc-windows-msvc
  cd "$root/desktop/src-tauri"
  cargo tauri build --target "$target" "${extra[@]}"
  out="$root/desktop/src-tauri/target/$target/release/bundle/nsis"
else
  shed="${SW_TOOLSHED:-D:/Dev/toolshed}"
  cli="${SW_TAURI_CLI:-$shed/tauri-cli/node_modules/@tauri-apps/cli/tauri.js}"
  [ -f "$cli" ] || { echo "No Tauri CLI at $cli (set SW_TAURI_CLI)" >&2; exit 1; }
  export RUSTUP_HOME="$shed/rustup" CARGO_HOME="$shed/cargo"
  export PATH="$(cygpath -u "$shed")/cargo/bin:$(cygpath -u "$shed")/winlibs/mingw64/bin:$PATH"
  target=x86_64-pc-windows-gnu
  drive="${SW_SUBST:-W:}"
  [ -d "$drive/" ] && { echo "The drive $drive is in use: free it (subst $drive /d) or set SW_SUBST" >&2; exit 1; }
  subst "$drive" "$(cygpath -w "$root/desktop")"
  trap 'cd /; MSYS2_ARG_CONV_EXCL="*" subst "$drive" /d' EXIT
  cd "$drive/src-tauri"
  node "$cli" build --target "$target" "${extra[@]}"
  out="$root/desktop/src-tauri/target/$target/release/bundle/nsis"
fi
dist="$root/desktop/dist${test:+/test}"
mkdir -p "$dist"
src="$(ls "$out"/*_"$version_out"_x64-setup.exe | head -1)"
cp "$src" "$dist/Stationwagon-Studio_${version_out}_x64-setup.exe"
cp "$src.sig" "$dist/Stationwagon-Studio_${version_out}_x64-setup.exe.sig"
ls -l "$dist/Stationwagon-Studio_${version_out}_x64-setup.exe"*
