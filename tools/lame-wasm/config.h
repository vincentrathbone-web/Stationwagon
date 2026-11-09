/* LAME 3.100's config.h for the WebAssembly build (tools/build-lame-wasm.sh): what Emscripten's
 * libc has. No decoder (mpglib), no frontend. USE_FAST_LOG is left out on purpose, so LAME's
 * psychoacoustics use the exact log() (slower, the most accurate). TAKEHIRO_IEEE754_HACK is LAME's
 * usual exact float rounding trick on IEEE 754 machines, as in its standard builds. */
#define STDC_HEADERS 1
#define HAVE_STDINT_H 1
#define HAVE_INTTYPES_H 1
#define HAVE_STDLIB_H 1
#define HAVE_STRING_H 1
#define HAVE_STRINGS_H 1
#define HAVE_LIMITS_H 1
#define HAVE_ERRNO_H 1
#define HAVE_FCNTL_H 1
#define HAVE_UNISTD_H 1
#define HAVE_SYS_TYPES_H 1
#define HAVE_SYS_STAT_H 1
#define HAVE_MEMORY_H 1
#define HAVE_INT8_T 1
#define HAVE_INT16_T 1
#define HAVE_INT32_T 1
#define HAVE_INT64_T 1
#define HAVE_UINT8_T 1
#define HAVE_UINT16_T 1
#define HAVE_UINT32_T 1
#define HAVE_UINT64_T 1
#define SIZEOF_SHORT 2
#define SIZEOF_INT 4
#define SIZEOF_LONG 4
#define SIZEOF_LONG_LONG 8
#define SIZEOF_FLOAT 4
#define SIZEOF_DOUBLE 8
#define SIZEOF_UNSIGNED_SHORT 2
#define SIZEOF_UNSIGNED_INT 4
#define SIZEOF_UNSIGNED_LONG 4
#define SIZEOF_UNSIGNED_LONG_LONG 8
#define TAKEHIRO_IEEE754_HACK 1
#define LAME_LIBRARY_BUILD 1
#define PROTOTYPES 1
#define PACKAGE "lame"
#define VERSION "3.100"

/* configure writes these typedefs into config.h (configMS.h does the same on Windows) */
typedef float ieee754_float32_t;
typedef double ieee754_float64_t;
