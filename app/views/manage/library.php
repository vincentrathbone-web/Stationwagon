<?php use function Stationwagon\{h, duration, megabytes}; use Stationwagon\App;
/** @var array $tracks @var string $q @var int $total @var int $bytes @var int $confirm @var int $edit @var int $maxUpload @var array $prepQuality @var array $prep @var array $preset @var array $relevel @var string $csrf */
[$prepMaster, $prepLevel, $prepTarget] = $prep;
$lufs = fn(float $v) => str_replace('-', '−', number_format($v, 1));
// the usual loudness targets, marked on the slider (round 41)
$standards = [-16 => 'Apple Music, podcasts', -14 => 'Spotify, YouTube', -11 => 'Loud, like FM radio', -9 => 'Very loud'];
[$presetName, $presetSettings] = $preset;
?>
<div class="vhead">
  <div>
    <h1>Music library</h1>
    <p><span id="libCount"><?= $total ?> songs · <?= h(megabytes($bytes)) ?></span>. New songs join the AutoDJ straight away; playlists and the schedule decide when they play.</p>
  </div>
</div>

<?php
$dropRoute = 'manage/library';
$dropNoun = 'song';
$dropIntro = 'Drop songs here, or choose them: MP3, WAV, FLAC, M4A (AAC or ALAC), AAC, Ogg, Opus or WMA. Your browser first prepares each song as chosen below and trims silence at both ends; other formats become MP3s, with a 320 kbps MP3 kept as the original.';
require __DIR__ . '/_drop.php';
?>

<?php
// The table (round 62): full width, sortable, columns to choose, star ratings. $cols: key => label; the ones in
// $hiddenCols start hidden (the person's choice is kept in the browser, assets/library.js).
$cols = ['artist' => 'Artist', 'album' => 'Album', 'length' => 'Length', 'badges' => 'Badges', 'playlists' => 'Playlists', 'added' => 'Added',
    'loudness' => 'Loudness', 'bitrate' => 'Bitrate', 'size' => 'Size', 'preset' => 'Mastering preset'];
$hiddenCols = ['bitrate', 'size', 'preset'];
$ncols = 14;
$tz = new DateTimeZone((string) ($station['timezone'] ?? 'UTC') ?: 'UTC');
// a song's stars are a radio group (arrow keys move, 1 to 5 and 0 rate); $action: plain buttons that rate the selected songs
$stars = function (?int $r, string $label, bool $action = false): string {
    $o = '<span class="stars" role="' . ($action ? 'group' : 'radiogroup') . '" aria-label="' . ($action ? '' : 'Rating for ') . h($label) . '">';
    for ($i = 1; $i <= 5; $i++) {
        $word = $i . ($i === 1 ? ' star' : ' stars');
        $o .= '<button type="button" class="star' . ($r !== null && $i <= $r ? ' on' : '') . '"'
            . ($action ? ' tabindex="0"' : ' role="radio" aria-checked="' . ($r === $i ? 'true' : 'false') . '" tabindex="' . ($r === $i || ($r === null && $i === 1) ? '0' : '-1') . '"')
            . ' data-stars="' . $i . '" title="' . $word . '" aria-label="' . $word . '">★</button>';
    }
    return $o . '</span>';
};
?>
<form class="toolbar" method="get" action="<?= h(App::url('manage/library')) ?>" role="search">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search title or artist" aria-label="Search the library">
  <button class="btn" type="submit">Search</button>
  <?php if ($q !== ''): ?><a class="btn" href="<?= h(App::url('manage/library')) ?>">Show all</a><?php endif ?>
  <select id="ratingFilter" aria-label="Show songs by rating" hidden>
    <option value="">All ratings</option>
    <option value="none">Not rated yet</option>
    <?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= str_repeat('★', $i) ?> <?= $i ?> star<?= $i === 1 ? '' : 's' ?></option><?php endfor ?>
  </select>
  <details class="colmenu" id="colMenu" hidden>
    <summary class="btn">Columns</summary>
    <div class="colpanel" role="group" aria-label="Columns to show">
      <?php foreach ($cols as $key => $label): ?><label class="swrow"><input class="sw" type="checkbox" data-col="<?= h($key) ?>"<?= in_array($key, $hiddenCols, true) ? '' : ' checked' ?>> <?= h($label) ?></label><?php endforeach ?>
      <label class="swrow dense"><input class="sw" type="checkbox" id="denseToggle"> Compact rows</label>
    </div>
  </details>
