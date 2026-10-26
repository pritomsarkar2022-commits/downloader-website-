<?php
declare(strict_types=1);

require __DIR__ . '/lib/support.php';

date_default_timezone_set((string) cfg('app.timezone', 'UTC'));

if (cfg('app.debug', false)) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED);
}

// Pretty urls (/api/analyze) and query urls (?r=analyze) both work, so the app
// runs on a host with or without mod_rewrite.
$route = trim((string) ($_GET['r'] ?? ($_SERVER['PATH_INFO'] ?? '')), '/');
if ($route === '' && preg_match('#^/(api|health)/([a-z]+)#i', (string) ($_SERVER['REQUEST_URI'] ?? ''), $m)) {
    $route = strtolower($m[2]);
}
if ($route === '' && preg_match('#^/(robots\.txt|sitemap\.xml)$#i', (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), $m)) {
    $route = strtolower($m[1]) === 'robots.txt' ? 'robots' : 'sitemap';
}

$securityHeaders = static function (): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
};
$securityHeaders();

try {
    switch ($route) {
        case 'analyze':
            handle_analyze();
            break;
        case 'build':
            handle_build();
            break;
        case 'progress':
            handle_progress();
            break;
        case 'file':
            handle_file();
            break;
        case 'relay':
            handle_relay();
            break;
        case 'health':
            handle_health();
            break;
        case 'robots':
            handle_robots();
            break;
        case 'sitemap':
            handle_sitemap();
            break;
        case '':
        case 'index':
            handle_page();
            break;
        default:
            http_response_code(404);
            $notFound = true;
            require __DIR__ . '/views/error.php';
    }
} catch (ExtractException $e) {
    json_out([
        'ok' => false,
        'error' => $e->getMessage(),
        'hint' => $e->hint,
        'code' => $e->codeName,
    ], 422);
} catch (UrlGuardException $e) {
    // The ssrf guard already speaks in visitor terms, and a refused address is a
    // bad link, not our failure, so it must not land in the generic 500 below.
    json_out([
        'ok' => false,
        'error' => $e->getMessage(),
        'hint' => 'Paste the link of a video page on a public site.',
        'code' => 'bad_url',
    ], 422);
} catch (HttpException $e) {
    vd_log('http failure', ['url' => $e->url, 'status' => $e->status, 'msg' => $e->getMessage()]);
    json_out([
        'ok' => false,
        'error' => $e->getMessage(),
        'hint' => 'The video host is blocking our server or is down. A minute of waiting usually fixes it.',
        'code' => 'network',
    ], 502);
} catch (Throwable $e) {
    vd_log('unhandled', ['msg' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    if (preg_match('#^(api|analyze|relay|build|progress|file|health|json)#', $route)) {
        json_out(['ok' => false, 'error' => 'Something went wrong on our side.', 'code' => 'server'], 500);
    }
    http_response_code(500);
    $fatal = cfg('app.debug', false) ? $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() : '';
    require __DIR__ . '/views/error.php';
    exit;
}

function handle_page(): void
{
    $registry = new ExtractorRegistry();
    $view = ['hosts' => $registry->knownHosts()];
    require __DIR__ . '/views/home.php';
}

function handle_analyze(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_out(['ok' => false, 'error' => 'Use POST.'], 405);
    }
    $limit = new Throttle();
    $verdict = $limit->hit(Throttle::clientKey() . '|api', 20, 60);
    if (!$verdict['allowed']) {
        json_out([
            'ok' => false,
            'error' => 'Too many links at once.',
            'hint' => 'Wait ' . $verdict['retryAfter'] . ' seconds and try again.',
            'code' => 'rate_limited',
            'retryAfter' => $verdict['retryAfter'],
        ], 429);
    }

    $raw = $_POST['url'] ?? null;
    if ($raw === null) {
        $body = file_get_contents('php://input');
        $json = json_decode((string) $body, true);
        $raw = is_array($json) ? ($json['url'] ?? null) : null;
    }
    if (!is_string($raw) || strlen($raw) > 2000) {
        json_out(['ok' => false, 'error' => 'Paste a video page link.', 'code' => 'empty'], 422);
    }

    $result = Analyzer::analyze($raw);
    json_out(['ok' => true] + $result);
}

/**
 * Start an HLS -> MP4 build. The work itself happens in small slices, one per
 * request, so a long stream survives a shared host cutting scripts off at 30s.
 */
function handle_build(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_out(['ok' => false, 'error' => 'Use POST.'], 405);
    }
    $limit = new Throttle();
    $verdict = $limit->hit(Throttle::clientKey() . '|build', 6, 60);
    if (!$verdict['allowed']) {
        json_out([
            'ok' => false,
            'error' => 'Too many saves at once.',
            'hint' => 'Wait ' . $verdict['retryAfter'] . ' seconds and try again.',
            'code' => 'rate_limited',
        ], 429);
    }

    $jobs = new JobStore();
    $record = $jobs->find((string) ($_POST['job'] ?? ''));
    if ($record === null) {
        json_out([
            'ok' => false,
            'error' => 'That analysis expired.',
            'hint' => 'Run the video page again.',
            'code' => 'job_expired',
        ], 410);
    }
    $source = $jobs->source($record, (string) ($_POST['src'] ?? ''));
    if ($source === null) {
        json_out(['ok' => false, 'error' => 'Unknown file in that result.', 'code' => 'bad_source'], 422);
    }

    $store = new BuildStore();
    $http = new HttpClient((int) cfg('build.request_limit', 900));
    $build = Mp4Builder::begin($record, $source, $http);
    $build = Mp4Builder::tick($build, $store);
    json_out(['ok' => true, 'build' => $build['id']] + Mp4Builder::progress($build, $store));
}

