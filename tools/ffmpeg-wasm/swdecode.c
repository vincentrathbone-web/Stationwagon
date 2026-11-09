// Song prep's own decoder (tools/build-ffmpeg-wasm.sh): decodes a whole file held in memory to float
// samples at its own rate, for every upload that isn't an MP3 (WAV, FLAC, M4A, AAC, Ogg, Opus, WMA).
// One file at a time:
//   sw_dec_open(data, size)  -> 0, or a negative error (not audio, no decoder for it)
//   sw_dec_run()             -> the number of sample frames decoded (all of them), or a negative error
//   sw_dec_info(what)        -> 0: sample rate, 1: channels (1 or 2 kept), 2: codec id, 3: frames,
//                               4: pointer to channel 0's floats, 5: pointer to channel 1's (or 0's),
//                               6: pointer to the cover picture (0 if none), 7: its size in bytes
//   sw_dec_tag(key)          -> the tag's text ("title", "artist", "album", ...) or 0
//   sw_dec_codec()           -> the decoder's name ("wmav2", "vorbis", ...)
//   sw_dec_close()           -> frees everything (the caller frees the data it passed)
#include <stdlib.h>
#include <string.h>
#include "libavformat/avformat.h"
#include "libavcodec/avcodec.h"
#include "libavutil/avutil.h"
#include "libavutil/dict.h"
#include "libavutil/opt.h"

static const uint8_t *mem; static int64_t memSize, memPos;
static AVFormatContext *fmt; static AVIOContext *io; static AVCodecContext *dec; static int audio = -1;
static float *out[2]; static int64_t frames, cap; static int channels;
static const uint8_t *pic; static int picSize;

static int readMem(void *opaque, uint8_t *buf, int n) {
  (void) opaque;
  int64_t left = memSize - memPos;
  if (left <= 0) return AVERROR_EOF;
  if (n > left) n = (int) left;
  memcpy(buf, mem + memPos, n);
  memPos += n;
  return n;
}
static int64_t seekMem(void *opaque, int64_t off, int whence) {
  (void) opaque;
  if (whence == AVSEEK_SIZE) return memSize;
  whence &= ~AVSEEK_FORCE;
  int64_t to = whence == SEEK_SET ? off : whence == SEEK_CUR ? memPos + off : whence == SEEK_END ? memSize + off : -1;
  if (to < 0 || to > memSize) return -1;
  return memPos = to;
}

void sw_dec_close(void) {
  if (dec) avcodec_free_context(&dec);
  if (fmt) avformat_close_input(&fmt);
  if (io) { av_freep(&io->buffer); avio_context_free(&io); }
  free(out[0]); free(out[1]); out[0] = out[1] = NULL;
  frames = cap = 0; channels = 0; audio = -1; pic = NULL; picSize = 0; mem = NULL;
}

int sw_dec_open(const uint8_t *data, int size) {
  sw_dec_close();
  av_log_set_level(AV_LOG_QUIET);
  mem = data; memSize = size; memPos = 0;
  unsigned char *buf = av_malloc(65536);
  io = avio_alloc_context(buf, 65536, 0, NULL, readMem, NULL, seekMem);
  fmt = avformat_alloc_context();
  fmt->pb = io;
  fmt->flags |= AVFMT_FLAG_CUSTOM_IO;
  int r = avformat_open_input(&fmt, NULL, NULL, NULL);
  if (r < 0) { fmt = NULL; return -1; }
  if (avformat_find_stream_info(fmt, NULL) < 0) return -2;
  for (unsigned i = 0; i < fmt->nb_streams; i++) {
    AVStream *s = fmt->streams[i];
    if ((s->disposition & AV_DISPOSITION_ATTACHED_PIC) && s->attached_pic.size > 0 && !pic) { pic = s->attached_pic.data; picSize = s->attached_pic.size; }
  }
  audio = av_find_best_stream(fmt, AVMEDIA_TYPE_AUDIO, -1, -1, NULL, 0);
  if (audio < 0) return -3;
  const AVCodec *c = avcodec_find_decoder(fmt->streams[audio]->codecpar->codec_id);
  if (!c) return -4;
  dec = avcodec_alloc_context3(c);
  if (avcodec_parameters_to_context(dec, fmt->streams[audio]->codecpar) < 0) return -5;
  if (avcodec_open2(dec, c, NULL) < 0) return -6;
  channels = dec->ch_layout.nb_channels > 1 ? 2 : 1;
  return 0;
}

