<?php
declare(strict_types=1);

/**
 * Offline suite for the general deep reader: the shapes a player uses to name its
 * file, the documents it delegates to, and the ranking that decides which
 * candidate gets a probe. Nothing here touches the network.
 *
 *   php tests/test_deep.php
 *
 * The live half of this proof is tests/deep_matrix.php.
 */

require __DIR__ . '/../lib/support.php';

$pass = 0;
$fail = 0;
/** @var list<string> $failures */
$failures = [];

function ok(string $label, bool $cond): void
{
    global $pass, $fail, $failures;
    if ($cond) {
        $pass++;
        printf("  [ok]   %s\n", $label);
    } else {
        $fail++;
        $failures[] = $label;
        printf("  [FAIL] %s\n", $label);
    }
}

function group(string $title): void
{
    echo "\n== {$title}\n";
}

/** @param Source[] $sources */
function urls(array $sources): array
{
    return array_map(static fn(Source $s) => $s->url, $sources);
}

/** @param Source[] $sources */
function has(array $sources, string $needle): bool
{
    foreach ($sources as $s) {
        if (str_contains($s->url, $needle)) {
            return true;
        }
    }
    return false;
}

function find(HttpResponse $res, string $base = ''): array
{
    return MediaSniffer::candidates(JsUnpacker::flatten($res->body), $res->finalUrl, ['Referer' => $res->finalUrl]);
}

function page(string $html, string $url): HttpResponse
{
    return new HttpResponse(200, $html, [], 'text/html', $url, strlen($html));
}

const SITE = 'https://watch.example.org/episode/41';

// ------------------------------------------------------- structured html tags

group('Structured tags: the shapes that name a file on purpose');

$og = '<html><head>'
    . '<meta property="og:video" content="https://cdn.example.org/hls/master.m3u8">'
    . '<meta property="og:video:secure_url" content="https://cdn.example.org/backup/1080.m3u8">'
    . '<meta name="twitter:player:stream" content="https://cdn.example.org/twitter/stream.mp4">'
    . '<link rel="alternate" type="application/x-mpegURL" href="/playlist/top.m3u8">'
    . '</head><body>'
    . '<video controls src="/player/direct.mp4"></video>'
    . '<video data-src="https://cdn.example.org/lazy/720.mp4"></video>'
    . '<source src="/alt/web.webm" type="video/webm">'
    . '<source srcset="/alt/pair.mp4 1280w, /alt/small.mp4 640w">'
    . '<embed src="/flash/old.swf"><embed src="/flash/movie.mp4">'
    . '<object data="/flash/legacy.mp4"></object>'
    . '<a href="https://cdn.example.org/download/clip.mkv">download</a>'
    . '<a href="/about/terms">terms</a>'
    . '</body></html>';
$found = find(page($og, SITE));
$got = urls($found);
ok('og:video becomes a source', has($found, 'hls/master.m3u8'));
ok('og:video:secure_url becomes a source', has($found, 'backup/1080.m3u8'));
ok('twitter:player:stream becomes a source', has($found, 'twitter/stream.mp4'));
ok('an mpegurl link tag becomes a source', has($found, 'playlist/top.m3u8'));
ok('a video src becomes a source', has($found, 'player/direct.mp4'));
ok('a lazy video data-src becomes a source', has($found, 'lazy/720.mp4'));
ok('a source src becomes a source', has($found, 'alt/web.webm'));
ok('a source srcset gives both files', has($found, 'alt/pair.mp4') && has($found, 'alt/small.mp4'));
ok('an embed with a file becomes a source', has($found, 'flash/movie.mp4'));
ok('an object data becomes a source', has($found, 'flash/legacy.mp4'));
ok('a download link becomes a source', has($found, 'download/clip.mkv'));
ok('a flash file is not a video source', !has($found, 'old.swf'));
ok('a navigation link is not a video source', !in_array('https://watch.example.org/about/terms', $got, true));
$kinds = [];
foreach ($found as $s) {
    $kinds[$s->url] = $s->kind;
}
ok('the mkv link is labelled mkv, not mp4', ($kinds['https://cdn.example.org/download/clip.mkv'] ?? '') === Source::MKV);
ok('the webm source is labelled webm', ($kinds['https://watch.example.org/alt/web.webm'] ?? '') === Source::WEBM);
ok('every candidate carries a referer', (static function (array $sources): bool {
    foreach ($sources as $s) {
        if (($s->headers['Referer'] ?? '') !== SITE) {
            return false;
        }
    }
    return true;
})($found));

