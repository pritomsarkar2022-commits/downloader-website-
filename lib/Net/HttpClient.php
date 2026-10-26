<?php
declare(strict_types=1);

/**
 * The app's only outbound door. Manual redirect following so every hop is
 * re-validated by UrlGuard, a per-analysis request budget, and one cookie jar
 * shared across the whole extraction so session cookies survive the hops.
 */
final class HttpClient
{
    private int $budget;
    private ?string $jar = null;

    /** @var array<int,array{url:string,status:int,bytes:int}> */
    public array $trace = [];

    public function __construct(?int $budget = null)
    {
        $this->budget = $budget ?? (int) cfg('extract.request_limit', 8);
    }

    public function remaining(): int
    {
        return max(0, $this->budget);
    }

    public function cookieHeader(): string
    {
        if ($this->jar === null || !is_file($this->jar)) {
            return '';
        }
        $out = [];
        foreach (file($this->jar, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if ($line === '' || $line[0] === '#' || substr_count($line, "\t") < 6) {
                continue;
            }
            $c = explode("\t", $line);
            $out[$c[5]] = $c[6];
        }
        return implode('; ', array_map(static fn($k, $v) => $k . '=' . $v, array_keys($out), $out));
    }

    public function get(string $url, array $opts = []): HttpResponse
    {
        return $this->request($url, $opts);
    }

    public function post(string $url, string $body, array $opts = []): HttpResponse
    {
        return $this->request($url, $opts + ['body' => $body, 'method' => 'POST']);
    }

    public function request(string $url, array $opts = []): HttpResponse
    {
        if ($this->budget <= 0) {
            throw new HttpException('That page needs too many requests to resolve a file.', 504, $url);
        }

        $method = strtoupper($opts['method'] ?? 'GET');
        $maxBytes = (int) ($opts['max_bytes'] ?? cfg('http.page_max_bytes', 2_000_000));
        $maxHops = (int) ($opts['max_redirects'] ?? cfg('http.max_redirects', 5));
        $headers = $opts['headers'] ?? [];
        $current = $url;

        for ($hop = 0;; $hop++) {
            UrlGuard::assertSafe($current);
            $res = $this->doRequest($method, $current, $headers, $opts, $maxBytes);
            $this->budget--;
            $this->trace[] = ['url' => $current, 'status' => $res->status, 'bytes' => $res->downloadedBytes];

            $location = $res->header('location');
            if ($res->status >= 300 && $res->status < 400 && $location !== null && $hop < $maxHops) {
                $next = url_absolutize($current, $location);
                if ($res->status !== 307 && $res->status !== 308) {
                    $method = 'GET';
                    unset($opts['body'], $opts['method']);
                }
                $headers['Referer'] = $current;
                $current = $next;
                continue;
            }
            return $res;
        }
    }

    /**
     * The browser-ish header set every outbound request carries. Some hosts answer
     * a request without Accept-Language with 403, so the relay must use this too
     * rather than its own copy.
     *
     * @param array<string,string> $extra overrides, keyed by header name
     * @return string[] curl-ready "Name: value" lines
     */
    public static function headerLines(array $extra = [], string $accept = ''): array
    {
        $base = [
            'User-Agent' => (string) cfg('http.user_agent'),
            'Accept' => $accept !== '' ? $accept : 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => (string) cfg('http.accept_language', 'en-US,en;q=0.9'),
        ];
        foreach ($extra as $k => $v) {
            $base[$k] = $v;
        }
        $lines = [];
        foreach ($base as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        return $lines;
    }

    private function doRequest(string $method, string $url, array $headers, array $opts, int $maxBytes, ?string $caBundle = null): HttpResponse
    {
        $this->jar ??= tempnam(sys_get_temp_dir(), 'vdck');

        $headerLines = self::headerLines(
            $headers,
            (string) ($opts['accept'] ?? '')
        );

        $body = '';
        $responseHeaders = [];
        $abort = false;
        $toFile = $opts['to_file'] ?? null;
        $fh = null;
        $written = 0;

        if ($toFile !== null) {
            $fh = fopen($toFile, 'wb');
            if (!$fh) {
                throw new HttpException('Could not write the download to disk.');
            }
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_FOLLOWLOCATION => false,  // each hop is validated separately
            CURLOPT_AUTOREFERER => false,
            CURLOPT_CONNECTTIMEOUT => (int) cfg('http.connect_timeout', 8),
            CURLOPT_TIMEOUT => (int) ($opts['timeout'] ?? cfg('http.timeout', 20)),
            CURLOPT_ENCODING => '',
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOSIGNAL => 1,
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => $toFile === null,
            CURLOPT_BUFFERSIZE => 65536,
            CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$responseHeaders): int {
                $len = strlen($line);
                if ($len > 2 && str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($k))] = trim($v);
                }
                return $len;
            },
        ]);
        if ($caBundle !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        }
        if (!empty($opts['range'])) {
            curl_setopt($ch, CURLOPT_RANGE, $opts['range']);
        }
        if (is_array($opts['form'] ?? null)) {
            // curl builds the multipart body and its boundary header itself.
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['form']);
        } elseif (!empty($opts['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
        }

        if ($toFile !== null) {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_FILE, $fh);
        } else {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION,
                /** @param mixed $ch */
                static function ($ch, float $dlTotal, float $dlNow) use (&$abort, $maxBytes): int {
                    if ($dlNow > $maxBytes) {
                        $abort = true;
                        return 1;
                    }
                    return 0;
                });
        }

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $finalUrl = (string) (curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url);
        curl_close($ch);

        if ($fh !== null) {
            $written = (int) ftell($fh);
            fclose($fh);
        }
        if ($errno === CURLE_ABORTED_BY_CALLBACK) {
            $errno = 0; // size cap only, the bytes read so far are still valid
        }
        if ($errno !== 0) {
            if ($toFile !== null && is_file($toFile)) {
                @unlink($toFile);
            }
            if ($caBundle === null && self::isCertError($errno)) {
                $bundle = CertChain::bundleFor((string) (parse_url($url, PHP_URL_HOST) ?: ''));
                if ($bundle !== null) {
                    return $this->doRequest($method, $url, $headers, $opts, $maxBytes, $bundle);
                }
            }
            throw new HttpException(self::curlMessage($errno, $errmsg), $status, $url);
        }

        if ($abort) {
            $responseHeaders['x-vd-truncated'] = '1';
        }

        return new HttpResponse(
            $status,
            $toFile !== null ? '' : (string) $raw,
            $responseHeaders,
            $contentType,
            $finalUrl,
            $toFile !== null ? $written : strlen((string) $raw),
        );
    }

    /** errno values that mean "the chain did not build", not "the host is down". */
    public static function isCertError(int $errno): bool
    {
        return in_array($errno, [
            CURLE_SSL_CACERT,        // 60, also CURLE_SSL_PEER_CERTIFICATE
            CURLE_SSL_CACERT_BADFILE,
            CURLE_SSL_CONNECT_ERROR,
        ], true);
    }

    private static function curlMessage(int $errno, string $errmsg): string
    {
        return match ($errno) {
            CURLE_COULDNT_RESOLVE_HOST => 'That web address does not resolve.',
            CURLE_COULDNT_CONNECT => 'That site refused the connection.',
            CURLE_OPERATION_TIMEDOUT => 'That site took too long to answer.',
            CURLE_SSL_CONNECT_ERROR, CURLE_SSL_PEER_CERTIFICATE, CURLE_SSL_CERTPROBLEM, CURLE_SSL_CACERT => 'That site has a broken TLS certificate.',
            CURLE_TOO_MANY_REDIRECTS => 'That page keeps redirecting.',
            default => 'Could not reach that page' . ($errmsg !== '' ? ': ' . $errmsg : '.'),
        };
    }
}
