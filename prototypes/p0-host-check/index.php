<?php
// P0 Host check: can this hosting account run Stationwagon?
declare(strict_types=1);
require_once __DIR__ . '/../shared/util.php';
$config = sw_config();

function bytes_from_ini(string $v): int
{
    $v = trim($v);
    $n = (int) $v;
    switch (strtolower(substr($v, -1))) {
        case 'g': return $n * 1024 ** 3;
        case 'm': return $n * 1024 ** 2;
        case 'k': return $n * 1024;
    }
    return $n;
}
function mb(int $b): string { return round($b / 1048576, 1) . ' MB'; }

$disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
$canExec = function_exists('exec') && !in_array('exec', $disabled, true);
$ffmpeg = '';
if ($canExec) {
    @exec(stripos(PHP_OS, 'WIN') === 0 ? 'where ffmpeg 2>NUL' : 'command -v ffmpeg 2>/dev/null', $lines);
    $ffmpeg = trim($lines[0] ?? '');
}
$dataWritable = is_writable($config['data_dir']);
// files for the browser-side checks
@file_put_contents($config['data_dir'] . '/range-test.bin', str_repeat(random_bytes(64), 1024));
@file_put_contents($config['data_dir'] . '/probe.json', '{"secret":true}');
$free = @disk_free_space($config['data_dir']);
$mp3s = count(array_filter(glob($config['media_dir'] . '/*') ?: [], fn($p) => preg_match('/\.mp3$/i', $p)));

$upload = bytes_from_ini((string) ini_get('upload_max_filesize'));
$post = bytes_from_ini((string) ini_get('post_max_size'));
$exec = (int) ini_get('max_execution_time');

