#!/usr/bin/env bash
# Builds a slim FFmpeg 7.1 to WebAssembly for song prep: app/assets/vendor/ffmpeg-decode-7.1.js, one file
# (the .wasm is inside it), exposing SwDecode() -> Promise of the module with sw_dec_open / sw_dec_run /
# sw_dec_info / sw_dec_tag / sw_dec_codec / sw_dec_close (tools/ffmpeg-wasm/swdecode.c). It decodes every
# upload that isn't an MP3, with its tags and cover, the same in every browser: WAV, FLAC, AAC and ALAC
# (M4A, raw AAC), Ogg Vorbis, Opus and FLAC, WMA (all four kinds). No encoders, filters or network. In the
# browser MP3s keep the browser's own decoder (Chrome and Firefox use this same mp3float decoder inside), and
# this one is loaded only for the other formats; on the server (round 85, cli/prep-node.mjs) it decodes MP3s too.
#   bash tools/build-ffmpeg-wasm.sh [ffmpeg source folder] [emsdk folder] [make folder]
# FFmpeg's source: https://ffmpeg.org/releases/ffmpeg-7.1.3.tar.xz
# (SHA-256 f0bf043299db9e3caacb435a712fc541fbb07df613c4b893e8b77e67baf3adbe), unpacked. Emscripten as for
# build-lame-wasm.sh. On Windows, GNU make (ezwinports make-4.4.1-without-guile-w32-bin.zip, unpacked) and
# Git Bash run FFmpeg's configure.
# FFmpeg built this way (no --enable-gpl, no --enable-nonfree) is LGPL-2.1-or-later: its licence goes beside
# the build (ffmpeg-7.1-LICENSE.txt), and the build's own source and this script are in the repo.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
root="$(dirname "$here")"
src="${1:-D:/Dev/toolshed/src/ffmpeg-7.1.3}"
emsdk="${2:-D:/Dev/toolshed/emsdk}"
make_dir="${3:-D:/Dev/toolshed/make/bin}"
build="${SW_BUILD:-D:/Dev/toolshed/build/ffmpeg-wasm}"
out="$root/app/assets/vendor"
[ -f "$src/configure" ] || { echo "No FFmpeg source at $src"; exit 1; }
export EMSDK="$emsdk"
em="$emsdk/upstream/emscripten"
u() { cygpath -u "$1" 2>/dev/null || echo "$1"; }
py="$(ls -d "$emsdk"/python/*/ 2>/dev/null | head -1)" # emsdk's own Python (emcc needs one)
[ -n "$py" ] && export EMSDK_PYTHON="${py}python.exe"
export PATH="$(u "$make_dir"):$(u "$em"):$(u "$emsdk/upstream/bin")${py:+:$(u "$py")}:$PATH"
mkdir -p "$build/tmp"
export TMPDIR="$(u "$build")/tmp" # configure needs a POSIX temp path
cd "$build"
if [ ! -f ffbuild/config.mak ] || [ "${SW_RECONFIGURE:-0}" = 1 ]; then
  "$src/configure" \
    --target-os=none --arch=x86_32 --enable-cross-compile \
    --cc=emcc --cxx=em++ --ar=emar --ranlib=emranlib --nm=llvm-nm --host-cc=emcc \
    --extra-cflags="-O3" \
    --disable-asm --disable-inline-asm --disable-x86asm --disable-stripping --disable-debug \
    --disable-programs --disable-doc --disable-runtime-cpudetect --disable-autodetect \
    --disable-network --disable-pthreads --disable-w32threads --disable-os2threads \
    --disable-everything --disable-avdevice --disable-swscale --disable-postproc --disable-avfilter \
    --enable-protocol=file \
    --enable-demuxer=asf,ogg,mov,flac,wav,aac,mp3 \
    --enable-decoder=mp3float,wmav1,wmav2,wmapro,wmalossless,vorbis,opus,flac,alac,aac,pcm_u8,pcm_s16le,pcm_s24le,pcm_s32le,pcm_f32le,pcm_f64le \
    --enable-parser=vorbis,opus,flac,aac,mpegaudio
  # a Windows make reads D:/ paths, not Git Bash's /d/ ones
  w() { cygpath -m "$1" 2>/dev/null || echo "$1"; }
  for f in Makefile ffbuild/config.mak; do
    sed -i "s#$(u "$src")#$(w "$src")#g; s#$(u "$build")#$(w "$build")#g" "$f"
  done
fi
libs="libavformat/libavformat.a libavcodec/libavcodec.a libswresample/libswresample.a libavutil/libavutil.a"
make -j"${SW_JOBS:-8}" $libs
emcc -O3 -I"$build" -I"$src" "$here/ffmpeg-wasm/swdecode.c" \
  $libs \
  -s WASM=1 -s SINGLE_FILE=1 -s MODULARIZE=1 -s EXPORT_NAME=SwDecode -s ALLOW_MEMORY_GROWTH=1 \
  -s INITIAL_MEMORY=32MB -s MAXIMUM_MEMORY=4GB -s ENVIRONMENT=web,worker,node -s FILESYSTEM=0 \
  -s EXPORTED_FUNCTIONS=_sw_dec_open,_sw_dec_run,_sw_dec_close,_sw_dec_info,_sw_dec_tag,_sw_dec_codec,_sw_dec_version,_malloc,_free \
  -s EXPORTED_RUNTIME_METHODS=HEAPU8,HEAPF32,HEAP32,UTF8ToString \
  -o "$out/ffmpeg-decode-7.1.js"
cp "$src/COPYING.LGPLv2.1" "$out/ffmpeg-7.1-LICENSE.txt"
ls -l "$out/ffmpeg-decode-7.1.js"