group('Base href: a relative player path resolves against the app, not the url');

$relBase = '<html><head><base href="/player/app/"></head><body>'
    . '<video src="clips/inner.mp4"></video>'
    . '<source src="./alt/web.webm">'
    . '<video data-src="https://cdn.example.org/lazy/720.mp4"></video>'
    . '</body></html>';
$found = find(page($relBase, SITE));
ok('a relative file follows the base tag', has($found, '/player/app/clips/inner.mp4'));
ok('a dot-relative file follows the base tag too', has($found, '/player/app/alt/web.webm'));
ok('an absolute file ignores the base tag', has($found, 'lazy/720.mp4'));

// ------------------------------------------------------------ player configs

group('Player config: the key names every js player hides its file behind');

$configs = [
    'jwplayer' => '<script>jwplayer("k").setup({sources:[{file:"https://cdn.example.org/jw/720.mp4"}],image:"x.jpg"});</script>',
    'videojs' => '<script>videojs("v",{sources:[{src:"/vj/hls/master.m3u8",type:"application/x-mpegURL"}]});</script>',
    'plyr' => '<script>const player = new Plyr("#p",{sources:[{src:"https://cdn.example.org/plyr/clip.mp4"}]});</script>',
    'clappr' => '<script>var options = {sources: [{url: "https://cdn.example.org/clap/stream.m3u8"}]};</script>',
    'hand rolled' => '<script>var playerConfig = {"videoUrl":"https://cdn.example.org/hr/main.mp4","hls_url":"//cdn.example.org/hr/hls.m3u8"};</script>',
    'flowplayer' => '<script>flowplayer("#p", {clip: {url: "/fp/movie.mp4"}});</script>',
    'escaped json' => '<script>var data = {\"file\":\"https:\\/\\/cdn.example.org\\/esc\\/one.mp4\"};</script>',
    'concatenated' => '<script>var u = "https://cdn.example.org/" + "join/final" + ".mp4";</script>',
    'packed' => '<script>eval(function(p,a,c,k,e,d){e=function(c){return c};if(!\'.replace(/^/,String)\'){while(c--){d[c]=k[c]||c}k=[function(e){return d[e]}];e=function(){return \'\\\\w+\'};c=1};while(c--){if(k[c]){p=p.replace(new RegExp(\'\\\\b\'+e(c)+\'\\\\b\'),k[c])}}return p}(\'1 0={6:"3://2.5/4/one.mp4"}</script>',
];
foreach ($configs as $label => $html) {
    $found = find(page($html, SITE));
    ok($label . ' config file url is found', $found !== []);
}
$found = find(page($configs['hand rolled'], SITE));
ok('both the mp4 and the hls of a hand rolled config survive', has($found, 'hr/main.mp4') && has($found, 'hr/hls.m3u8'));
$found = find(page($configs['escaped json'], SITE));
ok('an escaped json url is decoded and kept', has($found, 'esc/one.mp4'));
$found = find(page($configs['concatenated'], SITE));
ok('a concatenated url is rejoined', has($found, 'join/final.mp4'));
$found = find(page('<script>var a={file:"/bare/endpoint/no-extension"};</script>', SITE));
ok('an extension-less file endpoint is kept for probing', has($found, 'bare/endpoint/no-extension'));
$found = find(page('<script>var a={url:"/bare/thing"};</script>', SITE));
ok('a weak url key with no media shape is dropped', !has($found, 'bare/thing'));

