<?php
$brand = (string) cfg('brand.name', 'Video Downloader');
$lede = (string) cfg('brand.sub_tagline', 'Turn any video page into a direct download, with a preview first.');
/** @var array $view */
$hosts = $view['hosts'] ?? [];

// One recognisable domain per reader, so the list reads as brands and not as a
// pile of cdn mirrors.
$tldRank = ['com' => 0, 'net' => 1, 'to' => 2, 'io' => 3, 'gs' => 4, 'me' => 5, 'org' => 6, 'site' => 7, 'live' => 8, 'click' => 9, 'ch' => 10, 'co' => 11, 'ps' => 12, 'sx' => 13, 'pe' => 14, 'info' => 15, 'pro' => 16];
$coreOf = static function (string $h): string { $p = explode('.', $h); return strtolower($p[count($p) - 2] ?? $h); };
$tldOf = static function (string $h): string { $p = explode('.', $h); return strtolower((string) end($p)); };
$groups = [];
foreach ($hosts as $host => $reader) {
    $groups[$reader][] = $host;
}
foreach ($groups as $reader => $list) {
    usort($list, static function (string $a, string $b) use ($reader, $coreOf, $tldOf, $tldRank): int {
        $sa = $coreOf($a) === $reader ? 0 : 1;
        $sb = $coreOf($b) === $reader ? 0 : 1;
        if ($sa !== $sb) {
            return $sa - $sb;
        }
        return ($tldRank[$tldOf($a)] ?? 99) <=> ($tldRank[$tldOf($b)] ?? 99) ?: strcmp($a, $b);
    });
    $groups[$reader] = $list;
}
ksort($groups);
$chipHosts = array_map(static fn (array $l): string => $l[0], array_values($groups));
?>
<?php require __DIR__ . '/parts/head.php'; ?>

<section class="hero">
  <div class="wrap">
    <div class="hero-split">
      <div>
        <span class="eyebrow"><span class="dot"></span> Free, no signup</span>
        <h1 class="display-lg">Paste a link. Get the MP4.</h1>
        <p class="lede body-lg"><?= e($lede) ?></p>
        <div class="row-flex" style="margin-top:22px">
          <a class="btn btn-filled btn-lg" href="#tool">
            <svg class="ic"><use href="#ic-content_paste"/></svg> Paste a video link
          </a>
          <a class="btn btn-text btn-lg" href="#sites">
            See supported sites
            <svg class="ic"><use href="#ic-arrow_forward"/></svg>
          </a>
        </div>
        <div class="stats">
          <div><b>MP4</b><span>Original file when offered</span></div>
          <div><b>HLS</b><span>Joined into one MP4</span></div>
          <div><b>1080p</b><span>Best the host serves</span></div>
        </div>
      </div>

      <div class="tool" id="tool">
        <div class="tool-head">
          <div>
            <h2 class="title-lg">Video link</h2>
            <p class="body-sm">Paste the page you watch, not the .mp4 file.</p>
          </div>
          <span class="badge" id="job-state"><svg class="ic" style="font-size:15px"><use href="#ic-bolt"/></svg> Ready</span>
        </div>

        <form class="stack" id="analyze-form" autocomplete="off">
          <div>
            <div class="field" id="url-field">
              <input type="url" id="url" name="url" inputmode="url" spellcheck="false" placeholder=" " aria-describedby="url-help">
              <label for="url">https://streamtape.com/v/...</label>
              <span class="affix">
                <button class="btn-icon" type="button" id="paste-btn" aria-label="Paste from clipboard">
                  <svg class="ic"><use href="#ic-content_paste_go"/></svg>
                </button>
              </span>
            </div>
            <p class="field-help" id="url-help">One link at a time. Direct file links work too.</p>
          </div>
          <button class="btn btn-filled btn-lg" type="submit" id="go-btn" style="width:100%">
            <svg class="ic"><use href="#ic-search"/></svg>
            <span id="go-label">Find the video</span>
          </button>
          <div class="progress indeterminate hide" id="busy-bar"></div>
        </form>

        <div class="host-chips" aria-label="Known sites">
          <?php foreach ($chipHosts as $h): ?>
            <span class="chip"><svg class="ic"><use href="#ic-video_file"/></svg><?= e($h) ?></span>
          <?php endforeach; ?>
          <span class="chip chip-fill">and any page that serves a video file</span>
        </div>

        <div class="results" id="results" aria-live="polite"></div>
      </div>
    </div>
  </div>
