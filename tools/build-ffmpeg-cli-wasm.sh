#!/usr/bin/env bash
# Test tool, not part of the app: FFmpeg 7.1's own `ffmpeg` command built to WebAssembly for Node (it reads
# and writes files directly), so the upload tests can make their samples in every format from the lossless
# test music: WMA (v2 and Pro is decode-only, so WMA v2), Ogg Vorbis, Opus, FLAC, ALAC and AAC in M4A, WAV.
#   bash tools/build-ffmpeg-cli-wasm.sh [ffmpeg source folder] [emsdk folder] [make folder]
#   node D:/Dev/toolshed/ffmpeg-cli/ffmpeg.js -i in.flac -c:a wmav2 -b:a 192k out.wma
# The ffmpeg program needs threads (Node workers). Same sources and tools as build-ffmpeg-wasm.sh. Output: D:/Dev/toolshed/ffmpeg-cli/ffmpeg.js (+ .wasm).
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
src="${1:-D:/Dev/toolshed/src/ffmpeg-7.1.3}"
emsdk="${2:-D:/Dev/toolshed/emsdk}"
make_dir="${3:-D:/Dev/toolshed/make/bin}"
build="${SW_BUILD:-D:/Dev/toolshed/build/ffmpeg-cli-wasm}"
out="${SW_OUT:-D:/Dev/toolshed/ffmpeg-cli}"
[ -f "$src/configure" ] || { echo "No FFmpeg source at $src"; exit 1; }
export EMSDK="$emsdk"
u() { cygpath -u "$1" 2>/dev/null || echo "$1"; }
w() { cygpath -m "$1" 2>/dev/null || echo "$1"; }
py="$(ls -d "$emsdk"/python/*/ 2>/dev/null | head -1)"
[ -n "$py" ] && export EMSDK_PYTHON="${py}python.exe"
export PATH="$(u "$make_dir"):$(u "$emsdk/upstream/emscripten"):$(u "$emsdk/upstream/bin")${py:+:$(u "$py")}:$PATH"
mkdir -p "$build/tmp" "$build/fftools/textformat" "$build/fftools/resources" "$out"
export TMPDIR="$(u "$build")/tmp"
cd "$build"
if [ ! -f ffbuild/config.mak ] || [ "${SW_RECONFIGURE:-0}" = 1 ]; then
  "$src/configure" \
    --target-os=none --arch=x86_32 --enable-cross-compile \
    --cc=emcc --cxx=em++ --ar=emar --ranlib=emranlib --nm=llvm-nm --host-cc=emcc \
    --extra-cflags="-O2 -pthread" --extra-ldflags="-pthread -sPROXY_TO_PTHREAD=1 -sPTHREAD_POOL_SIZE=12 -sNODERAWFS=1 -sALLOW_MEMORY_GROWTH=1 -sINITIAL_MEMORY=256MB -sEXIT_RUNTIME=1" \
    --disable-asm --disable-inline-asm --disable-x86asm --disable-stripping --disable-debug \
    --disable-doc --disable-runtime-cpudetect --disable-autodetect --disable-network \
    --enable-pthreads --disable-w32threads --disable-os2threads \
    --disable-everything --disable-avdevice --disable-swscale --disable-postproc \
    --disable-ffplay --disable-ffprobe --enable-ffmpeg \
    --enable-protocol=file,pipe \
    --enable-demuxer=flac,wav,image2,mjpeg,asf,ogg,mov,mp3 \
    --enable-muxer=asf,ogg,opus,ipod,mp4,wav,flac,mp3 \
    --enable-decoder=flac,pcm_s16le,pcm_s24le,mjpeg,png,wmav2,vorbis,opus,alac,aac,mp3 \
    --enable-encoder=wmav2,vorbis,opus,flac,alac,aac,pcm_s16le,pcm_s24le,pcm_f32le,mjpeg,png \
    --enable-parser=flac,vorbis,opus,aac,mjpeg,png \
    --enable-filter=abuffer,abuffersink,aformat,anull,aresample,atrim,buffer,buffersink,format,null,scale
  for f in Makefile ffbuild/config.mak; do
    sed -i "s#$(u "$src")#$(w "$src")#g; s#$(u "$build")#$(w "$build")#g" "$f"
  done
fi
make -j"${SW_JOBS:-8}" ffmpeg_g
cp ffmpeg_g "$out/ffmpeg.js"
[ -f ffmpeg_g.wasm ] && cp ffmpeg_g.wasm "$out/ffmpeg_g.wasm"
ls -l "$out"