</form>
<div class="bulkbar" id="bulkBar" hidden>
  <b id="bulkCount">0 selected</b>
  <span class="muted2">Rate them:</span> <span id="bulkStars"><?= $stars(null, 'Rate the selected songs', true) ?></span>
  <button class="btn sm" type="button" id="bulkClear">Clear rating</button>
  <button class="btn sm" type="button" id="bulkNone">Deselect</button>
</div>
<p class="muted2 libnote" id="libNote" role="status" aria-live="polite"></p>

<div class="tablewrap lib" id="libWrap" data-action="<?= h(App::url('manage/library')) ?>" data-csrf="<?= h($csrf) ?>" data-base="<?= h(App::url('')) ?>">
  <table class="lib" id="libTable" data-hidden="<?= h(implode(' ', $hiddenCols)) ?>">
    <thead><tr>
      <th class="c-sel" data-col="sel"><input type="checkbox" id="selAll" aria-label="Select every song shown"></th>
      <th data-col="title" aria-sort="ascending"><button type="button" class="sortb" data-key="title">Title</button></th>
      <?php foreach ($cols as $key => $label): $num = in_array($key, ['length', 'playlists', 'loudness', 'bitrate', 'size'], true); ?>
        <th data-col="<?= h($key) ?>"<?= $num ? ' class="num"' : '' ?>><?php if ($key === 'badges'): ?><?= h($label) ?><?php else: ?><button type="button" class="sortb" data-key="<?= h($key) ?>"<?= $num ? ' data-type="num"' : '' ?>><?= h($label) ?></button><?php endif ?></th>
      <?php endforeach ?>
      <th data-col="rating"><button type="button" class="sortb" data-key="rating" data-type="num">Rating</button></th>
      <th data-col="acts"><span class="vh">Actions</span></th>
    </tr></thead>
    <tbody id="libBody">
    <?php if (!$tracks): ?>
      <tr class="empty"><td colspan="<?= $ncols ?>" class="muted2"><?= $q !== '' ? 'No songs match “' . h($q) . '”.' : 'No songs yet. Add some above.' ?></td></tr>
    <?php endif ?>
    <?php foreach ($tracks as $t): $id = (int) $t['id']; $row = Stationwagon\Library::row($t); $line = Stationwagon\Rights::line(Stationwagon\Rights::decode($t['rights']));
      $added = ''; try { $added = (new DateTimeImmutable((string) $t['created_at'], new DateTimeZone('UTC')))->setTimezone($tz)->format('j M Y'); } catch (Throwable) {} ?>
      <?php if ($edit === $id): ?>
        <tr class="editing"><td colspan="<?= $ncols ?>">
          <form method="post" action="<?= h(App::url('manage/library')) ?>" class="editrow">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="q" value="<?= h($q) ?>">
            <label class="field"><span class="lbl">Title</span><input name="title" value="<?= h($t['title']) ?>" required></label>
            <label class="field"><span class="lbl">Artist</span><input name="artist" value="<?= h($t['artist'] ?? '') ?>"></label>
            <label class="field"><span class="lbl">Album</span><input name="album" value="<?= h($t['album'] ?? '') ?>"></label>
            <button class="btn primary sm" type="submit">Save</button>
            <a class="btn sm" href="<?= h(App::url('manage/library') . ($q !== '' ? '?q=' . rawurlencode($q) : '')) ?>">Cancel</a>
          </form>
        </td></tr>
      <?php else: ?>
        <tr id="t<?= $id ?>" data-track="<?= h(json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>">
          <td class="c-sel" data-col="sel"><input type="checkbox" class="sel" aria-label="Select <?= h($t['title']) ?>"></td>
          <td class="c-title" data-col="title"><div class="tcell"><span class="arts"><?php if ($row['art']): ?><img src="<?= h(App::url($row['art'])) ?>" alt="Album art" width="40" height="40" loading="lazy"><?php endif ?></span><b class="tt"><?= h($t['title']) ?></b></div></td>
          <td class="c-artist" data-col="artist"><?= h($t['artist'] ?? '') ?></td>
          <td class="c-album alb" data-col="album"><?= h($t['album'] ?? '') ?></td>
          <td class="num" data-col="length" data-sort="<?= h((string) (float) $t['duration_s']) ?>"><?= h(duration((float) $t['duration_s'])) ?></td>
          <td class="c-badges" data-col="badges"><span class="cb"><?php if ($line !== ''): ?><button type="button" class="pill rights" title="<?= h($line) ?>">© Copyright</button><?php endif ?></span><?php if ((int) ($t['mastered'] ?? 0)): ?> <span class="pill auto" title="Mastered with “<?= h($t['preset'] ?? 'Audition chain') ?>”">Mastered</span><?php endif ?><?php if ($t['loudness_lufs'] !== null): ?> <span class="pill auto" title="<?= h('Levelled to ' . $lufs((float) $t['loudness_lufs']) . ' LUFS'
            . ($t['level_target'] !== null ? ' (target ' . $lufs((float) $t['level_target']) . ')' : '')
            . ($t['level_gain_db'] !== null ? ': ' . ((float) $t['level_gain_db'] >= 0 ? '+' : '') . $lufs((float) $t['level_gain_db']) . ' dB from ' . $lufs((float) $t['source_lufs']) . ' LUFS' : '')) ?>">Levelled</span><?php endif ?></td>
          <td class="num" data-col="playlists"><?= (int) $t['playlists'] ?></td>
          <td class="num" data-col="added" data-sort="<?= h((string) $t['created_at']) ?>"><?= h($added) ?></td>
          <td class="num" data-col="loudness" data-sort="<?= $t['loudness_lufs'] !== null ? h((string) (float) $t['loudness_lufs']) : '' ?>"><?= $t['loudness_lufs'] !== null ? h($lufs((float) $t['loudness_lufs'])) : '—' ?></td>
          <td class="num" data-col="bitrate" data-sort="<?= (int) $t['bitrate_kbps'] ?>"><?= (int) $t['bitrate_kbps'] ?> kbps</td>
          <td class="num" data-col="size" data-sort="<?= (int) $t['bytes'] ?>"><?= h(megabytes((int) $t['bytes'])) ?></td>
          <td class="c-preset" data-col="preset"><?= (int) ($t['mastered'] ?? 0) ? h((string) ($t['preset'] ?? 'Audition chain')) : '—' ?></td>
          <td class="c-rating" data-col="rating" data-sort="<?= $row['rating'] ?? 3 ?>"><?= $stars($row['rating'], $t['title']) ?></td>
          <td class="acts" data-col="acts">
            <?php if ($confirm === $id): ?>
              <span class="warn2">Remove from the library?</span>
              <?= $post('manage/library', 'Yes, remove', ['action' => 'delete', 'id' => $id, 'q' => $q], 'btn sm danger') ?>
              <a class="btn sm" href="<?= h(App::url('manage/library') . ($q !== '' ? '?q=' . rawurlencode($q) : '')) ?>">Keep</a>
            <?php else: ?>
              <a class="btn sm" data-act="edit" href="<?= h(App::url('manage/library') . '?' . http_build_query(array_filter(['q' => $q, 'edit' => $id]))) ?>">Edit</a>
              <a class="btn sm" href="<?= h(App::url('manage/songprep') . '?song=' . $id) ?>" title="Try mastering presets on this song">Song prep</a>
              <a class="btn sm" data-act="remove" href="<?= h(App::url('manage/library') . '?' . http_build_query(array_filter(['q' => $q, 'confirm' => $id]))) ?>">Remove</a>
            <?php endif ?>
          </td>
        </tr>
      <?php endif ?>
    <?php endforeach ?>
    </tbody>
  </table>
