<?php
declare(strict_types=1);

/** Everything the analysis of one page URL produced. */
final class MediaBundle
{
    public string $provider = 'generic';
    public ?string $title = null;
    public ?string $thumbnail = null;
    public string $pageUrl = '';
    public string $finalUrl = '';

    /** @var Source[] */
    public array $sources = [];

    /** @var string[] things the user should be told (expired file, DRM, slow host ...) */
    public array $notices = [];

    /** 0..1, how much we trust the result. Below 0.4 the UI asks for a manual check. */
    public float $confidence = 0.5;

    /** Populated when the site is reachable but deliberately unsolvable. */
    public ?string $blockedReason = null;

    public function addSource(Source $s): void
    {
        foreach ($this->sources as $existing) {
            if ($existing->url === $s->url) {
                return;
            }
        }
        $this->sources[] = $s;
    }

    public function best(): ?Source
    {
        $video = array_filter($this->sources, static fn(Source $s) => $s->isVideo());
        if (!$video) {
            return null;
        }
        usort($video, static function (Source $a, Source $b): int {
            $rank = static fn(Source $s): int => match (true) {
                $s->kind === Source::MP4 => 3,
                $s->kind === Source::HLS => 2,
                default => 1,
            };
            return ($rank($b) <=> $rank($a)) ?: ($b->height <=> $a->height);
        });
        return $video[0];
    }

    public function notice(string $message): void
    {
        if (!in_array($message, $this->notices, true)) {
            $this->notices[] = $message;
        }
    }
}
