<?php
declare(strict_types=1);

/**
 * Same-origin relay: the browser only ever talks to this site, while the real
 * file host gets the Referer/Cookie it demands. Streams in fixed-size chunks so
 * a 700MB file never touches memory.
 */
final class Relay
{
    public static function run(array $record, string $url, string $sig, bool $asDownload = false, string $filename = '', bool $isPlaylist = false): never
    {
        $store = new JobStore();
        if (!$store->verify($record, $url, $sig)) {
            http_response_code(403);
            header('Content-Type: text/plain');
            echo 'This download link is not valid.';
            exit;
        }
        // Only hosts this job already saw are reachable through the relay.
        if (!self::hostAllowed($record, $url)) {
            http_response_code(403);
            header('Content-Type: text/plain');
            echo 'That file host is not part of this analysis.';
            exit;
        }
        try {
            UrlGuard::assertSafe($url);
        } catch (UrlGuardException $e) {
            // The link was signed, so this is a second wall rather than the first:
            // a stored job whose file address has since become an internal one.
            http_response_code(403);
            header('Content-Type: text/plain');
            echo 'That file address is not reachable.';
            exit;
        }

        $headers = self::headersFor($record, $url);
        // These hosts name playlists .txt and segments .css, so the flag travels
        // in the signed link instead of being guessed from the filename.
        if ($isPlaylist && !$asDownload) {
            self::serveRewrittenPlaylist($record, $url, $headers, $store);
        }
        self::stream($url, $headers, $asDownload, $filename, $isPlaylist);
    }

    private static function hostAllowed(array $record, string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        if ($host === '') {
            return false;
        }
        $allowed = [];
        foreach ($record['sources'] ?? [] as $s) {
            $h = strtolower((string) (parse_url((string) ($s['url'] ?? ''), PHP_URL_HOST) ?: ''));
            if ($h !== '') {
                $allowed[$h] = true;
                // cdn1.host.tld and host.tld are the same publisher.
                $allowed[implode('.', array_slice(explode('.', $h), -2))] = true;
            }
        }
        $regisable = implode('.', array_slice(explode('.', $host), -2));
        foreach (['pageHost', 'thumbHost'] as $k) {
            if (!empty($record[$k]) && (string) $record[$k] === $regisable) {
                return true;
            }
        }
        return isset($allowed[$host]) || isset($allowed[$regisable]);
    }

    /** @return array<string,string> */
    private static function headersFor(array $record, string $url): array
    {
        $out = [];
        foreach ($record['sources'] ?? [] as $s) {
            if (($s['url'] ?? '') === $url) {
                foreach (($s['headers'] ?? []) as $k => $v) {
                    $out[$k] = $v;
                }
            }
        }
        if (!isset($out['Referer']) && !empty($record['finalUrl'])) {
            $out['Referer'] = (string) $record['finalUrl'];
        }
        $cookies = [];
        foreach ($record['sources'] ?? [] as $s) {
            foreach ((array) ($s['cookies'] ?? []) as $k => $v) {
                $cookies[$k] = $v;
            }
        }
        if ($cookies) {
            $out['Cookie'] = implode('; ', array_map(static fn($k, $v) => $k . '=' . $v, array_keys($cookies), $cookies));
        }
        return $out;
    }

    private static function serveRewrittenPlaylist(array $record, string $url, array $headers, JobStore $store): never
    {
        $http = new HttpClient(1);
        try {
            $res = $http->get($url, ['headers' => $headers, 'accept' => 'application/x-mpegURL,application/vnd.apple.mpegurl,*/*;q=0.8', 'max_bytes' => 4_000_000]);
        } catch (HttpException $e) {
            http_response_code(502);
            header('Content-Type: text/plain');
            echo 'The video host refused to send its playlist.';
            exit;
        }
        if (!$res->ok() || !str_contains($res->body, '#EXTM3U')) {
            http_response_code(502);
            header('Content-Type: text/plain');
            echo 'That playlist could not be read.';
            exit;
        }
        $base = $res->finalUrl;
        $job = (string) $record['id'];
        $parsed = Playlist::parse($res->body, $base);
        // In a master playlist the bare lines are themselves playlists; in a media
        // playlist they are segments, and key/init uris never are.
        $lineIsPlaylist = $parsed->isMaster;
        $link = static function (string $target, bool $forceNotPlaylist = false) use ($store, $record, $job, $lineIsPlaylist): string {
            $q = [
                'r' => 'relay',
                'job' => $job,
                'u' => link_seal($target, (string) $record['secret']),
                's' => $store->sign($record, $target),
            ];
            if ($lineIsPlaylist && !$forceNotPlaylist) {
                $q['p'] = '1';
            }
            return self::appUrl(http_build_query($q));
        };
        $body = Playlist::rewrite($res->body, $base, $link);

        http_response_code(200);
        header('Content-Type: application/vnd.apple.mpegurl');
        header('Cache-Control: private, no-store');
        header('Access-Control-Allow-Origin: *');
        echo $body;
        exit;
    }