</div>
<script src="<?= h(App::asset('assets/prep-core.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/prep.js')) ?>" data-kbps="<?= $prepQuality['kbps'] ?>" data-rate="<?= $prepQuality['rate'] ?>" data-precision="<?= $prepQuality['precision'] ?>" data-dither="<?= $prepQuality['dither'] ? 1 : 0 ?>" data-quality="<?= $prepQuality['q'] ?>" data-worker="<?= h(App::asset('assets/prep-worker.js', ['assets/prep-core.js', 'assets/master-core.js', 'assets/vendor/lame-3.100.js', 'assets/vendor/ffmpeg-decode-7.1.js'])) ?>" defer></script>
<script src="<?= h(App::asset('assets/upload.js')) ?>" defer></script>
<template id="libEditTpl">
  <tr class="editing"><td colspan="<?= $ncols ?>">
    <form class="editrow libedit" novalidate>
      <div class="artbox">
        <div class="artpic" data-role="pic"></div>
        <div class="artbtns">
          <button class="btn sm" type="button" data-role="pick">Choose album art</button>
          <button class="btn sm" type="button" data-role="unpick">Remove art</button>
          <input type="file" accept="image/jpeg,image/png" data-role="file" hidden>
        </div>
      </div>
      <label class="field"><span class="lbl">Title</span><input name="title" required></label>
      <label class="field"><span class="lbl">Artist</span><input name="artist"></label>
      <label class="field"><span class="lbl">Album</span><input name="album"></label>
      <div class="editbtns">
        <button class="btn primary sm" type="submit">Save</button>
        <button class="btn sm" type="button" data-role="rights">© Copyright and licence…</button>
        <button class="btn sm" type="button" data-role="close">Close</button>
      </div>
      <p class="muted2 editmsg" role="status" aria-live="polite" data-role="msg"></p>
    </form>
  </td></tr>