function handle_progress(): void
{
    $store = new BuildStore();
    $record = $store->find((string) ($_GET['build'] ?? ''));
    if ($record === null) {
        json_out([
            'ok' => false,
            'done' => true,
            'error' => 'This save expired before it finished.',
            'hint' => 'Run the video page again.',
            'code' => 'build_expired',
        ], 410);
    }
    $record = Mp4Builder::tick($record, $store);
    json_out(Mp4Builder::progress($record, $store));
}

/**
 * Hand out the finished MP4. Only a build this server created and signed can be
 * read, and only by its signature, so this is never an open file download.
 */
function handle_file(): void
{
    $store = new BuildStore();
    $record = $store->find((string) ($_GET['build'] ?? ''));
    if ($record === null || !$store->verify($record, (string) ($_GET['s'] ?? ''))) {
        http_response_code(410);
        header('Content-Type: text/plain');
        echo 'This download expired. Run the video page again.';
        exit;
    }
    $path = $store->paths((string) $record['id'])['mp4'];
    if (($record['status'] ?? '') !== 'done' || !is_file($path)) {
        http_response_code(409);
        header('Content-Type: text/plain');
        echo 'That file is not ready yet.';
        exit;
    }

    $size = (int) filesize($path);
    $start = 0;
    $end = $size - 1;
    $status = 200;
    if (preg_match('/^bytes=(\d*)-(\d*)$/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') {
            $start = max(0, $size - (int) $m[2]);
        } else {
            $start = min((int) $m[1], $size - 1);
            if ($m[2] !== '') {
                $end = min((int) $m[2], $size - 1);
            }
        }
        if ($start > $end) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        $status = 206;
    }

    header('Content-Type: video/mp4');
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . ($end - $start + 1));
    header('Cache-Control: private, no-store');
    if ($status === 206) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    // The name comes from the stored record, never from the query string, so a
    // crafted header value cannot ride along with it.
    header('Content-Disposition: ' . (($_GET['play'] ?? '') === '1' ? 'inline' : 'attachment')
        . '; filename="' . $record['filename'] . '"');
    http_response_code($status);
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    @set_time_limit(0);

    $fh = fopen($path, 'rb');
    if ($fh === false) {
        exit;
    }
    fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fh)) {
        $chunk = fread($fh, min(262144, $left));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $left -= strlen($chunk);
    }
    fclose($fh);
    exit;
}

function handle_relay(): void{
    $job = (string) ($_GET['job'] ?? '');
    $enc = (string) ($_GET['u'] ?? '');
    $sig = (string) ($_GET['s'] ?? '');
    $store = new JobStore();
    $record = $store->find($job);
    if ($record === null) {
        http_response_code(410);
        header('Content-Type: text/plain');
        echo 'This link expired. Go back and analyse the video page again.';
        exit;
    }
    $url = link_open($enc, (string) ($record['secret'] ?? ''));
    if ($url === null || strlen($url) > 4000 || !preg_match('#^https?://#i', $url)) {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo 'Bad media reference.';
        exit;
    }
    Relay::run($record, $url, $sig, (($_GET['dl'] ?? '') === '1'), (string) ($_GET['name'] ?? ''), (($_GET['p'] ?? '') === '1'));
}

function handle_robots(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=86400');
    echo "User-agent: *\n";
    echo "Allow: /\n";
    // Nothing below these is a page, and the relay hands out other people's media.
    echo "Disallow: /api/\n";
    echo "Disallow: /health\n";
    echo 'Sitemap: ' . site_url('sitemap.xml') . "\n";
    exit;
}

function handle_sitemap(): void
{
    // The whole product is one page, so the sitemap is one real url rather than a
    // list of anchors that would only dilute the crawl budget.
    $home = site_url('/');
    $updated = max(array_map(
        'filemtime',
        array_filter([VD_ROOT . '/index.php', VD_ROOT . '/views/home.php', VD_ROOT . '/assets/css/app.css'], 'is_file')
    ));
    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    echo "  <url>\n";
    echo '    <loc>' . e($home) . "</loc>\n";
    echo '    <lastmod>' . date('c', (int) $updated) . "</lastmod>\n";
    echo "    <changefreq>monthly</changefreq>\n";
    echo "  </url>\n";
    echo "</urlset>\n";
    exit;
}

function handle_health(): void
{
    json_out([
        'ok' => true,
        'app' => 'video-downloader',
        'version' => VD_VERSION,
        'php' => PHP_VERSION,
        'curl' => function_exists('curl_init'),
        'openssl' => function_exists('openssl_decrypt'),
        'mbstring' => extension_loaded('mbstring'),
        'storage_writable' => is_writable(VD_ROOT . '/storage'),
        'outbound' => (static function (): string {
            try {
                $r = (new HttpClient(1))->get('https://example.com/', ['max_bytes' => 4096, 'timeout' => 8]);
                return $r->status >= 200 && $r->status < 500 ? 'ok' : 'blocked';
            } catch (Throwable $e) {
                return 'blocked: ' . $e->getMessage();
            }
        })(),
    ]);
}