// ---------------------------------------------------------------- JSON-LD

group('JSON-LD: the block a site publishes so search engines can find its video');

$ld = '<script type="application/ld+json">'
    . '{"@context":"https://schema.org","@type":"VideoObject","name":"Episode 41",'
    . '"contentUrl":"https://cdn.example.org/ld/main.mp4",'
    . '"embedUrl":"https://player.example.org/embed/ld",'
    . '"thumbnailUrl":"https://cdn.example.org/ld/poster.jpg",'
    . '"interactionStatistic":{"@type":"InteractionCounter","url":"https://example.org/not-a-file"}}'
    . '</script>'
    . '<script type="application/ld+json">{"@type":"ItemList","itemListElement":[{"@type":"VideoObject","contentUrl":["https://cdn.example.org/ld/array.mp4"]}]}</script>';
$found = find(page($ld, SITE));
ok('a VideoObject contentUrl is a source', has($found, 'ld/main.mp4'));
ok('a VideoObject embedUrl is a page to open, not a file', !has($found, 'embed/ld'));
$ldFinder = new DeepFinder(new HttpClient(26), new MediaBundle());
$ldFinder->readDocument(page($ld, SITE), 0);
$ldQueue = array_map(static fn(array $j) => $j['url'], $ldFinder->queued());
ok('a VideoObject embedUrl is queued for the next level', in_array('https://player.example.org/embed/ld', $ldQueue, true));
ok('a contentUrl list is a source', has($found, 'ld/array.mp4'));
ok('a poster image is not a source', !has($found, 'ld/poster.jpg'));
ok('a plain url field is not a file', !has($found, 'not-a-file'));

// ------------------------------------------------------- wrapper documents

group('Delegating documents: what the walk intends to open next');

$wrapper = '<html><head><meta http-equiv="refresh" content="0;url=/real/page"></head><body>'
    . '<iframe src="https://embed.example.net/player/99"></iframe>'
    . '<iframe data-src="//embed.example.net/lazy/100"></iframe>'
    . '<embed src="/legacy/flash-player.html"></embed>'
    . '<object data="/object/player.html"></object>'
    . '<script>if (top !== self) { top.location = "/busted/out";} else { window.location.href = "https://alt.example.net/watch/7"; }</script>'
    . '<a href="/page/2">next</a>'
    . '</body></html>';
$pages = MediaSniffer::playerPages($wrapper, SITE);
ok('an iframe is chased', in_array('https://embed.example.net/player/99', $pages, true));
ok('a lazy iframe is chased', in_array('https://embed.example.net/lazy/100', $pages, true));
ok('an embed document is chased', in_array('https://watch.example.org/legacy/flash-player.html', $pages, true));
ok('an object document is chased', in_array('https://watch.example.org/object/player.html', $pages, true));
ok('a meta refresh is chased', in_array('https://watch.example.org/real/page', $pages, true));
ok('a javascript location is chased', in_array('https://alt.example.net/watch/7', $pages, true));
ok('an ordinary link is not chased', !in_array('https://watch.example.org/page/2', $pages, true));
ok('a same host document is tried before a foreign one', $pages !== [] && str_contains($pages[0], 'watch.example.org'));

$endpoints = MediaSniffer::configEndpoints(
    '<script src="/js/player.json"></script><script>fetch("/api/video/sources?id=9");</script>'
    . '<script src="/js/app.js"></script><a href="/config/page">no</a>',
    SITE,
    3
);
ok('a json config is chased', in_array('https://watch.example.org/js/player.json', $endpoints, true));
ok('a video api call is chased', in_array('https://watch.example.org/api/video/sources?id=9', $endpoints, true));
ok('a plain script file is not chased', !in_array('https://watch.example.org/js/app.js', $endpoints, true));
ok('the config list respects its limit', count($endpoints) <= 3);

