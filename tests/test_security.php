<?php
declare(strict_types=1);

/**
 * Offline safety suite: nothing here touches the network, so it can run before a
 * deploy and inside CI on a machine with no outbound access.
 *
 *   php tests/test_security.php
 *
 * It covers the parts that must never regress: what url the server is willing to
 * fetch, which reader a host gets, how playlists are parsed, how relay links are
 * signed, and how the rate limit behaves.
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

/** @return array{0:bool,1:string} did it throw, and with what message */
function throws(callable $fn): array
{
    try {
        $fn();
        return [false, ''];
    } catch (Throwable $e) {
        $code = property_exists($e, 'codeName') ? '|' . $e->codeName : '';
        return [true, $e::class . ':' . $e->getMessage() . $code];
    }
}

// ---------------------------------------------------------------- url wall

group('UrlGuard: the server must never fetch what it is pointed at');

$blocked = [
    'loopback'            => 'http://127.0.0.1/video.mp4',
    'loopback other port' => 'http://127.0.0.1:8080/x.mp4',
    'localhost name'      => 'http://localhost/x.mp4',
    'private 10'          => 'http://10.0.0.7/x.mp4',
    'private 192'         => 'http://192.168.0.1/x.mp4',
    'private 172'         => 'http://172.16.5.5/x.mp4',
    'carrier nat'         => 'http://100.64.0.1/x.mp4',
    'cloud metadata'      => 'http://169.254.169.254/latest/meta-data/iam',
    'link local v6'       => 'http://[fe80::1]/x.mp4',
    'loopback v6'         => 'http://[::1]/x.mp4',
    'integer encoded ip'  => 'http://2130706433/x.mp4',
    'hex encoded ip'      => 'http://0x7f000001/x.mp4',
    'file scheme'         => 'file:///etc/passwd',
    'gopher scheme'       => 'gopher://127.0.0.1:6379/_GET%20x',
    'ftp scheme'          => 'ftp://example.com/etc/passwd',
    'redis port'          => 'http://example.com:6379/x',
    'ssh port'            => 'http://example.com:22/x',
    'userinfo trick'      => 'http://ok.com@127.0.0.1/x.mp4',
    'backslash trick'     => 'http://example.com\\@127.0.0.1/x.mp4',
    'internal suffix'     => 'http://builder.internal/x.mp4',
    'mdns suffix'         => 'http://host.local/x.mp4',
    'no host'             => 'https://',
    'not a url'           => 'hello world',
    'empty'               => '',
];
foreach ($blocked as $label => $url) {
    [$threw, $why] = throws(static fn() => UrlGuard::assertSafe($url));
    ok('blocks ' . $label, $threw);
    if (!$threw) {
        echo "         accepted {$url}\n";
    }
}

// Refusals are shown to the visitor, so they must not read like a log line.
$jargon = [];
foreach ($blocked as $label => $url) {
    [, $why] = throws(static fn() => UrlGuard::assertSafe((string) $url));
    if (preg_match('~\b(host|port|url|scheme|numeric|ipv6|malformed|exception)\b~i', $why)) {
        $jargon[] = (string) $label;
    }
}
ok('every refusal is written for a visitor' . ($jargon ? ': ' . implode(', ', $jargon) : ''), $jargon === []);

foreach (['https://streamtape.com/v/abc123', 'http://example.com/a.mp4', 'https://cdn.mxdrop.click:443/x.m3u8', 'https://sub.domain.cloudfam.io/a/b?token=1'] as $good) {
    [$threw] = throws(static fn() => UrlGuard::assertSafe($good));
    ok('allows ' . $good, !$threw);
}

// There is no escape hatch: even a caller that means to reach the dev server on
// this machine is refused, so no code path can turn the guard off by passing a flag.
[$threw] = throws(static fn() => UrlGuard::assertSafe('http://127.0.0.1:8085/x'));
ok('loopback is refused with no opt-out', $threw);
[$threw] = throws(static fn() => UrlGuard::assertSafe('http://localhost:8085/x'));
ok('localhost by name is refused', $threw);

