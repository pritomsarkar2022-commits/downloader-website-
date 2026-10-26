<?php
declare(strict_types=1);

/**
 * Turn an HLS stream into one progressive MP4, a few segments per request.
 *
 * Shared hosting kills a script after 30 seconds, so a build never tries to finish
 * in one go: every poll grabs a small slice of the playlist, appends it to the file on
 * disk, saves the cursor and answers with a percentage. Anything the pure PHP muxer
 * cannot legally put in an MP4 is refused before the first byte is pulled.
 */
final class Mp4Builder
{
    public static function begin(array $job, Source $src, HttpClient $http): array
    {
        if ($src->kind !== Source::HLS) {
            throw new ExtractException('That file is already a single video.', 'Use the plain download button.', 'not_hls');
        }

        [$playlist, $base] = self::mediaPlaylist($src, $http);
        if ($playlist->live) {
            throw new ExtractException('This stream has no end yet.', 'Save it once the broadcast is over.', 'live');
        }
        if ($playlist->sampleAes) {
            throw new ExtractException('This stream is protected with SAMPLE-AES.', 'A stream that needs its own decrypting player cannot be joined into a file here.', 'drm');
        }
        if ($playlist->map !== null) {
            throw new ExtractException('This stream is already cut as MP4 segments.', 'Use Save stream file to keep it as it is.', 'fmp4');
        }
        if ($playlist->key !== null && strtoupper((string) $playlist->key['method']) !== 'AES-128') {
            throw new ExtractException('This stream uses ' . $playlist->key['method'] . '.', 'Only a stream with a plain key file can be joined here.', 'drm');
        }
        if ($playlist->segments === []) {
            throw new ExtractException('The stream list came back empty.', 'Run the same link again.', 'no_segments');
        }
        $max = (int) cfg('build.max_segments', 1500);
        if (count($playlist->segments) > $max) {
            throw new ExtractException('This stream is longer than ' . $max . ' parts.', 'Pick a lower quality if the page offers one.', 'too_long');
        }
        $budget = (int) cfg('build.scratch_budget', 3_000_000_000);
        if ($budget > 0 && self::scratchUsed() > $budget) {
            throw new ExtractException('The save folder is full.', 'Old files clear on their own within two hours. Try again shortly.', 'disk_full');
        }

        $segments = array_map(static fn(array $s) => [
            'u' => $s['url'],
            's' => (int) $s['seq'],
            'd' => round((float) $s['duration'], 3),
        ], $playlist->segments);

        $key = null;
        if ($playlist->key !== null) {
            $key = ['uri' => url_absolutize($base, (string) $playlist->key['uri']), 'iv' => $playlist->key['iv']];
        }
        $resolved = Source::make($base, Source::HLS);
        $resolved->headers = $src->headers;

        return (new BuildStore())->create($job, $resolved, $segments, $key, (float) $playlist->totalDuration);
    }