// --------------------------------------------------------------- ranking

group('Ranking: which candidate gets one of the few probes');

$mixed = '<html><head><meta property="og:video" content="https://cdn.example.org/real/feature.mp4"></head><body>'
    . '<script>var promo="https://cdn.example.org/ads/promo-banner.mp4";</script>'
    . '<video src="https://cdn.example.org/real/feature.mp4"></video>'
    . '<script>var fallback="https://cdn.example.org/other/720.mp4";</script>'
    . '</body></html>';
$found = find(page($mixed, SITE));
$g = urls($found);
ok('the file the page declares comes first', ($g[0] ?? '') === 'https://cdn.example.org/real/feature.mp4');
ok('the advertised clip is ranked below the feature', array_search('https://cdn.example.org/ads/promo-banner.mp4', $g, true) > array_search('https://cdn.example.org/other/720.mp4', $g, true));
ok('the same file named twice is kept once', count($g) === count(array_unique($g)));

$many = '';
for ($i = 1; $i <= 60; $i++) {
    $many .= '<a href="https://cdn.example.org/many/file' . $i . '.mp4">f</a>';
}
$found = find(page($many, SITE));
ok('the candidate list is capped before it can grow', count($found) <= (int) cfg('extract.deep_candidates', 40));

// ----------------------------------------------------------- hostile input

group('Hostile and broken input: the reader must not be able to hurt anything');

$hostile = '<video src="javascript:alert(1)"></video>'
    . '<video src="data:video/mp4;base64,AAAA"></video>'
    . '<video src="blob:https://example.org/8ce1"></video>'
    . '<iframe src="javascript:void(0)"></iframe>'
    . '<meta property="og:video" content="file:///etc/passwd">'
    . '<a href="http://127.0.0.1:8080/private.mp4">internal</a>'
    . '<a href="http://169.254.169.254/latest/meta.mp4">metadata</a>'
    . '<a href="https://example.org/quote.mp4?auth=1&amp;token=abc">entity</a>'
    . '<a href="https://example.org/' . str_repeat('x', 1400) . '.mp4">too long</a>'
    . '<video src="unclosed.mp4><a href="/broken">';
$found = find(page($hostile, SITE));
$g = urls($found);
ok('every candidate is an http or https url', array_filter($g, static fn($u) => preg_match('~^https?://~i', $u) !== 1) === []);
ok('an html entity inside a url is decoded', has($found, 'quote.mp4?auth=1&token=abc'));
ok('an over long url is dropped', !has($found, str_repeat('x', 1400)));
$refused = [];
foreach ($found as $s) {
    try {
        UrlGuard::assertSafe($s->url);
    } catch (UrlGuardException $e) {
        $refused[] = $s->url;
    }
}
ok('a private address found in markup is refused before any request', in_array('http://127.0.0.1:8080/private.mp4', $refused, true));
ok('a cloud metadata address is refused before any request', in_array('http://169.254.169.254/latest/meta.mp4', $refused, true));
ok('a frame with a javascript src is never queued', !in_array('javascript:void(0)', MediaSniffer::playerPages($hostile, SITE), true));

// ------------------------------------------------------------ deep finder

group('DeepFinder: the walk itself, without the network');

$http = new HttpClient(26);
$bundle = new MediaBundle();
$finder = new DeepFinder($http, $bundle);
$finder->readDocument(page($wrapper, SITE), 0);
$queue = $finder->queued();
$depths = array_unique(array_map(static fn(array $j) => $j['depth'], $queue));
ok('every document found on the page is queued one level deeper', $depths === [1]);
ok('the queue is not unbounded', count($queue) <= (int) cfg('extract.frames_per_page', 5) + (int) cfg('extract.json_configs', 3));

$you = '<iframe src="https://www.youtube.com/embed/abcd1234"></iframe>'
    . '<iframe src="https://m.youtube.com/watch?v=abcd1234"></iframe>'
    . '<iframe src="https://player.example.net/keepme"></iframe>';