// ---------------------------------------------------------------- input

group('Analyzer::normalize: junk in, clean error out');

$cases = [
    ['', 'empty'],
    ['   ', 'empty'],
    ['http://', 'bad_url'],
    ['https://ex ample.com/x', 'bad_url'],
    ['javascript:alert(1)', 'bad_url'],
];
foreach ($cases as [$in, $expect]) {
    [$threw, $why] = throws(static fn() => Analyzer::normalize($in));
    ok('rejects ' . var_export($in, true), $threw && str_contains($why, '|' . $expect));
    if (!$threw) {
        echo "         accepted " . var_export($in, true) . "\n";
    }
}
[$threw, $why] = throws(static fn() => Analyzer::normalize('http://127.0.0.1/x.mp4'));
ok('a private address never reaches the extractor', $threw && str_contains($why, 'UrlGuardException'));
ok('bare domain gains https', Analyzer::normalize('example.com/v/1') === 'https://example.com/v/1');
ok('https link survives untouched', Analyzer::normalize('https://cloudfam.io/cf3b9cf55ec3') === 'https://cloudfam.io/cf3b9cf55ec3');

// ---------------------------------------------------------------- selection

group('ExtractorRegistry: the right reader per host');

$registry = new ExtractorRegistry();
$select = [
    'https://streamtape.com/v/abc'                 => StreamtapeExtractor::class,
    'https://www.streamtape.com/v/abc'             => StreamtapeExtractor::class,
    'https://streamtape.to/v/abc'                  => StreamtapeExtractor::class,
    'https://cloudfam.io/cf3b9cf55ec3'             => CloudfamExtractor::class,
    'https://mxdrop.click/f/vngdxnpetkgneq'        => MxdropExtractor::class,
    'https://vixeo.io/e/2aEpRw0sAzmB'              => VixeoExtractor::class,
    'https://dupload.net/d69az94bqhso'             => XfsExtractor::class,
    // These three stop visitors at a gate, so the named refusal wins over any
    // generic reader that would otherwise return an empty result.
    'https://www.file-upload.org/emdsxzq5ihxi'     => BlockedExtractor::class,
    'https://upfilesgo.com/NOn8F5'                 => BlockedExtractor::class,
    'https://torupload.com/file/bzsidgtdpk1c'      => BlockedExtractor::class,
    'https://frdl.by/863fzrwqojan'                 => BlockedExtractor::class,
    'https://flash-files.com/Lmdk5gC6mxmjGSfG'     => BlockedExtractor::class,
    'https://some-unknown-site.example/v/1'        => GenericExtractor::class,
];
foreach ($select as $url => $want) {
    $got = $registry->forUrl((string) $url);
    ok(basename((string) parse_url($url, PHP_URL_PATH)) . ' on ' . (string) parse_url($url, PHP_URL_HOST) . ' -> ' . $want, $got instanceof $want);
}

// A look-alike host must not inherit the real host's reader.
foreach (['https://streamtape.com.evil.example/v/1', 'https://notstreamtape.com/v/1', 'https://cloudfam.io.attacker.test/x'] as $spoof) {
    ok('no reader leak for ' . (string) parse_url($spoof, PHP_URL_HOST), !$registry->forUrl($spoof) instanceof StreamtapeExtractor);
}

ok('registrable folds subdomains', ExtractorRegistry::registrable('CDN1.StreamTape.COM.') === 'streamtape.com');
ok('registrable keeps co.uk', ExtractorRegistry::registrable('a.b.site.co.uk') === 'site.co.uk');
ok('registrable survives a junk host', ExtractorRegistry::registrable('') === '');

// ---------------------------------------------------------------- media kind

group('MediaSniffer: what a url and a response actually are');