</template>
<?php /* The song details window (round 56, goal 10): before each upload (upload.js, with the top part: art, title,
  artist, album, and Add / Skip / Cancel the rest) and for a song in the library (library.js: copyright and licence).
  The fields are what radio returns (SAMRO, PRS) and distributors ask for, in three groups; see Rights. */ ?>
<dialog class="rightsdlg" id="rightsDlg" aria-labelledby="rightsTitle">
  <form method="dialog" id="rightsForm" novalidate>
    <h2 id="rightsTitle">Copyright and licence</h2>
    <p class="muted2" id="rightsSong"></p>
    <?php /* "Look up details" (round 65): MusicBrainz suggestions for this song, asked for by a manager; assets/lookup.js, MusicBrainz.php */ ?>
    <section class="lkbox" id="lkBox" aria-labelledby="lkTitle">
      <div class="lkhead">
        <h3 id="lkTitle">Look up details</h3>
        <button class="btn sm" type="button" id="lkRun">Look up on MusicBrainz</button>
      </div>
      <p class="muted2 lknote">Suggests the album, year, label, ISRC, writers and publishers from <a href="https://musicbrainz.org/" target="_blank" rel="noopener noreferrer">MusicBrainz</a>, a free, open music database. The station’s server sends it only this song’s title, artist, ISRC and length. Nothing changes until you tick what to keep. It says who is registered, not what you may play: keep the licence itself below.</p>
      <p class="muted2 editmsg" id="lkMsg" role="status" aria-live="polite"></p>
      <ol class="lkcands" id="lkCands" hidden></ol>
      <div class="lkresult" id="lkResult" hidden>
        <label class="field" id="lkRelWrap" hidden><span class="lbl">Release (the album, year, label and track number come from it)</span><select id="lkRel"></select></label>
        <table class="lktable"><thead><tr><th><span class="vh">Use</span></th><th>Field</th><th>Now</th><th>Found</th></tr></thead><tbody id="lkRows"></tbody></table>
        <p class="sdwarn" id="lkWarn" role="status" hidden></p>
        <div class="lkcover" id="lkCover" hidden>
          <img id="lkCoverImg" alt="Cover found on MusicBrainz" width="96" height="96" hidden>
          <button class="btn sm" type="button" id="lkCoverShow">Show the cover</button>
          <button class="btn sm primary" type="button" id="lkCoverUse" hidden>Use this cover</button>
          <span class="muted2" id="lkCoverMsg" role="status" aria-live="polite"></span>
        </div>
        <div class="dlgbtns"><button class="btn primary sm" type="button" id="lkApply">Fill the ticked fields</button></div>
      </div>
    </section>
    <label class="field" id="lkAlbumWrap" hidden><span class="lbl">Album</span><input id="lkAlbumIn" maxlength="255" autocomplete="off"></label>
    <div class="sdtop" id="sdTop" hidden>
      <p class="sdprep muted2" id="sdPrep" role="status" aria-live="polite"></p>
      <div class="artbox">
        <div class="artpic" id="sdPic">No art</div>
        <div class="artbtns">
          <button class="btn sm" type="button" id="sdPick">Choose album art</button>
          <button class="btn sm" type="button" id="sdUnpick">Remove art</button>
          <input type="file" accept="image/jpeg,image/png" id="sdFile" hidden>
        </div>
      </div>
      <div class="rgrid">
        <label class="field wide"><span class="lbl">Title</span><input name="title" maxlength="255" autocomplete="off"></label>
        <label class="field"><span class="lbl">Artist</span><input name="artist" maxlength="255" autocomplete="off"></label>
        <label class="field"><span class="lbl">Album</span><input name="album" maxlength="255" autocomplete="off"></label>
      </div>
    </div>
    <fieldset class="rgroup">
      <legend>The recording</legend>
      <div class="rgrid">
        <label class="field"><span class="lbl">Record label</span><input name="label" maxlength="200" autocomplete="off"></label>
        <label class="field"><span class="lbl">Track number</span><input name="track" maxlength="10" autocomplete="off" placeholder="3 or 3/12"></label>
        <label class="field"><span class="lbl">℗ Sound recording owner</span><input name="pline" maxlength="200" autocomplete="off" placeholder="Usually the artist or label"></label>
        <label class="field"><span class="lbl">Year</span><input name="year" maxlength="20" autocomplete="off" inputmode="numeric"></label>
        <label class="field wide"><span class="lbl">ISRC <span class="muted2">(the recording’s code)</span></span><input name="isrc" maxlength="20" autocomplete="off" placeholder="ZA-ABC-24-00001"></label>
      </div>
    </fieldset>
    <fieldset class="rgroup">
      <legend>The song (composition)</legend>
      <div class="rgrid">
        <label class="field wide"><span class="lbl">Songwriter(s) / composer(s)</span><input name="writers" maxlength="500" autocomplete="off"></label>
        <label class="field wide"><span class="lbl">Lyricist(s)</span><input name="lyricists" maxlength="500" autocomplete="off" placeholder="When not the songwriters"></label>
        <label class="field"><span class="lbl">Publisher(s)</span><input name="publisher" maxlength="200" autocomplete="off"></label>
        <label class="field"><span class="lbl">© Copyright owner</span><input name="owner" maxlength="200" autocomplete="off"></label>
        <label class="field wide"><span class="lbl">ISWC <span class="muted2">(the composition’s code)</span></span><input name="iswc" maxlength="20" autocomplete="off" placeholder="T-034.524.680-1"></label>
      </div>
    </fieldset>
    <fieldset class="rgroup">
      <legend>Licence</legend>
      <div class="rgrid">
        <label class="field wide"><span class="lbl">Licence type</span><input name="licence" maxlength="200" autocomplete="off" list="licenceTypes" placeholder="Pick one or type your own"></label>
        <label class="field"><span class="lbl">Licence number or reference</span><input name="licence_ref" maxlength="100" autocomplete="off" placeholder="CCLI song number, contract…"></label>
        <label class="field"><span class="lbl">Valid until</span><input name="licence_until" type="date"></label>
        <label class="field wide"><span class="lbl">Licence link</span><input name="licence_url" type="url" maxlength="500" autocomplete="off" placeholder="https://…"></label>
        <label class="field wide"><span class="lbl">Licence terms</span><textarea name="terms" rows="4" maxlength="20000" placeholder="The terms, pasted as text"></textarea></label>
      </div>
      <datalist id="licenceTypes"><option value="All rights reserved (permission from the owner)"><option value="SAMRO / SAMPRA blanket licence"><option value="CCLI Streaming Licence"><option value="Creative Commons BY 4.0"><option value="Creative Commons BY-SA 4.0"><option value="Creative Commons BY-NC 4.0"><option value="Royalty-free (production music)"><option value="Public domain"><option value="The station's own work"></datalist>
    </fieldset>
    <div class="field">
      <span class="lbl" id="notesLbl">More information</span>
      <div class="rtbar" role="toolbar" aria-label="Link tools">
        <button class="btn sm" type="button" data-cmd="link">Add link</button>
        <button class="btn sm" type="button" data-cmd="unlink">Remove link</button>
      </div>
      <div class="rtlink" id="rtLink" hidden>
        <input type="url" id="rtUrl" placeholder="https://…" aria-label="Link address">
        <button class="btn sm primary" type="button" id="rtApply">Apply</button>
        <button class="btn sm" type="button" id="rtCancel">Cancel</button>
      </div>
      <div class="rtedit" id="rtNotes" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="notesLbl" spellcheck="true"></div>
      <span class="hint">Text and links only. A YouTube link, the artist’s page, anything listeners may want. Pasting removes all formatting but keeps line breaks.</span>
    </div>
    <p class="sdwarn" id="sdWarn" role="status" aria-live="polite" hidden></p>
    <p class="muted2 editmsg" role="status" aria-live="polite" id="rightsMsg"></p>
    <div class="dlgbtns" id="rightsBtns">
      <button class="btn primary" type="submit" id="rightsSave">Save</button>
      <button class="btn" type="button" id="rightsClear">Clear all</button>
      <button class="btn" type="button" id="rightsCancel">Cancel</button>
    </div>
    <div class="dlgbtns" id="sdBtns" hidden>
      <button class="btn primary" type="submit" id="sdAdd">Add song</button>
      <button class="btn" type="button" id="sdSkip">Skip this song</button>
      <button class="btn" type="button" id="sdStop">Cancel the rest</button>
    </div>
  </form>
</dialog>
<script src="<?= h(App::asset('assets/relevel.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/lookup.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/library.js')) ?>" defer></script>