</section>

<section class="section section-alt" id="how">
  <div class="wrap stack-lg">
    <div style="max-width:62ch">
      <h2 class="display-sm">Three steps, no account</h2>
      <p class="body-lg muted">Most downloaders fail because they only read the link you paste. This one opens the page the way a browser does, decodes the player script, then checks the file is really there before it shows you anything.</p>
    </div>
    <div class="grid grid-3">
      <div class="step"><span class="n">1</span><div><h3 class="title-md">Paste the page link</h3><p class="body-md muted">Copy it from your browser bar or the share button on the player.</p></div></div>
      <div class="step"><span class="n">2</span><div><h3 class="title-md">We resolve the file</h3><p class="body-md muted">MP4 links are read directly. HLS playlists are opened and every quality is listed.</p></div></div>
      <div class="step"><span class="n">3</span><div><h3 class="title-md">Preview, then take it</h3><p class="body-md muted">Watch it in the player, copy the working link, or download the file.</p></div></div>
    </div>
  </div>
</section>

<section class="section">
  <div class="wrap stack-lg">
    <h2 class="display-sm">What you get</h2>
    <div class="grid grid-3">
      <?php
      $features = [
        ['movie_filter', 'Real file detection', 'The page is opened the way a browser opens it, hidden scripts are decoded, and every candidate file is checked before it is shown to you.'],
        ['high_quality', 'Every quality listed', 'A stream that carries several qualities is opened and each one is listed, so you pick 1080p, 720p or the smallest file that plays.'],
        ['preview', 'Watch before you save', 'The preview plays through this site, so it keeps working even when the video host blocks other sites.'],
        ['link', 'A link that works', "The link you copy carries the host's own key and stays alive for as long as the host allows."],
        ['speed', 'Built for slow hosts', 'Large files move in pieces instead of all at once, so a long video does not crash halfway through.'],
        ['shield', 'Nothing stored', 'Analysis results expire on their own. No database, no account, no video kept on disk.'],
      ];
      foreach ($features as [$icon, $title, $text]): ?>
        <article class="feature">
          <div class="ico"><svg class="ic"><use href="#ic-<?= e($icon) ?>"/></svg></div>
          <h3 class="title-lg"><?= e($title) ?></h3>
          <p class="body-md muted"><?= e($text) ?></p>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section section-alt" id="sites">
  <div class="wrap stack-lg">
    <div style="max-width:64ch">
      <h2 class="display-sm">Supported sites</h2>
      <p class="body-lg muted">Hosts with a dedicated reader. Any other page that exposes a video file still goes through the general reader, so paste it and see.</p>
    </div>
    <div class="card card-out hosts table-scroll">
      <?php
      $kindOf = static function (string $reader): string {
          return in_array($reader, ['streamtape', 'cloudfam', 'mxdrop', 'xfs'], true) ? 'MP4' : 'MP4 or HLS';
      };
      ?>
      <table class="hosts-table">
        <thead><tr><th>Site</th><th>What comes back</th></tr></thead>
        <tbody>
        <?php foreach ($groups as $reader => $list): ?>
          <tr>
            <td>
              <b><?= e($list[0]) ?></b>
              <?php if (count($list) > 1): ?><span class="alias">and <?= count($list) - 1 ?> more domains</span><?php endif; ?>
            </td>
            <td><span class="badge<?= $kindOf($reader) === 'MP4' ? ' badge-ok' : '' ?>"><?= $kindOf($reader) ?></span></td>
          </tr>
        <?php endforeach; ?>
        <tr><td><b>any other site</b></td><td><span class="badge">best effort</span></td></tr>
        </tbody>
      </table>
    </div>
    <div class="notice">
      <svg class="ic"><use href="#ic-info"/></svg>
      <span>Some hosts put a wait timer, a captcha or an app requirement in front of the file. Those are their rules and this tool will not defeat them. It only reads what the page already serves.</span>
    </div>
  </div>
