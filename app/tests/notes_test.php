<?php
// Command-line test of presenter notes: what the studio may post (each kind), posting only while
// a show is on air, removing, the listeners' view (live, then "This morning", "Yesterday", gone
// after a week, never under a newer show) and the change marker the player watches.
//   php app/tests/notes_test.php      (same SW_TEST_DB_* settings as install_test.php)
declare(strict_types=1);
require __DIR__ . '/lib.php';
use Stationwagon\{Db, Library, Notes, Planner};

$t = test_database('notes');
$root = temp_root('notes');
try {
    $cfg = test_install($t, $root);
    $db = Db::connect($cfg['db']);
    $station = $db->run('SELECT * FROM ' . $db->table('stations'))->fetch();
    $dj = (int) $db->run('SELECT id FROM ' . $db->table('users'))->fetchColumn();
    $lib = new Library($db, $station, $root);
    mkdir("$root/in");
    foreach (['Amazing Grace' => 150, 'Be Thou My Vision' => 170] as $name => $secs) {
        make_mp3("$root/in/tmp", $secs, [], $secs);
        $lib->import("$root/in/tmp", null, null, "$name.mp3");
    }
    $p = new Planner($db, $station, $root);
    $notes = new Notes($db, $station);
    $now = 1800000000.0; // 2027-01-15 08:00 UTC, 10:00 in Johannesburg

    echo "What a note may hold\n";
    $v = Notes::clean('verse', ['ref' => '  Psalm   23:1 ', 'text' => "The LORD is my shepherd;\x07 I shall not want."]);
    check('a verse keeps its reference and pasted text, tidied', ($v['body']['ref'] ?? '') === 'Psalm 23:1' && $v['body']['text'] === 'The LORD is my shepherd; I shall not want.', json_encode($v));
    check('  in KJV unless told otherwise, opening its own reference', $v['body']['version'] === 'KJV' && $v['body']['passage'] === 'Psalm 23:1');
    $v2 = Notes::clean('verse', ['ref' => 'John 10:11', 'text' => 'I am the good shepherd.', 'version' => 'afr53', 'passage' => 'John 10:1–18']);
    check('  or in another version, opening a longer passage', $v2['body']['version'] === 'AFR53' && $v2['body']['passage'] === 'John 10:1–18');
    check('a verse needs its reference', isset(Notes::clean('verse', ['text' => 'x'])['error']));
    check('  and its text', isset(Notes::clean('verse', ['ref' => 'Psalm 23:1', 'text' => " \n "])['error']));
    check('  and a sensible version code', isset(Notes::clean('verse', ['ref' => 'Psalm 23:1', 'text' => 'x', 'version' => 'King James!'])['error']));
    $r = Notes::clean('refs', ['text' => 'Read further this week', 'refs' => "Ezekiel 34:11–16\n\n 1 Peter 5:1–4 "]);
    check('references come one per line', ($r['body']['refs'] ?? null) === ['Ezekiel 34:11–16', '1 Peter 5:1–4'], json_encode($r));
    check('  or as a list; at least one', isset(Notes::clean('refs', ['refs' => ['', ' ']])['error']) && !isset(Notes::clean('refs', ['refs' => ['Romans 8:25']])['error']));
    check('  and twelve at most', isset(Notes::clean('refs', ['refs' => array_fill(0, 13, 'Psalm 1')])['error']));
    check('a video needs a title and a web address', !isset(Notes::clean('video', ['text' => 'Sunday’s sermon', 'url' => 'https://example.test/v/1'])['error'])
        && isset(Notes::clean('video', ['text' => 'Sunday’s sermon', 'url' => ''])['error']) && isset(Notes::clean('video', ['url' => 'https://example.test/v/1'])['error']));
    check('  never a script address', isset(Notes::clean('video', ['text' => 'x', 'url' => 'javascript:alert(1)'])['error']));
    $long = Notes::clean('note', ['text' => str_repeat('a', 1200)]);
    check('a plain note is cut at 1000 characters', mb_strlen($long['body']['text']) === 1000);
    check('  and can’t be empty', isset(Notes::clean('note', ['text' => ''])['error']));
    check('  keeps its paragraphs', Notes::clean('note', ['text' => "One.\n\n\n\nTwo."])['body']['text'] === "One.\n\nTwo.");
    check('unknown kinds are refused', isset(Notes::clean('poll', ['text' => 'x'])['error']));

    echo "Posting during a show\n";
    check('nothing to show listeners at first', $notes->forListeners($now)['show'] === null);
    $rev0 = $notes->rev($now);
    check('notes can’t be posted with no show on air', isset($notes->post('live-none', $dj, 'note', ['text' => 'Hello'], $now)['error']));
    $p->timeline($now); // something on air to hand over from
    $went = $p->goLive($now, 'Morning message', $dj)['result'];
    check('a test show goes live', isset($went['live']), json_encode($went['error'] ?? ''));
    $live = $went['live'];
    $pid = $live['public_id'];
    $rev1 = $notes->rev($now);
    check('a show going live changes the marker', $rev1 !== $rev0, "$rev0 → $rev1");
    check('  but listeners see nothing until the first note', $notes->forListeners($now)['show'] === null);
    $a = $notes->post($pid, $dj, 'verse', ['ref' => 'Psalm 23:1', 'text' => 'The LORD is my shepherd; I shall not want.', 'passage' => 'Psalm 23'], $now + 360);
    check('a verse is posted', isset($a['note']['id']), json_encode($a));
    check('  with a link to the passage on Bible Gateway', $a['note']['read'] === 'https://www.biblegateway.com/passage/?search=Psalm%2023&version=KJV', $a['note']['read'] ?? '');
    check('  and the time in the station’s time zone', $a['note']['at'] === '10:06' && $a['note']['posted'] === 1800000360, $a['note']['at']);
    $rev2 = $notes->rev($now);
    check('  which changes the marker', $rev2 !== $rev1);
    $b = $notes->post($pid, $dj, 'refs', ['text' => 'Read further', 'refs' => "Ezekiel 34:11–16\n1 Peter 5:1–4"], $now + 400);
    check('references link each passage (dashes made plain)', ($b['note']['refs'][0]['url'] ?? '') === 'https://www.biblegateway.com/passage/?search=Ezekiel%2034%3A11-16&version=KJV', $b['note']['refs'][0]['url'] ?? '');
    $c = $notes->post($pid, $dj, 'note', ['text' => '<b>Bring</b> your questions & a friend'], $now + 500);
    check('text is kept as written (the player shows it as text)', $c['note']['text'] === '<b>Bring</b> your questions & a friend');
    $bad = $notes->post($pid, $dj, 'video', ['text' => 'x', 'url' => 'ftp://example.test'], $now + 510);
    check('a bad note says why and isn’t stored', isset($bad['error']) && count($notes->forShow($pid)) === 3);
    $view = $notes->forListeners($now + 600);
    check('listeners see the live show', $view['show']['live'] === true && $view['show']['label'] === 'Live', json_encode($view['show']));
    check('  who is presenting it', str_contains($view['show']['by'], 'Test Admin') && str_contains($view['show']['by'], 'Morning message') && str_contains($view['show']['by'], 'posting live'), $view['show']['by']);
    check('  newest note first', array_column($view['items'], 'id') === [$c['note']['id'], $b['note']['id'], $a['note']['id']]);
    check('the studio sees them oldest first', array_column($notes->forShow($pid), 'id') === [$a['note']['id'], $b['note']['id'], $c['note']['id']]);
    check('a note can be removed', $notes->remove($pid, $b['note']['id']) && count($notes->forShow($pid)) === 2);
    check('  which changes the marker', $notes->rev($now) !== $rev2);
    check('  but not through another show', !$notes->remove('live-other', $a['note']['id']));

    echo "After the show\n";
    $p->endLive($now + 900, $pid);
    check('notes can’t be added once it has ended', isset($notes->post($pid, $dj, 'note', ['text' => 'Late'], $now + 950)['error']));
    $after = $notes->forListeners($now + 1000);
    check('listeners keep its notes, “This morning”', $after['show']['label'] === 'This morning' && $after['show']['live'] === false && count($after['items']) === 2, $after['show']['label']);
    check('  with when it ran', str_contains($after['show']['by'], '10:00–'), $after['show']['by']);
    check('“Yesterday” the next day', $notes->forListeners($now + 86400)['show']['label'] === 'Yesterday');
    check('  and the marker moves with the day', $notes->rev($now + 86400) !== $notes->rev($now + 1000));
    check('a weekday after that', $notes->forListeners($now + 3 * 86400)['show']['label'] === 'Fri 15 Jan');
    check('gone after a week', $notes->forListeners($now + 8 * 86400)['show'] === null);
    $next = $p->goLive($now + 2000, 'Midday message', $dj)['result']['live'];
    check('a new show doesn’t show the old show’s notes', $notes->forListeners($now + 2010)['show'] === null);
    $notes->post($next['public_id'], $dj, 'note', ['text' => 'Welcome'], $now + 2020);
    $mid = $notes->forListeners($now + 2030);
    check('  only its own, once it posts', $mid['show']['title'] === 'Midday message' && count($mid['items']) === 1);
    for ($i = 1; $i < Notes::PER_SHOW; $i++) {
        $notes->post($next['public_id'], $dj, 'note', ['text' => "Note $i"], $now + 2030);
    }
    check('a show holds ' . Notes::PER_SHOW . ' notes at most', isset($notes->post($next['public_id'], $dj, 'note', ['text' => 'One too many'], $now + 2040)['error']));
    $p->endLive($now + 2100, $next['public_id']);
} finally {
    drop_test_database($t);
    remove_tree($root);
}
finish();
