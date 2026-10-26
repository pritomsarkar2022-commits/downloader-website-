<?php
declare(strict_types=1);

/** Pick the extractor for a url, or the generic one. */
final class ExtractorRegistry
{
    /** @var ExtractorInterface[] */
    private array $extractors = [];

    public function __construct()
    {
        // Named refusals go first so a gated host explains itself instead of
        // falling through to the general reader and looking broken.
        $this->register(new BlockedExtractor());
        $this->register(new YouTubeExtractor());
        $this->register(new StreamtapeExtractor());
        $this->register(new StreamfileExtractor());
        $this->register(new LuluvidoExtractor());
        $this->register(new FirestreamExtractor());
        $this->register(new PlaymateExtractor());
        $this->register(new VidaraExtractor());
        $this->register(new VixeoExtractor());
        $this->register(new CloudfamExtractor());
        $this->register(new MxdropExtractor());
        $this->register(new XfsExtractor());
        // Hosts without a reader still work when they expose a plain .mp4/.m3u8,
        // including ones that hide it inside packed player js.
        $this->register(new GenericExtractor());
    }

    public function register(ExtractorInterface $e): void
    {
        $this->extractors[] = $e;
    }

    public function forUrl(string $url): ExtractorInterface
    {
        $host = self::registrable((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        $best = null;
        $bestScore = -1;
        foreach ($this->extractors as $e) {
            foreach ($e->hosts() as $pattern) {
                if ($host === $pattern || str_ends_with($host, '.' . $pattern)) {
                    $score = strlen($pattern) * 100 + $e->priority();
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = $e;
                    }
                }
            }
        }
        if ($best !== null) {
            return $best;
        }
        $g = new GenericExtractor();
        $g->setProvider($host !== '' ? $host : 'generic');
        return $g;
    }

    /** Names shown on the homepage so users know what to expect. */
    public function knownHosts(): array
    {
        $out = [];
        foreach ($this->extractors as $e) {
            if (in_array($e->name(), ['blocked', 'youtube'], true)) {
                continue;
            }
            foreach ($e->hosts() as $h) {
                $out[$h] = $e->name();
            }
        }
        ksort($out);
        return $out;
    }

    public static function registrable(string $host): string
    {
        $host = strtolower(trim($host, '.'));
        $parts = explode('.', $host);
        if (count($parts) < 2) {
            return $host;
        }
        // co.uk / com.bd / gov.in style second-level public suffixes.
        $tails = ['co','com','net','org','gov','edu','ac','mil','ne','or','go','lg'];
        $last = $parts[count($parts) - 1];
        $second = $parts[count($parts) - 2];
        if (strlen($last) === 2 && in_array($second, $tails, true)) {
            return implode('.', array_slice($parts, -3));
        }
        return $second . '.' . $last;
    }
}