    public static function appUrl(string $query): string
    {
        $base = cfg('app.base_url', '');
        if (is_string($base) && $base !== '') {
            return rtrim($base, '/') . '/?' . $query;
        }
        $scheme = (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $path = rtrim(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
        if ($path === '' || $path === '/') {
            $path = '/index.php';
        }
        return $scheme . '://' . $host . $path . '?' . $query;
    }

    private static function stream(string $url, array $headers, bool $asDownload, string $filename, bool $isPlaylist, ?string $caBundle = null): never
    {
        $try = self::attemptStream($url, $headers, $asDownload, $filename, $isPlaylist, $caBundle);

        // A file host that sends an incomplete certificate chain is fixed by
        // supplying the missing issuer, never by switching verification off.
        if ($try['errno'] !== 0 && $caBundle === null && !$try['sent'] && HttpClient::isCertError($try['errno'])) {
            $bundle = CertChain::bundleFor((string) (parse_url($url, PHP_URL_HOST) ?: ''));
            if ($bundle !== null) {
                $try = self::attemptStream($url, $headers, $asDownload, $filename, $isPlaylist, $bundle);
            }
        }

        if ($try['errno'] !== 0 || !$try['ok']) {
            if (!$try['sent']) {
                http_response_code(502);
                header('Content-Type: text/plain');
            }
            vd_log('relay stream failed', ['url' => $url, 'errno' => $try['errno']]);
            echo "\n<!-- connection to the video host dropped -->";
        }
        exit;
    }

    /** @return array{ok:bool,errno:int,sent:bool} */
    private static function attemptStream(string $url, array $headers, bool $asDownload, string $filename, bool $isPlaylist, ?string $caBundle): array
    {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        @set_time_limit(0);

        // A manifest is a few hundred bytes of text, and some hosts 403 a ranged one.
        $range = $isPlaylist ? '' : (string) ($_SERVER['HTTP_RANGE'] ?? '');
        $ch = curl_init();
        // Same header set the analyser used, or hosts that probe for Accept-Language
        // answer a relayed request with 403 while the preview of the same file works.
        $headerLines = HttpClient::headerLines(
            $headers,
            $isPlaylist ? 'application/vnd.apple.mpegurl,application/x-mpegURL,*/*;q=0.8' : '*/*'
        );
        if ($range !== '') {
            $headerLines[] = 'Range: ' . $range;
        }

        $sentHeaders = false;
        $remoteStatus = 0;
        $remoteHeaders = [];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$remoteHeaders, &$remoteStatus): int {
                $len = strlen($line);
                if (preg_match('#^HTTP/\d(?:\.\d)? (\d{3})#', $line, $m)) {
                    $remoteStatus = (int) $m[1];
                    return $len;
                }
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $remoteHeaders[strtolower(trim($k))] = trim($v);
                }
                return $len;
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (&$sentHeaders, &$remoteStatus, &$remoteHeaders, $asDownload, $filename, $isPlaylist, $url): int {
                if (!$sentHeaders) {
                    $sentHeaders = true;
                    self::passHeaders($remoteStatus, $remoteHeaders, $asDownload, $filename, $isPlaylist, $url);
                }
                echo $chunk;
                flush();
                return strlen($chunk);
            },
        ]);

        if ($caBundle !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        }

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        return ['ok' => $ok !== false, 'errno' => $errno, 'sent' => $sentHeaders];
    }

    private static function passHeaders(int $status, array $h, bool $asDownload, string $filename, bool $isPlaylist, string $url): void
    {
        // Some file hosts answer 206 to every request, even one with no Range in
        // it. A browser that asked for the whole file must not be told it got a
        // piece of it, so the partial status only travels when we relayed a range.
        $ranged = isset($_SERVER['HTTP_RANGE']) && $_SERVER['HTTP_RANGE'] !== '';
        http_response_code($status === 206 && $ranged ? 206 : ($status >= 400 ? $status : 200));
        $type = $h['content-type'] ?? ($isPlaylist ? 'application/vnd.apple.mpegurl' : 'application/octet-stream');
        if (str_contains(strtolower($type), 'octet-stream') && !$isPlaylist) {
            $type = match (MediaSniffer::kindOf($url)) {
                Source::MP4 => 'video/mp4',
                Source::WEBM => 'video/webm',
                Source::HLS => 'application/vnd.apple.mpegurl',
                default => 'application/octet-stream',
            };
        }
        header('Content-Type: ' . $type);
        if (!empty($h['content-length'])) {
            header('Content-Length: ' . $h['content-length']);
        }
        if ($ranged && !empty($h['content-range'])) {
            header('Content-Range: ' . $h['content-range']);
        }
        if (!empty($h['accept-ranges'])) {
            header('Accept-Ranges: ' . $h['accept-ranges']);
        }
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');

        if ($asDownload) {
            $name = $filename !== '' ? $filename : self::defaultName($url);
            header('Content-Disposition: attachment; filename="' . addslashes($name) . '"');
        }
    }

    public static function defaultName(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $base = preg_replace('/[^\w.\- ]+/', '', basename($path)) ?: 'video';
        $kind = MediaSniffer::kindOf($url);
        if (!str_contains($base, '.')) {
            $base .= '.' . match ($kind) {
                Source::HLS, Source::TS => 'ts',
                Source::WEBM => 'webm',
                default => 'mp4',
            };
        }
        return substr($base, 0, 120);
    }
}
