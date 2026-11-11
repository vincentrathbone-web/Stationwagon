# Looking up album and licensing details online: research (2026-11-11)

The question (the user, 2026-11-11): can the station tap into online databases for album information and licensing
details (publishers and so on)? Built from it in round 65: **Look up details** with MusicBrainz (see `HANDOVER.md`).
This file keeps what was found, so the other sources need not be researched again. Checked on 2026-11-11 from
the public pages and a few real requests; terms change, so read the source's own page before building on one.

## What a lookup is, and is not

- It says **who is registered** (the label, the publisher, the writers, the codes). It does **not** say whether the
  station may play a song: that is the licence (SAMRO for the composition, SAMPRA for the recording and needle time in
  South Africa, or the licence page and certificate of royalty-free music), which stays in the song's own Copyright
  and licence window.
- Matching by title and artist can be wrong. Suggestions are shown with the source and ticked by a person; nothing is
  filled in silently.
- The station's own library (Life.Church Switch tracks, Pixabay library music) returned **no hits** on MusicBrainz:
  independent and production music is mostly not in these databases. For mainstream worship songs the answer is rich
  (for "Reckless Love": ISRC, label, barcode, release date, cover, ISWC, 3 writers and 5 publishers).

## Sources

| Source | Gives | Access | Verdict |
|---|---|---|---|
| **MusicBrainz** (musicbrainz.org) | recordings, releases (album, date, label, barcode, track), ISRCs, works with ISWC, writers, lyricists, publishers (where entered) | free web service, about 1 request a second per IP, descriptive User-Agent with contact required; core data CC0; the replication feed is CC BY-NC-SA; commercial users are asked to pay MetaBrainz | **Built** (round 65), non-commercial use confirmed by the user |
| **Cover Art Archive** (coverartarchive.org) | front cover by release id | free; images stay the copyright owners' | **Built**: offered only when the song has no art, and the person confirms |
| **AcoustID** (acoustid.org) | identifies a song from its sound (Chromaprint fingerprint) and returns MusicBrainz recording ids | free **for non-commercial use only**; registered application key; 3 requests a second; commercial use on acoustid.biz | Possible later for songs with poor tags (needs a Chromaprint fingerprint made in the browser) |
| **Deezer** (api.deezer.com) | title, album, release date, cover, ISRC; `track/isrc:CODE` worked without a key | no key for basic reads; terms and limits only confirmed from third-party pages | Possible second source for a quick ISRC match; read the terms first |
| **Discogs** | ℗ and © lines printed on releases, labels, catalogue numbers | API token; terms allow commercial use in general but not for "Restricted Data"; CC0 for the rest; rate limit by moving average | Possible later for the ℗/© lines |
| **iTunes Search API** | album, artwork | free, about 20 calls a minute; artwork only to promote store content | Not suitable (artwork terms; no ISRC lookup confirmed) |
| **Spotify Web API** | album copyright lines, ISRC | February to July 2026 changes: development mode needs Premium and is capped at 5 users; extended quota needs a registered business with a large audience | Not viable |
| **The MLC** (themlc.com) | US mechanical works data: writers, publishers, ISWC | free web search for people; the Public Search API and bulk feed need a request to the MLC | Not automatable without their agreement |
| **ASCAP / BMI Songview** | US works, splits, publishers | free web search; no developer API or scraping terms found | Manual only |
| **SoundExchange ISRC Search** (isrc.soundexchange.com, isrcsearch.ifpi.org) | recording owner and ISRC metadata for about 20 million recordings | free web page; no public API found; bulk is by DDEX for rights owners | Manual only |
| **CISAC ISWC Net** (iswc.org) | the international work code and works metadata | official third-party API is restricted (click-through licence, contact iswc.thirdparties@cisac.org); the web search has a CAPTCHA; unofficial credits.fm has a free API (terms unverified) | Not automatable officially |
| **HFA Songfile, Music Reports Songdex** | US publishers and mechanical licensing | web tools; no public API found; Songdex is commercial | Manual only |
| **CCLI SongSelect** | CCLI number, publisher, copyright of church songs | the partner API is closed to new partners | Manual only |
| **SAMRO, SAMPRA, CAPASSO** (South Africa) | works, writers, publishers (SAMRO members' portal); needle time for sound recordings (SAMPRA) | no public lookup API found; radio stations report titles, writers, ISRC and label to them | Manual only; the Copyright and licence window already holds the fields a return needs |
| **US Copyright Office catalogue** | registrations since 1978 | free web search | Manual only; legal records, US works |

## How the lookup is built (round 65)

- Only the **server** contacts MusicBrainz, only when a manager clicks **Look up on MusicBrainz** in a song's details
  window, and it sends only the title, artist, ISRC and length typed in the window. Listeners' browsers contact no one.
- The User-Agent is `Stationwagon/<version> ( <the station's privacy email, else its address> )`.
- Requests are spaced at least 1.1 s apart (a lock file in `storage/cache/mb`), and every answer is kept there for
  30 days, so a repeat lookup costs nothing.
- A lookup is about four requests: the search (or the ISRC), the recording, the work (the recording's own, else the
  work of the same name as a labelled guess) and the release.
- The staff-facing privacy line is in the window itself. The listeners' privacy page is unchanged: no listener data is involved.