</section>

<section class="section" id="plans">
  <div class="wrap stack-lg">
    <div style="max-width:60ch">
      <h2 class="display-sm">Plans</h2>
      <p class="body-lg muted">Start free. Pay only if you need batch links or your own domain.</p>
    </div>
    <div class="grid grid-3">
      <article class="plan">
        <div><span class="title-md">Free</span><div class="price">$0<small> / forever</small></div></div>
        <ul>
          <li><svg class="ic"><use href="#ic-check"/></svg> Unlimited single link lookups</li>
          <li><svg class="ic"><use href="#ic-check"/></svg> Preview and copy link</li>
          <li><svg class="ic"><use href="#ic-check"/></svg> MP4 and HLS detection</li>
        </ul>
        <a class="btn btn-outlined" href="#tool" style="justify-content:center">Use it now</a>
      </article>
      <article class="plan pop">
        <span class="badge badge-warn flag">Most popular</span>
        <div><span class="title-md">Pro</span><div class="price">$6<small> / month</small></div></div>
        <ul>
          <li><svg class="ic"><use href="#ic-check"/></svg> Everything in Free</li>
          <li><svg class="ic"><use href="#ic-check"/></svg> Batch up to 50 links</li>
          <li><svg class="ic"><use href="#ic-check"/></svg> HLS saved as one MP4 file</li>
          <li><svg class="ic"><use href="#ic-check"/></svg> Higher download ceiling</li>
        </ul>
        <a class="btn btn-filled" href="#tool" style="justify-content:center">Try Pro links</a>
      </article>
      <article class="plan">
        <div><span class="title-md">Team</span><div class="price">$19<small> / month</small></div></div>
        <ul>
          <li><svg class="ic"><use href="#ic-check"/></svg> Everything in Pro</li>
          <li><svg class="ic"><use href="#ic-check"/></svg> Your own domain and logo</li>
          <li><svg class="ic"><use href="#ic-check"/></svg> Priority host support</li>
        </ul>
        <a class="btn btn-tonal" href="#tool" style="justify-content:center">Talk to us</a>
      </article>
    </div>
  </div>
</section>

<section class="section section-alt" id="faq">
  <div class="wrap faq-wrap">
    <h2 class="display-sm">Common questions</h2>
    <?php
    $faq = [
      ['Why do you need the page link and not the .mp4 link?', 'The .mp4 link the player uses is signed and usually expires in minutes. Reading the page lets us fetch a fresh one the moment you download.'],
      ['What is HLS and why is it not already MP4?', 'HLS is a stream split into many small pieces with an index file. Players love it, but it is not a single file you can keep, so this site collects the pieces and writes them as one MP4.'],
      ['Do you keep my links or the videos?', 'No. The analysis is held in a short lived file so the preview can stream, then it is deleted on its own.'],
      ['Will every site work?', 'No. A site that gates the file behind a login, a captcha or a timer stays gated. We tell you that plainly instead of failing quietly.'],
      ['Is this legal?', 'Owning the file or having permission to copy it is your responsibility. The tool only reaches data a page already publishes.'],
    ];
    foreach ($faq as [$q, $a]): ?>
      <details class="faq-item">
        <summary><?= e($q) ?></summary>
        <div class="ans"><?= e($a) ?></div>
      </details>
    <?php endforeach; ?>
  </div>
</section>

<section class="section">
  <div class="wrap">
    <div class="cta-band">
      <h2 class="display-sm">One link is all it takes</h2>
      <p class="body-lg" style="max-width:60ch;margin:0">No signup, no app, no extension. Paste it and get the file.</p>
      <a class="btn btn-lg btn-invert" href="#tool">
        <svg class="ic"><use href="#ic-download"/></svg> Start now
      </a>
    </div>
  </div>
</section>

<?php require __DIR__ . '/parts/footer.php'; ?>
<script src="<?= e(base_href()) ?>assets/js/app.js?v<?= ASSET_VER ?>" defer></script>
</body>
</html>
