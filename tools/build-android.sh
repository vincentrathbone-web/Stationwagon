#!/usr/bin/env bash
# Builds Stationwagon Studio for Android (mobile/): the APK, signed with the app's release key in
# ~/.android-keys/stationwagon-release.jks (its password in tools/deploy/android-key.local.txt, never
# committed). The first run makes the key: back it up (with the password) somewhere safe, because
# Android only installs an update signed with the same key. The APK lands in mobile/dist/ under the
# name a release uses: Stationwagon-Studio_<version>.apk
#   bash tools/build-android.sh [version] [test]
# A version overrides mobile/package.json's. "test" makes a build for mobile/tests/android_test.mjs
# only: the phone's WebView can be inspected over USB, and the test can set the station and the
# update address with adb; it goes to mobile/dist/test/ and is never released.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
keystore="$HOME/.android-keys/stationwagon-release.jks"
pwfile="$root/tools/deploy/android-key.local.txt"
export JAVA_HOME="${SW_JAVA_HOME:-C:\\Program Files\\Eclipse Adoptium\\jdk-21.0.12.101-hotspot}"
# Gradle's daemon talks over a Unix socket in a temp folder; the default one fails on this PC
mkdir -p /c/gtmp
export JAVA_TOOL_OPTIONS="-Djdk.net.unixdomain.tmpdir=C:\\gtmp"
keytool="$(cygpath -u "$JAVA_HOME")/bin/keytool"

if [ ! -f "$keystore" ]; then
  [ -f "$pwfile" ] && { echo "$pwfile exists but the key doesn't: put the key back at $keystore" >&2; exit 1; }
  mkdir -p "$(dirname "$keystore")" "$(dirname "$pwfile")"
  pw="$(head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 28)"
  "$keytool" -genkeypair -keystore "$(cygpath -w "$keystore")" -storetype PKCS12 -alias stationwagon -keyalg RSA -keysize 4096 \
    -validity 36500 -storepass "$pw" -keypass "$pw" -dname "CN=Stationwagon Studio, O=Controll, C=ZA" 2>&1 | grep -v JAVA_TOOL || true
  printf 'Android release key: %s\nKey password: %s\nBack both up somewhere safe: an update must be signed with this key.\n' "$keystore" "$pw" > "$pwfile"
  echo "Made a new release key at $keystore (password in $pwfile). Back them up."
fi
export SW_ANDROID_KEYSTORE="$(cygpath -w "$keystore")"
export SW_ANDROID_KEY_PASSWORD="$(sed -n 's/^Key password: //p' "$pwfile" | tr -d '\r\n')"

version="${1:-$(sed -n 's/^  "version": "\(.*\)",$/\1/p' "$root/mobile/package.json")}"
test="${2:-}"
cd "$root/mobile"
[ -d node_modules ] || npm install --no-audit --no-fund
npx cap sync android
cd android
args=(assembleRelease -q "-PswVersion=$version")
[ "$test" = "test" ] && args+=(-PswTest)
./gradlew.bat "${args[@]}" 2>&1 | { grep -v "Picked up JAVA_TOOL_OPTIONS" || true; }
apk="app/build/outputs/apk/release/app-release.apk"
[ -f "$apk" ] || { echo "The build made no APK" >&2; exit 1; }
dist="$root/mobile/dist${test:+/test}"
mkdir -p "$dist"
cp "$apk" "$dist/Stationwagon-Studio_${version}.apk"
rm -f "$apk"
ls -l "$dist/Stationwagon-Studio_${version}.apk"
