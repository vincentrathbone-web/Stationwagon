#!/usr/bin/env bash
# Builds LAME 3.100 to WebAssembly for song prep: app/assets/vendor/lame-3.100.js, one file (the
# .wasm is inside it, so a worker loads it with importScripts and Node with require), exposing
# SwLame() -> Promise of the module with sw_open / sw_encode / sw_flush / sw_close (tools/lame-wasm/swlame.c).
#   bash tools/build-lame-wasm.sh [lame source folder] [emsdk folder]
# LAME's source: https://sourceforge.net/projects/lame/files/lame/3.100/lame-3.100.tar.gz
# (SHA-256 ddfe36cab873794038ae2c1210557ad34857a4b6bdc515785d1da9e175b1da1e), unpacked. Emscripten
# from https://github.com/emscripten-core/emsdk (emsdk install latest; emsdk activate latest).
# LAME is LGPL-2.0-or-later: its licence goes beside the build (lame-3.100-LICENSE.txt), and the
# build's own source and this script are in the repo, so anyone can rebuild or replace it.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
root="$(dirname "$here")"
src="${1:-D:/Dev/toolshed/src/lame-3.100}"
emsdk="${2:-D:/Dev/toolshed/emsdk}"
out="$root/app/assets/vendor"
emcc="$emsdk/upstream/emscripten/emcc.bat"
[ -f "$src/include/lame.h" ] || { echo "No LAME source at $src"; exit 1; }
[ -f "$emcc" ] || emcc="$emsdk/upstream/emscripten/emcc"
export EMSDK="$emsdk"
# the libmp3lame sources the library needs (no decoder, no SIMD versions: WebAssembly has neither)
files=()
for f in VbrTag bitstream encoder fft gain_analysis id3tag lame newmdct presets psymodel quantize quantize_pvt \
         reservoir set_get tables takehiro util vbrquantize version; do
  files+=("$src/libmp3lame/$f.c")
done
"$emcc" -O3 -DHAVE_CONFIG_H -I"$here/lame-wasm" -I"$src/include" -I"$src/libmp3lame" \
  "${files[@]}" "$here/lame-wasm/swlame.c" \
  -s WASM=1 -s SINGLE_FILE=1 -s MODULARIZE=1 -s EXPORT_NAME=SwLame -s ALLOW_MEMORY_GROWTH=1 \
  -s ENVIRONMENT=web,worker,node -s FILESYSTEM=0 \
  -s EXPORTED_FUNCTIONS=_sw_open,_sw_encode,_sw_flush,_sw_close,_sw_get,_sw_version,_malloc,_free \
  -s EXPORTED_RUNTIME_METHODS=HEAPU8,HEAP16,UTF8ToString \
  -o "$out/lame-3.100.js"
cp "$src/COPYING" "$out/lame-3.100-LICENSE.txt"
ls -l "$out/lame-3.100.js"