$kind = [
    'https://x.com/a.mp4'                       => Source::MP4,
    'https://x.com/a.MP4?token=abc'             => Source::MP4,
    'https://x.com/master.m3u8'                 => Source::HLS,
    'https://x.com/index.m3u8?KeyId=1'          => Source::HLS,
    'https://x.com/seg-1.ts'                    => Source::TS,
    'https://x.com/a.webm'                      => Source::WEBM,
    'https://x.com/a.mkv'                       => Source::MKV,
    'https://x.com/page.html'                   => null,
    'https://x.com/no-extension'                => null,
];
foreach ($kind as $u => $want) {
    ok((string) $u . ' is ' . var_export($want, true), MediaSniffer::kindOf((string) $u) === $want);
}

$html = '<script>var src="https://cdn.example.com/hls/1080/index.m3u8"; var b="/videos/clip.mp4";</script>'
    . '<video src="https://cdn.example.com/direct/movie.mp4"></video>'
    . '<a href="https://cdn.example.com/img/poster.jpg">poster</a>';
$found = MediaSniffer::mediaUrls($html);
ok('finds the m3u8 inside script text', in_array('https://cdn.example.com/hls/1080/index.m3u8', $found, true));
ok('finds the video tag source', in_array('https://cdn.example.com/direct/movie.mp4', $found, true));
ok('ignores an image', !in_array('https://cdn.example.com/img/poster.jpg', $found, true));

$sources = MediaSniffer::sourcesFromText($html, 'https://player.example.com/watch/1');
$kinds = array_map(static fn(Source $s) => $s->kind, $sources);
ok('relative media url is resolved against the page', (static function () use ($sources) {
    foreach ($sources as $s) {
        if (str_ends_with($s->url, '/videos/clip.mp4')) {
            return true;
        }
    }
    return false;
})());
ok('hls and mp4 both become sources', in_array(Source::HLS, $kinds, true) && in_array(Source::MP4, $kinds, true));

// ---------------------------------------------------------------- playlists

group('Playlist: parse, pick a quality, rewrite for the relay');

$master = "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=5000000,RESOLUTION=1920x1080,CODECS=\"avc1.640028\"\n1080/index.m3u8\n"
    . "#EXT-X-STREAM-INF:BANDWIDTH=1200000,RESOLUTION=640x360\n360/index.m3u8\n";
$p = Playlist::parse($master, 'https://cdn.example.com/hls/master.m3u8');
ok('master detected', $p->isMaster);
ok('two variants', count($p->variants) === 2);
ok('variant urls are absolute', $p->variants[0]['url'] === 'https://cdn.example.com/hls/1080/index.m3u8');
ok('height read', $p->variants[0]['height'] === 1080);
ok('codecs read', $p->variants[0]['codecs'] === 'avc1.640028');
$pick = $p->selectVariant(720);
// 360 is nearer to 720 than 1080 is, and the small over-target penalty keeps a
// player from jumping up to a stream it cannot sustain.
ok('a 720 request takes the closer low stream', $pick !== null && $pick['height'] === 360);
ok('an exact 1080 request takes the top stream', ($p->selectVariant(1080)['height'] ?? null) === 1080);
ok('no target uses the configured default', ($p->selectVariant(null)['height'] ?? null) === 1080);
ok('an empty variant list returns null', Playlist::parse('#EXTM3U', 'https://x.example.com/a')->selectVariant(480) === null);

$media = "#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXT-X-KEY:METHOD=AES-128,URI=\"https://k.example.com/key.bin\"\n#EXTINF:6.0,\nseg-1.ts\n#EXTINF:4.0,\nhttps://other.example.com/seg-2.ts\n#EXT-X-ENDLIST\n";
$m = Playlist::parse($media, 'https://cdn.example.com/hls/v/low.m3u8');
ok('media playlist detected', !$m->isMaster);
ok('segments counted', count($m->segments) === 2);
ok('relative segment resolved', $m->segments[0]['url'] === 'https://cdn.example.com/hls/v/seg-1.ts');
ok('absolute segment kept', $m->segments[1]['url'] === 'https://other.example.com/seg-2.ts');
ok('key uri resolved', is_array($m->key) && ($m->key['uri'] ?? '') === 'https://k.example.com/key.bin');
ok('duration totalled', abs($m->totalDuration - 10.0) < 0.001);
ok('endlist seen', $m->endList && !$m->live);

