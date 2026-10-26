<?php
declare(strict_types=1);

/**
 * Build state for the "save as one MP4" pipeline, kept in small json files.
 *
 * A build is a job's signed media playlist plus a cursor, so a 30 second shared
 * hosting limit only costs a resume: each poll processes a few segments, saves the
 * cursor and hands the browser a percentage. The build carries its own secret, which
 * is what signs the download url, so a finished file cannot be pulled by guessing ids.
 */
final class BuildStore
{
    private string $buildDir;
    private string $fileDir;

    public function __construct()
    {
        $this->buildDir = VD_ROOT . '/storage/builds';
        $this->fileDir = VD_ROOT . '/storage/files';
        foreach ([$this->buildDir, $this->fileDir] as $d) {
            if (!is_dir($d)) {
                @mkdir($d, 0775, true);
            }
        }
    }

    public function create(array $job, Source $src, array $segments, ?array $key, float $duration): array
    {
        $this->maybeGc();
        $secret = bin2hex(random_bytes(16));
        $id = substr(hash_hmac('sha256', $src->url . $secret, 'vd-build'), 0, 20);
        $ttl = (int) cfg('build.ttl', 3600);
        $record = [
            'id' => $id,
            'secret' => $secret,
            'job' => (string) ($job['id'] ?? ''),
            'provider' => (string) ($job['provider'] ?? ''),
            'title' => (string) ($job['title'] ?? ''),
            'headers' => $src->headers,
            'playlist' => $src->url,
            'key' => $key,
            'segments' => $segments,
            'duration' => round($duration, 2),
            'cursor' => 0,
            'bytes' => 0,
            'video_samples' => 0,
            'audio_samples' => 0,
            'params' => null,
            'ts_offset' => 0,
            'last_dts' => null,
            'last_delta' => null,
            'status' => 'running',
            'error' => null,
            'hint' => null,
            'note' => null,
            'filename' => self::fileName((string) ($job['title'] ?? ''), (string) ($job['provider'] ?? 'video')),
            'created' => time(),
            'updated' => time(),
            'expires' => time() + $ttl,
        ];
        $this->save($record);
        return $record;
    }

    public function find(string $id): ?array
    {
        if (!preg_match('/^[a-f0-9]{20}$/', $id)) {
            return null;
        }
        $rec = json_decode((string) @file_get_contents($this->statePath($id)), true);
        if (!is_array($rec) || ($rec['expires'] ?? 0) < time()) {
            $this->discard($id);
            return null;
        }
        return $rec;
    }

    public function save(array $record): void
    {
        $record['updated'] = time();
        $json = json_encode($record, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new ExtractException('Could not save the build state.', 'Storage is full or not writable.', 'io');
        }
        $tmp = $this->statePath((string) $record['id']) . '.tmp';
        file_put_contents($tmp, $json, LOCK_EX);
        rename($tmp, $this->statePath((string) $record['id']));
    }

    /**
     * @return array{mp4:string,log:string,state:string}
     */
    public function paths(string $id): array
    {
        return [
            'mp4' => $this->fileDir . '/' . $id . '.mp4',
            'log' => $this->buildDir . '/' . $id . '.log',
            'state' => $this->statePath($id),
        ];
    }

    /** @return resource|null a held lock, or null when another tick owns the build */
    public function lock(string $id)
    {
        $fh = @fopen($this->buildDir . '/' . $id . '.lock', 'c+');
        if ($fh === false) {
            return null;
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return null;
        }
        return $fh;
    }

    public function release($lock): void
    {
        if (is_resource($lock)) {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function sign(array $record): string
    {
        return substr(hash_hmac('sha256', 'file:' . $record['id'], (string) $record['secret']), 0, 32);
    }

    public function verify(array $record, string $sig): bool
    {
        return hash_equals($this->sign($record), $sig);
    }

    public function fileUrl(array $record): string
    {
        return Relay::appUrl('r=file&build=' . $record['id'] . '&s=' . $this->sign($record) . '&name=' . urlencode((string) $record['filename']));
    }

    public function discard(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{20}$/', $id)) {
            return;
        }
        $p = $this->paths($id);
        foreach ([$p['mp4'], $p['log'], $p['state'], $this->buildDir . '/' . $id . '.lock'] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    private function statePath(string $id): string
    {
        return $this->buildDir . '/' . $id . '.json';
    }

    private function maybeGc(): void
    {
        if (mt_rand(1, 100) > (int) (cfg('jobs.gc_probability', 0.05) * 100)) {
            return;
        }
        $age = (int) cfg('build.max_age', 7200);
        foreach (glob($this->buildDir . '/*') ?: [] as $f) {
            if (is_file($f) && time() - (int) filemtime($f) > $age) {
                @unlink($f);
            }
        }
        foreach (glob($this->fileDir . '/*.mp4') ?: [] as $f) {
            if (time() - (int) filemtime($f) > $age) {
                @unlink($f);
            }
        }
    }

    /** File names travel to the browser in a header, so keep them to plain characters. */
    public static function fileName(string $title, string $provider): string
    {
        $base = trim((string) preg_replace('/[^a-zA-Z0-9 ._-]+/', '', $title));
        $base = (string) preg_replace('/\s+/', '-', $base);
        $base = trim($base, '-.');
        if ($base === '' || strlen($base) > 80) {
            $base = $provider !== '' ? $provider : 'video';
        }
        return substr($base, 0, 80) . '.mp4';
    }
}
