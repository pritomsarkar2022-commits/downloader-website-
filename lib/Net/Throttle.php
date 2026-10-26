<?php
declare(strict_types=1);

/**
 * Flat-file rate limit. Shared hosting has no redis, and the abuse that matters
 * here is someone scripting thousands of analyses through our outbound ip.
 */
final class Throttle
{
    private string $dir;

    public function __construct()
    {
        $this->dir = VD_ROOT . '/storage/rate';
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    /** @return array{allowed:bool,retryAfter:int} */
    public function hit(string $key, int $limit = 12, int $window = 60): array
    {
        $file = $this->dir . '/' . sha1($key) . '.json';
        $now = time();
        $bucket = ['start' => $now, 'hits' => 0];
        if (is_file($file)) {
            $prev = json_decode((string) file_get_contents($file), true);
            if (is_array($prev) && ($prev['start'] ?? 0) + $window > $now) {
                $bucket = $prev;
            }
        }
        $bucket['hits']++;
        $allowed = $bucket['hits'] <= $limit;
        if (is_writable($this->dir)) {
            @file_put_contents($file, json_encode($bucket), LOCK_EX);
        }
        $this->maybeGc();
        return ['allowed' => $allowed, 'retryAfter' => max(1, ($bucket['start'] + $window) - $now)];
    }

    /** Shared plans count inodes, and every visitor leaves one small file behind. */
    private function maybeGc(): void
    {
        if (mt_rand(1, 50) !== 1) {
            return;
        }
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            if (time() - (int) filemtime($f) > 3600) {
                @unlink($f);
            }
        }
    }

    /**
     * Who a bucket belongs to. The socket address is the only key a caller cannot
     * choose, so the forwarded headers are read only when the operator says this
     * install really does sit behind a proxy: a forgeable key is no limit at all.
     */
    public static function clientKey(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
        if ((bool) cfg('security.trust_proxy_ip', false)) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
                $candidate = trim(explode(',', (string) ($_SERVER[$header] ?? ''))[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }
        return $ip;
    }
}