$rewritten = Playlist::rewrite($media, 'https://cdn.example.com/hls/v/low.m3u8', static fn(string $t): string => 'relay?url=' . rawurlencode($t));
ok('segments go through the relay', str_contains($rewritten, 'relay%3A%2F%2F') || str_contains($rewritten, 'relay?url='));
ok('no bare host url survives the rewrite', !str_contains($rewritten, 'https://cdn.example.com/hls/v/seg-1.ts'));
ok('the key uri is rewritten too', !str_contains($rewritten, 'https://k.example.com/key.bin'));

$broken = Playlist::parse("garbage\nnot a playlist at all\n", 'https://x.example.com/a');
ok('junk text parses without exploding', $broken->segments === [] && !$broken->isMaster);

// ---------------------------------------------------------------- signing

group('Signed relay links: tampering must fail');

$record = [
    'id' => '0123456789abcdef0123',
    'secret' => 'deadbeefdeadbeefdeadbeefdeadbeef',
    'expires' => time() + 600,
    'pageUrl' => 'https://streamtape.com/v/abc',
    'pageHost' => 'streamtape.com',
    'finalUrl' => 'https://streamtape.com/v/abc',
    'thumbHost' => '',
    'sources' => [],
];
$store = new JobStore();
$url = 'https://cdn.example.com/hls/master.m3u8';
$sig = $store->sign($record, $url);
ok('a fresh signature verifies', $store->verify($record, $url, $sig));
ok('signature is short hex', (bool) preg_match('/^[a-f0-9]{32}$/', $sig));
ok('different url is rejected', !$store->verify($record, $url . '?x=1', $sig));
ok('flipped character is rejected', !$store->verify($record, $url, substr_replace($sig, $sig[5] === 'a' ? 'b' : 'a', 5, 1)));
ok('empty signature is rejected', !$store->verify($record, $url, ''));
$other = $record;
$other['secret'] = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
ok("another job's signature is rejected", !$store->verify($other, $url, $sig));

foreach (['', 'zzzzzzzzzzzzzzzzzzzz', '../../config/app', '0123456789abcdef01', '0123456789abcdef0123456789'] as $badId) {
    ok('job id refused: ' . var_export($badId, true), $store->find($badId) === null);
}

// The browser must never see the origin url of a source.
$s = Source::make('https://cdn.example.com/secret/file.mp4?token=SUPERSECRET', Source::MP4);
$row = $s->toArray();
ok('source array carries no raw url', !str_contains(json_encode($row, JSON_UNESCAPED_SLASHES) ?: '', 'SUPERSECRET'));