// [label, value, state ok|warn|bad, required?, why]
$rows = [
    ['PHP version', PHP_VERSION, version_compare(PHP_VERSION, '8.0', '>=') ? 'ok' : 'bad', true, 'Needs PHP 8.0 or newer.'],
    ['Data folder writable', $dataWritable ? 'Yes' : 'No', $dataWritable ? 'ok' : 'bad', true, 'The timeline and live pieces are saved in prototypes/data.'],
    ['POST size limit', mb($post), $post >= 2 * 1048576 ? 'ok' : 'bad', true, 'A 6-second live piece is about 150 KB; 2 MB leaves room.'],
    ['Max execution time', $exec === 0 ? 'No limit' : $exec . ' s', ($exec === 0 || $exec >= 20) ? 'ok' : 'warn', false, 'Requests are short. 20 s covers scanning a big MP3 the first time.'],
    ['Memory limit', (string) ini_get('memory_limit'), bytes_from_ini((string) ini_get('memory_limit')) >= 64 * 1048576 || ini_get('memory_limit') === '-1' ? 'ok' : 'warn', false, 'Scanning a 15 MB MP3 reads it into memory once.'],
    ['Free disk space', $free ? round($free / 1073741824, 1) . ' GB' : 'Unknown', $free === false || $free > 2 * 1073741824 ? 'ok' : 'warn', false, 'Music library plus live recordings.'],
    ['MP3s in prototypes/media', (string) $mp3s, $mp3s >= 3 ? 'ok' : 'warn', false, 'Add at least 3 songs to test P1 and P2.'],
    ['exec() allowed', $canExec ? 'Yes' : 'No', $canExec ? 'ok' : 'warn', false, 'Not needed. Only used to look for ffmpeg.'],
    ['ffmpeg on server', $ffmpeg ?: 'Not found', $ffmpeg ? 'ok' : 'warn', false, 'Not needed: audio prep happens in the browser.'],
    ['HTTPS', (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'Yes' : 'No', 'warn', false, 'Browsers only allow the microphone on HTTPS (or localhost).'],
    ['Cloudflare in front', isset($_SERVER['HTTP_CF_RAY']) ? 'Yes (' . $_SERVER['HTTP_CF_RAY'] . ')' : 'No', isset($_SERVER['HTTP_CF_RAY']) ? 'ok' : 'warn', false, 'Optional. Lets Cloudflare serve the audio pieces to many listeners.'],
    ['Web server', (string) ($_SERVER['SERVER_SOFTWARE'] ?? PHP_SAPI), 'ok', false, 'Apache with .htaccess is assumed.'],
];
// HTTPS row: mark ok when it is on
if ($rows[9][1] === 'Yes') { $rows[9][2] = 'ok'; }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>P0 Host check · Stationwagon</title>
<link rel="stylesheet" href="../shared/proto.css">
</head>
<body>
<div class="wrap">
  <header>
    <p class="faint">Stationwagon prototype P0</p>
    <h1>Can this host run Stationwagon?</h1>
    <p class="muted">Server checks run in PHP; the last four run from your browser against this server.</p>
  </header>
  <section class="card">
    <h2>Result</h2>
    <p id="verdict" class="big">Checking…</p>
  </section>
  <section class="card tablewrap">
    <table>
      <thead><tr><th>Check</th><th>Found</th><th>Status</th><th>Why it matters</th></tr></thead>
      <tbody id="rows">
      <?php foreach ($rows as [$label, $value, $state, $required, $why]): ?>
        <tr data-required="<?= $required ? '1' : '0' ?>" data-state="<?= $state ?>">
          <td><?= htmlspecialchars($label) ?><?= $required ? ' <span class="pill">required</span>' : '' ?></td>
          <td class="mono"><?= htmlspecialchars($value) ?></td>
          <td><span class="pill <?= $state ?>"><?= $state === 'ok' ? 'Good' : ($state === 'bad' ? 'Problem' : 'Check') ?></span></td>
          <td class="muted"><?= htmlspecialchars($why) ?></td>
        </tr>
      <?php endforeach; ?>
        <tr data-required="1" data-state="pending" id="r-range"><td>Range requests on files <span class="pill">required</span></td><td class="mono">…</td><td><span class="pill">Testing</span></td><td class="muted">HLS pieces are byte ranges inside the MP3 files.</td></tr>
        <tr data-required="1" data-state="pending" id="r-private"><td>State files hidden <span class="pill">required</span></td><td class="mono">…</td><td><span class="pill">Testing</span></td><td class="muted">.htaccess must block data/*.json from the web.</td></tr>
        <tr data-required="0" data-state="pending" id="r-rewrite"><td>Rewrite time variables</td><td class="mono">…</td><td><span class="pill">Testing</span></td><td class="muted">Lets Apache pick a ready-made playlist by the clock, with no PHP (scale-up trick).</td></tr>
        <tr data-required="0" data-state="pending" id="r-clock"><td>Server clock vs this device</td><td class="mono">…</td><td><span class="pill">Testing</span></td><td class="muted">Players correct for the difference. Over 2 s means the server clock is off.</td></tr>
      </tbody>
    </table>
  </section>
  <p class="faint">Server time when this page was made: <span class="mono"><?= gmdate('Y-m-d H:i:s') ?> UTC</span></p>
</div>
<script>
const serverAtLoad = <?= json_encode(sw_now()) ?>;
function setRow(id, value, state){
  const tr=document.getElementById(id); tr.dataset.state=state;
  tr.children[1].textContent=value;
  tr.children[2].innerHTML=`<span class="pill ${state}">${state==="ok"?"Good":state==="bad"?"Problem":"Check"}</span>`;
}
async function run(){
  try{
    const r=await fetch("../data/range-test.bin",{headers:{Range:"bytes=100-199"},cache:"no-store"});
    const b=await r.arrayBuffer();
    setRow("r-range", `HTTP ${r.status}, ${b.byteLength} bytes`, r.status===206&&b.byteLength===100?"ok":"bad");
  }catch(e){setRow("r-range","Request failed","bad")}
  try{
    const r=await fetch("../data/probe.json",{cache:"no-store"});
    setRow("r-private", `HTTP ${r.status}`, r.status===403||r.status===404?"ok":"bad");
  }catch(e){setRow("r-private","Request failed","bad")}
  try{
    const r=await fetch("time-probe",{cache:"no-store"});
    if(r.ok){const j=await r.json();const same=j.apache_sec!==""&&Math.abs(+j.apache_sec - +j.php_sec)<=1||Math.abs(+j.apache_sec - +j.php_sec)===59;
      setRow("r-rewrite", `Apache second ${j.apache_sec}, PHP second ${j.php_sec}`, same?"ok":"warn");}
    else setRow("r-rewrite", `HTTP ${r.status} (rewrite not active)`, "warn");
  }catch(e){setRow("r-rewrite","Request failed","warn")}
  try{
    const t0=Date.now();const r=await fetch("probe.php",{cache:"no-store"});const j=await r.json();const t1=Date.now();
    const offset=j.php_now*1000-(t0+t1)/2;
    setRow("r-clock", `${(offset/1000).toFixed(2)} s (round trip ${t1-t0} ms)`, Math.abs(offset)<2000?"ok":"warn");
  }catch(e){setRow("r-clock","Request failed","warn")}
  const rows=[...document.querySelectorAll("#rows tr")];
  const badReq=rows.filter(r=>r.dataset.required==="1"&&r.dataset.state!=="ok");
  const warns=rows.filter(r=>r.dataset.state==="warn").length;
  const v=document.getElementById("verdict");
  v.textContent=badReq.length?`Not yet: ${badReq.length} required check${badReq.length>1?"s":""} failed`:`Yes. All required checks pass${warns?` (${warns} to look at)`:""}.`;
  v.style.color=badReq.length?"var(--live)":"var(--accent)";
}
run();
</script>
</body>
</html>
