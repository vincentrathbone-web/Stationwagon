<?php use function Stationwagon\h; use Stationwagon\{App, Listeners};
/** @var ?array $station @var string $email: the station's privacy contact, or '' */
$name = $station['name'] ?? 'This station';
?>
<article class="privacy">
  <h1>Privacy</h1>
  <p class="lede"><?= h($name) ?> counts its listeners so it knows how many people it reaches and when. Here is what it keeps and why.</p>

  <h2>What is kept when you listen</h2>
  <p>While the player plays, it tells the station once a minute that it’s still playing. With that message the station keeps, for each time you press Listen:</p>
  <ul>
    <li>a random number the player makes when you press Listen (it isn’t stored on your device and changes every time);</li>
    <li>when you started and how many minutes you listened;</li>
    <li>your internet address (IP address), and the details your browser sends about itself (its “user agent”: the kind of device, system and browser or app).</li>
  </ul>
  <p>From those the station works out the kind of device (phone, computer…), the browser or app, and roughly where you are: the country and nearest city, looked up on the station’s own server in a list of internet addresses (<a href="https://db-ip.com" rel="noopener" target="_blank">DB-IP</a>). Your address isn’t sent anywhere for this, and it gives a town at best, never a street.</p>
  <p><b>Listening sets no cookies.</b> Your browser keeps two small choices on your device, your volume and the look you picked; they stay there and aren’t sent to the station. Your name, email and the like are never asked for.</p>

  <h2>Why</h2>
  <p>Only to count: how many listen now and at each hour, how many different people listen in a day or a month, how long, on which devices and apps, and in which countries and cities. It helps the station plan its programmes and know whether its website, its embed on other websites, or links in apps reach people. It isn’t used for advertising and isn’t sold or given to anyone.</p>

  <h2>How long</h2>
  <p>Each Listen, with its internet address and browser details, is deleted after <b><?= Listeners::RAW_DAYS ?> days</b>. After that only totals are left (for example “40 listeners on Sunday, 30 of them on phones”), kept for about <?= (int) round(Listeners::KEEP_DAYS / 30) ?> months.</p>
  <p>To count different people, the station keeps a scrambled mark of your address and browser for each day you listen. The key that scrambles it changes every month and the old key is thrown away, so after that month no one, the station included, can tell whose mark it was.</p>

  <h2>Who can see it</h2>
  <p>The station’s managers see the totals on their Listeners page; they don’t see a list of addresses there. The station’s web host stores the data, and the pages and the stream pass through Cloudflare, which carries the connection. Like any website, the web host also keeps its own short-lived request logs.</p>

  <h2>Your rights</h2>
  <p>You can ask what is kept about you, and ask for it to be corrected or deleted, or object to it being kept. Under South Africa’s Protection of Personal Information Act you can also complain to the <a href="https://inforegulator.org.za" rel="noopener" target="_blank">Information Regulator</a>.</p>
  <p>If you’d rather not be counted at all, don’t press Listen: just opening the page keeps nothing about you.</p>

  <h2>Who to ask</h2>
  <?php if ($email !== ''): ?>
    <p>Write to <?= h($name) ?> at <a href="mailto:<?= h($email) ?>"><?= h($email) ?></a>. Say roughly when you listened and on what device, so the station can find your Listens.</p>
  <?php else: ?>
    <p>Contact <?= h($name) ?> the way you usually would. Say roughly when you listened and on what device, so the station can find your Listens.</p>
  <?php endif ?>

  <p class="muted"><a href="<?= h(App::url('')) ?>">Back to the player</a></p>
</article>