// The relay link itself must not hand the host's signed address to the browser.
$tokenUrl = 'https://files.example.com/p/token=SUPERSECRET';
$sealed = link_seal($tokenUrl, (string) $record['secret']);
ok('a sealed reference opens back to the same url', link_open($sealed, (string) $record['secret']) === $tokenUrl);
ok('a sealed reference hides the url from a base64 decode', base64_decode($sealed, true) !== $tokenUrl);
ok('a sealed reference does not contain the token', !str_contains($sealed, 'SUPERSECRET'));
ok('another job cannot open it', link_open($sealed, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa') === null);
$tamperedSeal = substr($sealed, 0, -2) . ($sealed[strlen($sealed) - 1] === 'a' ? 'b' : 'a');
ok('a tampered reference opens to nothing', !is_string(link_open($tamperedSeal, (string) $record['secret'])));
foreach (['', 'a', '%%%%', str_repeat('A', 40)] as $junk) {
    ok('junk reference refused: ' . var_export($junk, true), link_open((string) $junk, (string) $record['secret']) === null);
}
$link = (new ReflectionMethod(Analyzer::class, 'relayLink'))->invoke(null, $record, $store, $tokenUrl, false, '', false);
$q = parse_url((string) $link);
parse_str((string) ($q['query'] ?? ''), $qa);
ok('relay link exposes only the sealed form', (string) ($qa['u'] ?? '') !== base64_encode($tokenUrl) && link_open((string) $qa['u'], (string) $record['secret']) === $tokenUrl);

// Download names come from page data, so they must not carry paths or newlines.
foreach ([['../../etc/passwd', 'no traversal'], ["a\r\nContent-Disposition: x", 'no header injection'], ['مرحبا world', 'unicode kept']] as [$title, $why]) {
    $rec = $record;
    $rec['title'] = $title;
    $name = (new ReflectionMethod(Analyzer::class, 'fileName'))->invoke(null, $rec, $s);
    ok('file name (' . $why . '): ' . json_encode($name), !preg_match('#[\r\n/\\\\]#', (string) $name) && str_ends_with((string) $name, '.mp4'));
}
ok('relay default name is safe', !preg_match('#[\r\n"\\\\/]#', Relay::defaultName('https://x.example.com/../../etc/passwd')));

// A page that already names the file must not end up as "clip.mp4.mp4".
foreach ([['clip.mp4', 'clip.mp4'], ['Clip.MP4', 'Clip.MP4'], ['clip.mkv', 'clip.mkv.mp4'], ['no extension', 'no extension.mp4']] as [$title, $want]) {
    $rec = $record;
    $rec['title'] = $title;
    $got = (new ReflectionMethod(Analyzer::class, 'fileName'))->invoke(null, $rec, $s);
    ok('file name for ' . json_encode($title) . ' is ' . json_encode($want), $got === $want, (string) $got);
}

// ---------------------------------------------------------------- rate limit

group('Throttle: the limit holds and cannot be walked around');

$dir = VD_ROOT . '/storage/rate';
$before = glob($dir . '/*.json') ?: [];

$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.9';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.5, 10.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'curl/8.0';
$keyA = Throttle::clientKey();
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (totally different)';
$keyB = Throttle::clientKey();
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.99';
$keyC = Throttle::clientKey();
ok('rotating the user agent does not open a new bucket', $keyA === $keyB);
ok('forging CF-Connecting-IP does not open a new bucket', $keyA === $keyC);

$GLOBALS['vd_config']['security'] = ['trust_proxy_ip' => true];
$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.77';
ok('an operator who opts in gets the forwarded address', Throttle::clientKey() === '198.51.100.77');
$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
ok('a junk CF header drops to the next forwarded header', Throttle::clientKey() === '192.0.2.5');
unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);
ok('no usable forwarded header falls back to the socket address', Throttle::clientKey() === '203.0.113.7');
$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip, 10.0.0.1';
ok('a junk forwarded header falls back to the socket address', Throttle::clientKey() === '203.0.113.7');
unset($GLOBALS['vd_config']['security']);
unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_USER_AGENT']);
$_SERVER['REMOTE_ADDR'] = 'cli';

$salt = 'sec-' . bin2hex(random_bytes(4));
$t = new Throttle();
$seen = [];
for ($i = 0; $i < 6; $i++) {
    $seen[] = $t->hit($salt . '|probe', 3, 60)['allowed'];
}
ok('first three pass', array_slice($seen, 0, 3) === [true, true, true]);
ok('fourth is blocked', $seen[3] === false);
ok('a different client is not blocked', $t->hit($salt . '|other-client', 3, 60)['allowed']);
$wait = $t->hit($salt . '|probe', 3, 60)['retryAfter'];
ok('the retry hint is a usable number of seconds', is_int($wait) && $wait >= 1 && $wait <= 60);

foreach (glob($dir . '/*.json') ?: [] as $made) {
    if (!in_array($made, $before, true)) {
        @unlink($made);
    }
}

// ---------------------------------------------------------------- results

echo "\n";
printf("%d passed, %d failed\n", $pass, $fail);
foreach ($failures as $f) {
    echo "  failed: {$f}\n";
}
exit($fail === 0 ? 0 : 1);