    /**
     * Do as much of the remaining work as this request can afford, then report.
     *
     * @param array<string,mixed> $rec
     * @return array<string,mixed>
     */
    public static function tick(array $rec, BuildStore $store): array
    {
        if (($rec['status'] ?? '') === 'done' || ($rec['status'] ?? '') === 'error') {
            return $rec;
        }
        $lock = $store->lock((string) $rec['id']);
        if ($lock === null) {
            return $rec; // another poll owns this build right now
        }
        $fresh = $store->find((string) $rec['id']);
        $rec = $fresh ?? $rec;
        $paths = $store->paths((string) $rec['id']);
        $mux = null;
        try {
            $http = new HttpClient((int) cfg('build.request_limit', 900));
            $key = $rec['key'] === null ? null : self::keyBytes($http, $rec);
            $mux = Mp4Mux::open($paths['mp4'], $paths['log'], (int) $rec['cursor'] > 0);
            $deadline = microtime(true) + (float) cfg('build.tick_seconds', 10);
            $slice = (int) cfg('build.tick_segments', 3);
            $segments = $rec['segments'];
            // One demuxer for the whole slice: a packet that a part cuts in half
            // continues in the next one, and dropping it would slide audio off the
            // picture for the rest of the file.
            $stream = new TsStream();
            $empties = 0;

            for ($n = 0; $n < $slice && (int) $rec['cursor'] < count($segments); $n++) {
                if ($n > 0 && microtime(true) > $deadline) {
                    break;
                }
                $seg = $segments[(int) $rec['cursor']];
                $raw = self::readSegment($http, $rec, (string) $seg['u'], $key);
                if ($raw === null) {
                    // One dropped part ruins the timing of everything after it. Edges that
                    // cached a ranged reply keep replaying it for that exact url, so the
                    // second read asks for the same file under a path they have not stored.
                    usleep(400000);
                    $raw = self::readSegment($http, $rec, self::otherPath((string) $seg['u']), $key);
                }
                if ($raw === null) {
                    throw new ExtractException(
                        'Part ' . ((int) $rec['cursor'] + 1) . ' of ' . count($segments) . ' did not arrive.',
                        'The host dropped the connection. Run the save again to pick it up from here.',
                        'segment_failed'
                    );
                }
                $stream->push($raw);
                $taken = $stream->take();
                $video = $taken['video'];
                $audio = $taken['audio'];
                if ($video === [] && $audio === []) {
                    self::firstComplaint($stream->demuxer(), $rec);
                    if (++$empties >= 2) {
                        throw new ExtractException(
                            'Part ' . ((int) $rec['cursor'] + 1) . ' held no readable frames.',
                            'The stream is cut in a way this site cannot join. Use Save stream file instead.',
                            'no_frames'
                        );
                    }
                } else {
                    $empties = 0;
                    self::stampTimes($rec, $video, $audio);
                    $mux->addSegment($video, $audio);
                    $rec['bytes'] = $mux->bytesWritten();
                }
                self::captureParams($rec, $stream->avc(), $stream->aac());
                $rec['cursor'] = (int) $rec['cursor'] + 1;
                $rec['video_samples'] = (int) $rec['video_samples'] + count($video);
                $rec['audio_samples'] = (int) $rec['audio_samples'] + count($audio);
                if ($rec['bytes'] > (int) cfg('build.max_bytes', 1_610_612_736)) {
                    throw new ExtractException('This file grew past 1.5 GB.', 'Pick a lower quality.', 'too_big');
                }
                $store->save($rec);
            }

            $tail = $stream->drain();
            if ($tail['video'] !== [] || $tail['audio'] !== []) {
                self::stampTimes($rec, $tail['video'], $tail['audio']);
                $mux->addSegment($tail['video'], $tail['audio']);
                $rec['video_samples'] = (int) $rec['video_samples'] + count($tail['video']);
                $rec['audio_samples'] = (int) $rec['audio_samples'] + count($tail['audio']);
                $rec['bytes'] = $mux->bytesWritten();
            }

            if ((int) $rec['cursor'] >= count($segments)) {
                if (!is_array($rec['params'])) {
                    throw new ExtractException('The stream never announced its video format.', 'Use Save stream file to keep it as it is.', 'no_sps');
                }
                $mux->writeMoov($paths['log'], self::binary($rec['params']));
                $rec['status'] = 'done';
                $rec['bytes'] = $mux->bytesWritten();
            }
            $store->save($rec);
        } catch (Throwable $e) {
            $rec['status'] = 'error';
            $rec['error'] = $e->getMessage();
            $rec['hint'] = $e instanceof ExtractException ? $e->hint : '';
            vd_log('build failed', ['id' => $rec['id'] ?? '', 'msg' => $e->getMessage(), 'cursor' => $rec['cursor'] ?? 0]);
            $store->save($rec);
        } finally {
            if ($mux !== null) {
                $mux->close();
            }
            $store->release($lock);
        }
        return $rec;
    }

    /**
     * @param array<string,mixed> $rec
     * @return array<string,mixed>
     */
    public static function progress(array $rec, BuildStore $store): array
    {
        $total = max(1, count($rec['segments']));
        $done = ($rec['status'] ?? '') === 'done';
        return [
            'ok' => ($rec['status'] ?? '') !== 'error',
            'build' => $rec['id'],
            'status' => $rec['status'],
            'percent' => $done ? 100 : (int) floor(100 * (int) $rec['cursor'] / $total),
            'segments' => $total,
            'parts' => (int) $rec['cursor'],
            'duration' => $rec['duration'],
            'size' => (int) $rec['bytes'],
            'sizeHuman' => human_bytes((int) $rec['bytes']),
            'videoSamples' => (int) $rec['video_samples'],
            'filename' => $rec['filename'],
            'done' => $done,
            'fileUrl' => $done ? $store->fileUrl($rec) : null,
            'error' => $rec['status'] === 'error' ? $rec['error'] : null,
            'hint' => $rec['status'] === 'error' ? $rec['hint'] : null,
            'note' => $rec['note'],
        ];
    }