$b2 = new MediaBundle();
$finder2 = new DeepFinder($http, $b2);
$finder2->readDocument(page($you, SITE), 0);
$kept = array_map(static fn(array $j) => $j['url'], $finder2->queued());
ok('only the honest player stays in the queue', $kept === ['https://player.example.net/keepme']);
ok('the visitor is told why one player was skipped', (bool) array_filter($b2->notices, static fn($n) => str_contains($n, 'keeps to itself')));
ok('a look-alike host is not confused with YouTube', !DeepFinder::skipped('https://youtube.com.evil.test/video'));
ok('youtube and its subdomains are recognised', DeepFinder::skipped('https://m.youtube.com/x')
    && DeepFinder::skipped('https://youtu.be/x')
    && DeepFinder::skipped('https://video.googlevideo.com/x'));

$b3 = new MediaBundle();
$f3 = new DeepFinder($http, $b3);
$f3->readDocument(page('<video src="/one/deep/path.mp4"></video>', SITE), 3);
ok('a file at the deepest allowed level still counts', has($f3->ranked(), 'one/deep/path.mp4'));
ok('a file at the bottom level does not queue more pages', $f3->queued() === []);

$hint = [
    'https://cdn.example.org/hls/1080/index.m3u8' => 1080,
    'https://cdn.example.org/a/720p.mp4' => 720,
    'https://cdn.example.org/v?id=480' => 480,
    'https://cdn.example.org/2024/clip.mp4' => null,
    'https://cdn.example.org/14400/clip.mp4' => null,
    'https://cdn.example.org/plain/clip.mp4' => null,
];
foreach ($hint as $u => $want) {
    ok($u . ' reads as ' . var_export($want, true), DeepFinder::heightHint((string) $u) === $want);
}
$pl = (new DeepFinder($http, new MediaBundle()));
$pl->readDocument(page('<video src="https://cdn.example.org/hls/1080/index.m3u8"></video>', SITE), 0);
$pl = $pl->ranked();
ok('a playlist takes its resolution from the master, not the url guess', $pl !== [] && $pl[0]->height === null);
$mp = (new DeepFinder($http, new MediaBundle()));
$mp->readDocument(page('<video src="https://cdn.example.org/files/720/clip.mp4"></video>', SITE), 0);
$mp = $mp->ranked();
ok('a single file keeps the quality its own address states', $mp !== [] && $mp[0]->height === 720);

// ------------------------------------------------------------- the registry

group('Registry: who answers a YouTube link');

$registry = new ExtractorRegistry();
foreach (['https://www.youtube.com/watch?v=1', 'https://youtu.be/1', 'https://m.youtube.com/watch?v=1',
    'https://music.youtube.com/watch?v=1', 'https://www.youtube-nocookie.com/embed/1'] as $u) {
    $e = $registry->forUrl($u);
    ok($u . ' is answered by the YouTube refusal', $e instanceof YouTubeExtractor);
}
$caught = null;
try {
    $registry->forUrl('https://youtu.be/1')->extract('https://youtu.be/1', $http);
} catch (ExtractException $e) {
    $caught = $e;
}
ok('the refusal is final, not a fallthrough', $caught !== null && $caught->codeName === 'gated');
ok('the refusal explains itself without jargon', $caught !== null
    && !preg_match('~api|token|cookie|regex|curl|http|drm|ssrf~i', $caught->getMessage())
    && !preg_match('/[—–~]/u', $caught->getMessage() . $caught->hint));
foreach (['https://notyoutube.com/watch?v=1', 'https://watch.example.org/episode/41'] as $u) {
    ok($u . ' still goes to the general reader', $registry->forUrl($u) instanceof GenericExtractor);
}
ok('YouTube is not advertised as a supported host', !array_key_exists('youtube.com', $registry->knownHosts()));

// ------------------------------------------------------------------ report

echo "\n";
if ($failures) {
    echo "Failures:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
}
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
