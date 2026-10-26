<?php
declare(strict_types=1);

/**
 * Shared bootstrap: autoloading, config, error surface, small helpers.
 * Plain PHP on purpose so the whole app is a folder upload on shared hosting.
 */

define('VD_ROOT', dirname(__DIR__));
define('VD_VERSION', '1.0.0');

spl_autoload_register(static function (string $class): void {
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (glob(VD_ROOT . '/lib/*/*.php') ?: [] as $f) {
            $map[basename($f, '.php')] = $f;
        }
    }
    if (isset($map[$class])) {
        require_once $map[$class];
    }
});

$config = [];
foreach (glob(VD_ROOT . '/config/*.php') ?: [] as $file) {
    $config[basename($file, '.php')] = require $file;
}
$GLOBALS['vd_config'] = $config['app'] ?? [];

unset($config, $file);

function cfg(string $key, mixed $default = null): mixed
{
    $c = $GLOBALS['vd_config'] ?? [];
    foreach (explode('.', $key) as $part) {
        if (!is_array($c) || !array_key_exists($part, $c)) {
            return $default;
        }
        $c = $c[$part];
    }
    return $c;
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Bumps itself whenever any shipped css or js changes, so a cached stylesheet
 *  can never mask a deploy on a host that caches by url. */
if (!defined('ASSET_VER')) {
    $stamp = 0;
    foreach (glob(VD_ROOT . '/assets/{css,js}/*', GLOB_BRACE) ?: [] as $f) {
        $stamp = max($stamp, (int) filemtime($f));
    }
    define('ASSET_VER', (string) $stamp);
}

/** Directory url the front controller lives in, with a trailing slash, so asset
 *  paths stay correct even when a route is reached as /api/analyze. */
function base_href(): string
{
    static $base = null;
    if ($base === null) {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $base = rtrim(substr($script, 0, (int) strrpos($script, '/')), '/') . '/';
    }
    return $base;
}

/** Absolute url of the installed site, used by the canonical tag, robots and the
 *  sitemap. config app.base_url always wins; the requested host is only a fallback
 *  and is filtered so a stray Host: header cannot ride into a indexed url. */
function site_url(string $path = ''): string
{
    $root = rtrim((string) cfg('app.base_url', ''), '/');
    if ($root === '') {
        $https = (string) ($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) ($_SERVER['HTTPS'] ?? '')) !== 'off';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if (!preg_match('~^[A-Za-z0-9.\-]{1,100}(:\d{2,5})?$~', $host)) {
            $host = 'localhost';
        }
        $root = ($https ? 'https' : 'http') . '://' . $host . rtrim(base_href(), '/');
    }
    return $root . ($path === '' ? '' : '/' . ltrim($path, '/'));
}

/**
 * Seal a file address into the opaque u parameter of a relay link.
 *
 * The signature already proves a link belongs to this job; the seal is what keeps
 * the host's own signed url, with the access token inside it, out of the browser's
 * history, its logs, and anything the visitor copies the link into.
 */
function link_seal(string $url, string $secret): string
{
    $key = hash_hmac('sha256', 'relay-link', $secret, true);
    $iv = random_bytes(16);
    $ct = openssl_encrypt($url, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($ct === false) {
        throw new RuntimeException('Could not seal the media reference.');
    }
    return rtrim(strtr(base64_encode($iv . $ct), '+/', '-_'), '=');
}

function link_open(string $sealed, string $secret): ?string
{
    $b64 = strtr($sealed, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad === 1) {
        return null;
    }
    $raw = base64_decode($pad ? $b64 . str_repeat('=', 4 - $pad) : $b64, true);
    if (!is_string($raw) || strlen($raw) < 32) {
        return null;
    }
    $key = hash_hmac('sha256', 'relay-link', $secret, true);
    $url = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return is_string($url) ? $url : null;
}

function json_out(mixed $data, int $status = 200, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach ($headers as $k => $v) {
        header($k . ': ' . $v);
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function vd_log(string $message, array $context = []): void
{
    if (!cfg('logging.enabled', true)) {
        return;
    }
    $dir = VD_ROOT . '/' . trim((string) cfg('logging.dir', 'storage/logs'), '/');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $line = sprintf(
        "%s [%s] %s %s\n",
        date('Y-m-d H:i:s'),
        'INFO',
        $message,
        $context ? json_encode($context, JSON_UNESCAPED_SLASHES) : ''
    );
    @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
}

function human_bytes(int|float|null $bytes): ?string
{
    if ($bytes === null || $bytes <= 0) {
        return null;
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = min((int) floor(log((float) $bytes, 1024)), count($units) - 1);
    $v = $bytes / (1024 ** $i);
    return rtrim(rtrim(number_format($v, $v < 10 ? 2 : 1, '.', ''), '0'), '.') . ' ' . $units[$i];
}

/** Absolute url against an optional base, without touching already-absolute urls. */
function url_absolutize(string $base, string $maybeRelative): string
{
    $maybeRelative = trim($maybeRelative);
    if ($maybeRelative === '') {
        return $base;
    }
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $maybeRelative)) {
        return $maybeRelative; // already absolute (http:, data:, magnet: ...)
    }
    $p = parse_url($base);
    if (!isset($p['scheme'], $p['host'])) {
        return $maybeRelative;
    }
    $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (str_starts_with($maybeRelative, '//')) {
        return $p['scheme'] . ':' . $maybeRelative;
    }
    if ($maybeRelative[0] === '/') {
        return $origin . $maybeRelative;
    }
    $path = isset($p['path']) ? (str_ends_with($p['path'], '/') ? $p['path'] : dirname($p['path']) . '/') : '/';
    // Player configs like to spell their own folder as "./hls/master.m3u8".
    return $origin . $path . (str_starts_with($maybeRelative, './') ? substr($maybeRelative, 2) : $maybeRelative);
}