    /** The same file under a second cache key: most edges keep the object, only the key changes. */
    private static function otherPath(string $url): string
    {
        $q = strpos($url, '?');
        return $q === false
            ? rtrim($url, '/') . '/'
            : substr($url, 0, $q) . '/' . substr($url, $q);
    }

    /**
     * Pull one transport stream part and hand back its (decrypted) bytes.
     *
     * @param array<string,mixed> $rec
     */
    private static function readSegment(HttpClient $http, array &$rec, string $url, ?string $key): ?string
    {
        $tmp = tempnam(VD_ROOT . '/storage/builds', 'seg');
        if ($tmp === false) {
            return null;
        }
        try {
            try {
                $http->get($url, [
                    'headers' => $rec['headers'],
                    'timeout' => (int) cfg('build.segment_timeout', 25),
                    'to_file' => $tmp,
                    'accept' => 'video/mp2t,*/*;q=0.8',
                ]);
            } catch (HttpException $e) {
                vd_log('segment fetch failed', ['url' => $url, 'msg' => $e->getMessage()]);
                return null;
            }
            $size = (int) @filesize($tmp);
            if ($size < 188 || $size > (int) cfg('build.segment_max_bytes', 67_108_864)) {
                vd_log('segment arrived short', ['url' => $url, 'bytes' => $size]);
                return null;
            }
            $raw = (string) file_get_contents($tmp);
            if ($key !== null) {
                $raw = self::decrypt($raw, $key, self::iv((string) ($rec['key']['iv'] ?? ''), (int) $rec['segments'][(int) $rec['cursor']]['s']));
            }
            if (!TsDemuxer::looksLikeTs($raw)) {
                vd_log('segment is not a transport stream', ['url' => $url, 'bytes' => strlen($raw)]);
                return null;
            }
            return $raw;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Keep the clock moving forward when a host restarts its timestamps between
     * parts, because the muxer reads one continuous timeline out of the sample log.
     *
     * @param array<int,array{data:string,pts:int,dts:int,key:bool}> $video
     * @param array<int,array{data:string,pts:int}> $audio
     * @param array<string,mixed> $rec
     */
    private static function stampTimes(array &$rec, array &$video, array &$audio): void
    {
        if ($video === []) {
            return;
        }
        $offset = (int) ($rec['ts_offset'] ?? 0);
        $first = (int) $video[0]['dts'];
        $last = $rec['last_dts'] === null ? null : (int) $rec['last_dts'];
        if ($last !== null) {
            $gap = $first + $offset - $last;
            if ($gap <= 0 || $gap > 90000 * 120) {
                $offset = $last + (int) ($rec['last_delta'] ?? 3600) - $first;
            }
        }
        $previous = null;
        foreach ($video as $i => $u) {
            $video[$i]['dts'] = (int) $u['dts'] + $offset;
            $video[$i]['pts'] = (int) $u['pts'] + $offset;
            if ($previous !== null && $video[$i]['dts'] > $previous) {
                $rec['last_delta'] = $video[$i]['dts'] - $previous;
            }
            $previous = $video[$i]['dts'];
        }
        foreach ($audio as $i => $u) {
            $audio[$i]['pts'] = (int) $u['pts'] + $offset;
        }
        $rec['ts_offset'] = $offset;
        $rec['last_dts'] = $previous;
    }

    /** @param array<string,mixed> $rec */
    private static function captureParams(array &$rec, Avc $avc, Aac $aac): void
    {
        $avcC = $avc->avcC();
        if ($avcC === null || $avc->width < 2 || $avc->height < 2) {
            return;
        }
        $hex = bin2hex($avcC);
        if (is_array($rec['params'])) {
            if ($rec['params']['avcC'] !== $hex && $rec['note'] === null) {
                $rec['note'] = 'The stream switched quality partway, so the MP4 holds the part before the switch.';
            }
            return;
        }
        $rec['params'] = [
            'width' => $avc->width,
            'height' => $avc->height,
            'avcC' => $hex,
            'asc' => is_string($aac->asc) ? bin2hex($aac->asc) : '',
            'sampleRate' => (int) ($aac->sampleRate ?? 0),
            'channels' => (int) ($aac->channels ?? 0),
        ];
    }

    /** Bytes waiting in the scratch folder for someone to download them. */
    private static function scratchUsed(): int
    {
        $used = 0;
        foreach (glob(VD_ROOT . '/storage/files/*.mp4') ?: [] as $f) {
            $used += (int) filesize($f);
        }
        return $used;
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    private static function binary(array $p): array
    {
        $p['avcC'] = (string) hex2bin((string) $p['avcC']);
        $p['asc'] = (string) hex2bin((string) $p['asc']);
        return $p;
    }

    /** @return array{0:Playlist,1:string} the media playlist plus the url it was read from */
    private static function mediaPlaylist(Source $src, HttpClient $http): array
    {
        $text = self::fetchText($http, $src->url, $src->headers);
        $playlist = Playlist::parse($text, $src->url);
        if (!$playlist->isMaster) {
            return [$playlist, $src->url];
        }
        $variant = $playlist->selectVariant($src->height);
        if ($variant === null) {
            throw new ExtractException('The stream list has no video quality in it.', 'Try Save stream file instead.', 'no_variant');
        }
        $text = self::fetchText($http, $variant['url'], $src->headers);
        return [Playlist::parse($text, (string) $variant['url']), (string) $variant['url']];
    }

    /** @param array<string,mixed> $rec */
    private static function keyBytes(HttpClient $http, array $rec): string
    {
        $uri = (string) ($rec['key']['uri'] ?? '');
        if ($uri === '') {
            throw new ExtractException('This stream has no key url.', 'Only AES-128 with a plain key file can be opened.', 'drm');
        }
        $body = self::fetchText($http, $uri, $rec['headers']);
        $key = $body;
        if (strlen($key) !== 16) {
            $trimmed = trim($body, "\r\n \t");
            $key = strlen($trimmed) === 16 ? $trimmed : substr($body, 0, 16);
        }
        if (strlen($key) !== 16) {
            throw new ExtractException('The key for this stream is not a plain 16 byte one.', 'Use Save stream file instead.', 'drm');
        }
        return $key;
    }

    /** HLS default: the IV is the media sequence number as a 128 bit big-endian value. */
    private static function iv(?string $declared, int $sequence): string
    {
        if (is_string($declared) && $declared !== '') {
            $hex = strtolower(str_replace(['0x', 'X', ' '], '', $declared));
            if (strlen($hex) === 32 && preg_match('/^[0-9a-f]+$/', $hex)) {
                return (string) hex2bin($hex);
            }
        }
        return str_pad(pack('N', $sequence), 16, "\x00", STR_PAD_LEFT);
    }

    private static function decrypt(string $raw, string $key, string $iv): string
    {
        $aligned = strlen($raw) % 16 === 0 ? $raw : substr($raw, 0, strlen($raw) - (strlen($raw) % 16));
        $out = openssl_decrypt($aligned, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($out === false) {
            $out = openssl_decrypt(substr($raw, 0, 16 * intdiv(strlen($raw), 16)), 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);
        }
        return $out === false ? '' : $out;
    }

    /** @param array<string,mixed> $headers */
    private static function fetchText(HttpClient $http, string $url, array $headers): string
    {
        $res = $http->get($url, [
            'headers' => $headers,
            'max_bytes' => (int) cfg('build.playlist_max_bytes', 4_194_304),
            'accept' => 'application/vnd.apple.mpegurl,application/x-mpegURL,*/*;q=0.8',
        ]);
        if (!$res->ok()) {
            throw new ExtractException('The stream list came back as HTTP ' . $res->status . '.', 'The host link has probably expired. Run the same link again.', 'playlist_http');
        }
        return $res->body;
    }

    /**
     * A segment with no frames is usually a codec this muxer will not touch, so say
     * which one instead of writing an empty file.
     *
     * @param array<string,mixed> $rec
     */
    private static function firstComplaint(TsDemuxer $demux, array $rec): void
    {
        foreach ($demux->skipped as $why) {
            if (str_contains($why, '0x24')) {
                throw new ExtractException('This stream is HEVC (H.265).', 'HEVC video needs a full converter, and a shared server cannot run one.', 'hevc');
            }
        }
        vd_log('empty segment', ['url' => $rec['segments'][(int) $rec['cursor']]['u'] ?? '', 'pids' => $demux->pidKind]);
    }
}
