// A small encoder API over LAME 3.100 for song prep in the browser (built to WebAssembly by
// tools/build-lame-wasm.sh into app/assets/vendor/lame-3.100.js). One encoder per song:
//   sw_open(rate, channels, kbps, quality, reservoir) -> handle (0 on failure)
//   sw_encode(handle, left, right, samples, out, outSize) -> bytes written (16-bit PCM in)
//   sw_flush(handle, out, outSize) -> bytes written
//   sw_close(handle)
// CBR at kbps; quality is LAME's -q (0 best and slowest .. 9 fastest); reservoir 1 keeps LAME's bit
// reservoir on (as the lame command line does), 0 turns it off. The output rate is the input rate:
// LAME never resamples (song prep has already chosen the rate). No Xing/LAME tag frame is written,
// so every frame carries sound (the station's frame scanner counts frames for time). LAME's own
// gain cut for low bit rates is undone (see sw_open): the MP3 is as loud as the samples given.
#include <stdlib.h>
#include <emscripten/emscripten.h>
#include "lame.h"

static lame_t open_with(int rate, int channels, int kbps, int quality, int reservoir, float scale)
{
    lame_t g = lame_init();
    if (!g) return 0;
    lame_set_in_samplerate(g, rate);
    lame_set_out_samplerate(g, rate);
    lame_set_num_channels(g, channels);
    lame_set_mode(g, channels == 1 ? MONO : JOINT_STEREO);
    lame_set_VBR(g, vbr_off);
    lame_set_brate(g, kbps);
    lame_set_quality(g, quality);
    lame_set_disable_reservoir(g, reservoir ? 0 : 1);
    lame_set_bWriteVbrTag(g, 0);
    lame_set_write_id3tag_automatic(g, 0);
    lame_set_findReplayGain(g, 0);
    lame_set_scale(g, scale);
    if (lame_init_params(g) < 0) {
        lame_close(g);
        return 0;
    }
    return g;
}

/*
 * Unity gain. For CBR and ABR, LAME multiplies the input by a "scale" from its preset table
 * (presets.c, abr_switch_map: 0.95 up to 160 kbps, 0.97 at 192, 0.98 at 224, 1.0 from 256) to keep
 * low bit rates from clipping on decode. Song prep levels each song to the station's loudness and
 * keeps its peaks under its own ceiling, so that cut only makes every song 0.2-0.45 dB quieter than
 * it was levelled to. The encoder is opened once to learn the scale LAME settles on, then again
 * with its inverse, so what goes in is what comes out.
 */
EMSCRIPTEN_KEEPALIVE
lame_t sw_open(int rate, int channels, int kbps, int quality, int reservoir)
{
    lame_t g = open_with(rate, channels, kbps, quality, reservoir, 1.0f);
    if (!g) return 0;
    float s = lame_get_scale(g);
    if (s > 0.0f && s != 1.0f) {
        lame_close(g);
        g = open_with(rate, channels, kbps, quality, reservoir, 1.0f / s);
    }
    return g;
}

EMSCRIPTEN_KEEPALIVE
int sw_encode(lame_t g, const short *left, const short *right, int samples, unsigned char *out, int outSize)
{
    return lame_encode_buffer(g, left, right, samples, out, outSize);
}

EMSCRIPTEN_KEEPALIVE
int sw_flush(lame_t g, unsigned char *out, int outSize)
{
    return lame_encode_flush(g, out, outSize);
}

EMSCRIPTEN_KEEPALIVE
void sw_close(lame_t g)
{
    lame_close(g);
}

/** The settings LAME ended up with, for tests: quality, reservoir off, lowpass Hz, out rate, kbps, delay, scale x 10000. */
EMSCRIPTEN_KEEPALIVE
int sw_get(lame_t g, int what)
{
    switch (what) {
        case 0: return lame_get_quality(g);
        case 1: return lame_get_disable_reservoir(g);
        case 2: return lame_get_lowpassfreq(g);
        case 3: return lame_get_out_samplerate(g);
        case 4: return lame_get_brate(g);
        case 5: return lame_get_encoder_delay(g);
        case 6: return (int) (lame_get_scale(g) * 10000.0f + 0.5f); /* the scale LAME applies, x 10000 */
        default: return -1;
    }
}

EMSCRIPTEN_KEEPALIVE
const char *sw_version(void)
{
    return get_lame_version();
}
