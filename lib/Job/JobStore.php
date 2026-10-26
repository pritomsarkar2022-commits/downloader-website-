<?php
declare(strict_types=1);

/**
 * Analysis results live in small json files, not a database: shared hosting
 * without cron, no migrations, nothing to configure. Each job carries a secret
 * that signs the relay urls, so the relay can never become an open proxy.
 */
final class JobStore
{
    private string $dir;

    public function __construct()
    {
        $this->dir = VD_ROOT . '/storage/jobs';
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    public function create(MediaBundle $b): array
    {
        $this->maybeGc();
        $secret = bin2hex(random_bytes(16));
        $id = substr(hash_hmac('sha256', $b->pageUrl . $secret, 'vd-job'), 0, 20);
        $record = [
            'id' => $id,
            'secret' => $secret,
            'created' => time(),
            'expires' => time() + (int) cfg('jobs.ttl', 1800),
            'provider' => $b->provider,
            'title' => $b->title,
            'thumbnail' => $b->thumbnail,
            'pageUrl' => $b->pageUrl,
            'pageHost' => ExtractorRegistry::registrable((string) (parse_url($b->pageUrl, PHP_URL_HOST) ?: '')),
            'finalUrl' => $b->finalUrl,
            'thumbHost' => ExtractorRegistry::registrable((string) (parse_url((string) $b->thumbnail, PHP_URL_HOST) ?: '')),
            'notices' => $b->notices,
            'confidence' => $b->confidence,
            'sources' => array_map(static fn(Source $s) => self::encode($s), $b->sources),
        ];
        $this->write($id, $record);
        return $record;
    }

    public function find(string $id): ?array
    {
        if (!preg_match('/^[a-f0-9]{20}$/', $id)) {
            return null;
        }
        $file = $this->path($id);
        if (!is_file($file)) {
            return null;
        }
        $rec = json_decode((string) file_get_contents($file), true);
        if (!is_array($rec) || ($rec['expires'] ?? 0) < time()) {
            @unlink($file);
            return null;
        }
        return $rec;
    }

    public function touch(string $id, array $record): void
    {
        $this->write($id, $record);
    }

    public function source(array $record, string $sourceId): ?Source
    {
        foreach ($record['sources'] ?? [] as $data) {
            if (($data['id'] ?? '') === $sourceId) {
                return self::decode($data);
            }
        }
        return null;
    }

    /** Sign a url the browser is allowed to pull through the relay. */
    public function sign(array $record, string $url): string
    {
        return substr(hash_hmac('sha256', $url, (string) $record['secret']), 0, 32);
    }

    public function verify(array $record, string $url, string $sig): bool
    {
        return hash_equals($this->sign($record, $url), $sig);
    }

    private function path(string $id): string
    {
        return $this->dir . '/' . $id . '.json';
    }

    private function write(string $id, array $record): void
    {
        $json = json_encode($record, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new ExtractException('Could not save the analysis result.');
        }
        file_put_contents($this->path($id), $json, LOCK_EX);
        @chmod($this->path($id), 0664);
    }

    private function maybeGc(): void
    {
        if (mt_rand(1, 100) > (int) (cfg('jobs.gc_probability', 0.05) * 100)) {
            return;
        }
        $maxAge = (int) cfg('jobs.max_age', 86400);
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            if (time() - (int) filemtime($f) > $maxAge) {
                @unlink($f);
            }
        }
        foreach (glob(VD_ROOT . '/storage/files/*') ?: [] as $f) {
            if (is_file($f) && time() - (int) filemtime($f) > $maxAge) {
                @unlink($f);
            }
        }
    }

    private static function encode(Source $s): array
    {
        $rec = get_object_vars($s);
        $rec['headers'] = $s->headers;
        return $rec;
    }

    private static function decode(array $rec): Source
    {
        $s = Source::make((string) $rec['url'], (string) ($rec['kind'] ?? Source::MP4));
        foreach ($rec as $k => $v) {
            if ($k === 'url' || $k === 'kind' || !property_exists($s, $k)) {
                continue;
            }
            $s->$k = $v;
        }
        return $s;
    }
}
