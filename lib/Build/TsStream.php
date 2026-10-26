<?php
declare(strict_types=1);

/**
 * Elementary stream reader that survives segment and request boundaries.
 *
 * Holds one TsDemuxer, one Avc and one Aac for the whole build slice, so a PES
 * packet or a picture that a part cuts in half is finished by the next part instead
 * of being thrown away. Throwing it away shortens the track and slides the audio
 * out of step with the picture for every second that follows.
 */
final class TsStream
{
    private TsDemuxer $demux;
    private Avc $avc;
    private Aac $aac;

    /** @var array<int,array{data:string,pts:int,dts:int,key:bool}> */
    private array $video = [];
    /** @var array<int,array{data:string,pts:int}> */
    private array $audio = [];
    private ?float $audioClock = null;

    public function __construct()
    {
        $this->demux = new TsDemuxer();
        $this->avc = new Avc();
        $this->aac = new Aac();
    }

    public function demuxer(): TsDemuxer
    {
        return $this->demux;
    }

    public function avc(): Avc
    {
        return $this->avc;
    }

    public function aac(): Aac
    {
        return $this->aac;
    }

    /** Read one MPEG-TS buffer and queue the frames it completed. */
    public function push(string $ts): void
    {
        $this->demux->demux($ts, $this->sink());
    }

    /**
     * Close off everything still open at the end of a slice. The next request starts
     * a fresh reader, so this is the last chance to keep the loose ends.
     *
     * @return array<int,array{data:string,pts:int,dts:int,key:bool}>
     */
    public function drain(): array
    {
        $this->demux->flush($this->sink());
        foreach ($this->avc->finish() as $u) {
            $this->video[] = [
                'data' => Avc::toAvcc($u['nalus']),
                'pts' => (int) $u['pts'],
                'dts' => (int) $u['dts'],
                'key' => (bool) $u['key'],
            ];
        }
        return $this->take();
    }

    /**
     * @return array{video:array<int,array{data:string,pts:int,dts:int,key:bool}>,audio:array<int,array{data:string,pts:int}>}
     */
    public function take(): array
    {
        $out = ['video' => $this->video, 'audio' => $this->audio];
        $this->video = [];
        $this->audio = [];
        return $out;
    }

    /** @return callable(int,string,?int,?int,string):void */
    private function sink(): callable
    {
        return function (int $pid, string $kind, ?int $pts, ?int $dts, string $body): void {
            if ($kind === 'video' && $pid === $this->demux->videoPid) {
                foreach ($this->avc->push($body, (int) $pts, $dts) as $u) {
                    $this->video[] = [
                        'data' => Avc::toAvcc($u['nalus']),
                        'pts' => (int) $u['pts'],
                        'dts' => (int) $u['dts'],
                        'key' => (bool) $u['key'],
                    ];
                }
                return;
            }
            if ($pid !== $this->demux->audioPid) {
                return;
            }
            $rate = max(1, (int) ($this->aac->sampleRate ?? 44100));
            $step = 1024 * 90000 / $rate;
            if ($pts !== null && ($this->audioClock === null || abs((float) $pts - $this->audioClock) > $step * 2)) {
                $this->audioClock = (float) $pts;
            }
            foreach ($this->aac->push($body) as $frame) {
                $this->audio[] = ['data' => $frame['data'], 'pts' => (int) round($this->audioClock ?? 0)];
                $this->audioClock = ($this->audioClock ?? 0.0) + $step;
            }
        };
    }
}