static float sampleAt(const AVFrame *f, int ch, int i) {
  int planar = av_sample_fmt_is_planar(f->format), n = f->ch_layout.nb_channels;
  const uint8_t *p = planar ? f->extended_data[ch] : f->extended_data[0];
  int k = planar ? i : i * n + ch;
  switch (f->format) {
    case AV_SAMPLE_FMT_FLT: case AV_SAMPLE_FMT_FLTP: return ((const float *) p)[k];
    case AV_SAMPLE_FMT_DBL: case AV_SAMPLE_FMT_DBLP: return (float) ((const double *) p)[k];
    case AV_SAMPLE_FMT_S16: case AV_SAMPLE_FMT_S16P: return ((const int16_t *) p)[k] / 32768.0f;
    case AV_SAMPLE_FMT_S32: case AV_SAMPLE_FMT_S32P: return (float) (((const int32_t *) p)[k] / 2147483648.0);
    case AV_SAMPLE_FMT_U8: case AV_SAMPLE_FMT_U8P: return (((const uint8_t *) p)[k] - 128) / 128.0f;
    default: return 0;
  }
}

static int take(const AVFrame *f) {
  int n = f->nb_samples;
  if (frames + n > cap) {
    int64_t want = cap ? cap * 2 : 1 << 20;
    while (want < frames + n) want *= 2;
    for (int c = 0; c < channels; c++) {
      float *p = realloc(out[c], (size_t) want * sizeof(float));
      if (!p) return -1;
      out[c] = p;
    }
    cap = want;
  }
  int src1 = f->ch_layout.nb_channels > 1 ? 1 : 0;
  for (int i = 0; i < n; i++) {
    out[0][frames + i] = sampleAt(f, 0, i);
    if (channels > 1) out[1][frames + i] = sampleAt(f, src1, i);
  }
  frames += n;
  return 0;
}

static int drain(AVFrame *f) {
  int r;
  while ((r = avcodec_receive_frame(dec, f)) >= 0) {
    if (take(f) < 0) return -7;
    av_frame_unref(f);
  }
  (void) r;
  return 0; // a bad frame is skipped, as players do
}

int sw_dec_run(void) {
  if (!dec) return -1;
  AVPacket *pk = av_packet_alloc();
  AVFrame *f = av_frame_alloc();
  int bad = 0, r = 0;
  while (av_read_frame(fmt, pk) >= 0) {
    if (pk->stream_index == audio) {
      if (avcodec_send_packet(dec, pk) < 0) bad++;
      if ((r = drain(f)) < 0) break;
    }
    av_packet_unref(pk);
  }
  if (r >= 0) { avcodec_send_packet(dec, NULL); r = drain(f); }
  av_frame_free(&f);
  av_packet_free(&pk);
  if (r < 0) return r;
  return frames > 0 ? (int) frames : -8;
}

int sw_dec_info(int what) {
  switch (what) {
    case 0: return dec ? dec->sample_rate : 0;
    case 1: return channels;
    case 2: return dec ? (int) dec->codec_id : 0;
    case 3: return (int) frames;
    case 4: return (int) (intptr_t) out[0];
    case 5: return (int) (intptr_t) (channels > 1 ? out[1] : out[0]);
    case 6: return (int) (intptr_t) pic;
    case 7: return picSize;
    default: return 0;
  }
}

const char *sw_dec_tag(const char *key) {
  if (!fmt) return NULL;
  AVDictionaryEntry *e = av_dict_get(fmt->metadata, key, NULL, 0);
  if (!e && audio >= 0) e = av_dict_get(fmt->streams[audio]->metadata, key, NULL, 0);
  return e ? e->value : NULL;
}

const char *sw_dec_codec(void) { return dec && dec->codec ? dec->codec->name : ""; }
const char *sw_dec_version(void) { return av_version_info(); }
