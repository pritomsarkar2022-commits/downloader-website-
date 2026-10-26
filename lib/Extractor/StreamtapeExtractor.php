<?php
declare(strict_types=1);

/**
 * streamtape.com
 *
 * The page assembles its download link in js and scatters decoys:
 *   document.getElementById('captchalink').innerHTML = '//streamtape.com/get_video?id='
 *       + ('defg74Awo...&token=lgLw_dA8kbiI').substring(4);
 *   document.getElementById('norobotlink').innerHTML = "/streamtape.com/get_video?id=7"
 *       + ''+ ('xcdd4Awo...').substring(1).substring(2);   // corrupted id
 * plus hidden divs holding dead tokens. Which line is live moves around, so the
 * only safe reading is to evaluate every builder expression and then test the
 * results against the server, keeping whichever one answers with video.
 */
final class StreamtapeExtractor extends BaseExtractor
{
    public function hosts(): array
    {
        return ['streamtape.com', 'streamtape.net', 'streamtape.to', 'streamta.pe', 'shrtst.com', 'streamdav.net', 'tapecontent.net', 'advtpe.com', 'advtpe.net'];
    }

    public function priority(): int
    {
        return 20;
    }

    public function extract(string $pageUrl, HttpClient $http, array $visited = []): MediaBundle
    {
        $b = $this->newBundle($pageUrl, 'streamtape');
        $who = ucfirst(ExtractorRegistry::registrable((string) (parse_url($pageUrl, PHP_URL_HOST) ?: '')));
        $res = $http->get($pageUrl, ['headers' => ['Referer' => $pageUrl]]);
        if (!$res->ok()) {
            throw new ExtractException($who . ' answered HTTP ' . $res->status . '.', 'The file may be gone, or the link is wrong.', 'http_' . $res->status);
        }
        $this->fillMeta($b, $res);

        $candidates = self::linkCandidates($res->body, $res->finalUrl);
        if (!$candidates) {
            throw new ExtractException($who . ' gave no download link.', 'Their page changed, or this video is locked to their app.', 'no_link');
        }

        $chosen = null;
        foreach ($candidates as $url) {
            if ($http->remaining() <= 0) {
                break;
            }
            $s = Source::make($url, Source::MP4);
            $s->label = 'Original quality';
            $s->headers = ['Referer' => $res->finalUrl];
            $s->tokenExpiryFromQuery();
            if ($this->verify($s, $http)) {
                $chosen = $s;
                break;
            }
            vd_log('streamtape decoy or dead token', ['url' => preg_replace('/token=\w+/', 'token=***', $url)]);
        }

        if ($chosen === null) {
            throw new ExtractException($who . ' refused every link the page offered.', 'Their token usually clears on a second try. Paste the same address again.', 'token_expired');
        }
        $chosen->note = $who . ' links stay open roughly 19 hours.';
        $b->sources = [$chosen];
        $b->confidence = 0.92;
        return $b;
    }

    /**
     * @return string[] candidate get_video urls, js-built ones first
     */
    public static function linkCandidates(string $html, string $baseUrl): array
    {
        $out = [];

        // 1. innerHTML / href assignments built from string literals and .substring()
        preg_match_all('~\.innerHTML\s*=\s*([^;\n]{10,600})~', $html, $assigns);
        foreach ($assigns[1] ?? [] as $expr) {
            $built = self::evalConcat($expr);
            if ($built !== null && str_contains($built, 'get_video') && str_contains($built, 'token=')) {
                $out[] = url_absolutize($baseUrl, $built);
            }
        }

        // 2. a complete literal in the markup (older mirrors)
        if (preg_match_all('~(?:https?:)?//[^\s"\'<>]*?/get_video\?[^\s"\'<>]*token=[A-Za-z0-9_\-]+~i', $html, $lit)) {
            foreach ($lit[0] as $u) {
                $out[] = url_absolutize($baseUrl, $u);
            }
        }

        // 3. reconstructed from the loose parts, in case the shape changes again
        if (preg_match_all('~get_video\?(id=[A-Za-z0-9]+&expires=\d+&ip=[A-Za-z0-9_]+&token=[A-Za-z0-9_\-]+)~', $html, $parts)) {
            foreach ($parts[1] as $q) {
                $out[] = 'https://' . (parse_url($baseUrl, PHP_URL_HOST) ?: 'streamtape.com') . '/get_video?' . $q;
            }
        }

        $out = array_values(array_unique($out));
        // A url whose id matches the page id is far more likely to be the live one.
        $pageId = null;
        if (preg_match('~/(?:v|e|embed|f)/([A-Za-z0-9]+)~', $baseUrl, $pm)) {
            $pageId = $pm[1];
        }
        if ($pageId !== null) {
            usort($out, static function (string $a, string $b) use ($pageId): int {
                $qa = str_contains($a, 'id=' . $pageId . '&') ? 0 : 1;
                $qb = str_contains($b, 'id=' . $pageId . '&') ? 0 : 1;
                return $qa <=> $qb;
            });
        }
        return array_slice($out, 0, 5);
    }

    /**
     * Evaluate a safe js string expression made only of literals, + and .substring(n).
     * Returns null when the expression uses anything else, so a changed shape fails
     * loudly instead of producing a half-built url.
     */
    public static function evalConcat(string $expr): ?string
    {
        $expr = trim($expr);
        if ($expr === '' || strlen($expr) > 900) {
            return null;
        }
        $terms = self::splitTopLevel($expr, '+');
        if ($terms === null || $terms === []) {
            return null;
        }
        $out = '';
        $sawLiteral = false;
        foreach ($terms as $t) {
            $val = self::evalTerm($t);
            if ($val === null) {
                return null;
            }
            if (trim($t) !== '') {
                $sawLiteral = true;
            }
            $out .= $val;
        }
        return $sawLiteral ? $out : null;
    }

    /** One term: a quoted literal, a parenthesised sub-expression, or either with .substring() applied. */
    private static function evalTerm(string $t): ?string
    {
        $t = trim($t);
        if ($t === '') {
            return '';
        }
        if ($t[0] === '(') {
            $close = self::matchingParen($t);
            if ($close === null) {
                return null;
            }
            $value = self::evalConcat(substr($t, 1, $close - 1));
            if ($value === null) {
                return null;
            }
            $rest = trim(substr($t, $close + 1));
            while ($rest !== '') {
                if (!preg_match('~^\.substring\(\s*(\d+)\s*\)(.*)$~s', $rest, $m)) {
                    return null;
                }
                $value = substr($value, (int) $m[1]);
                $rest = trim($m[2]);
            }
            return $value;
        }
        if (preg_match('~^([\'"])((?:(?!\1).)*)\1$~s', $t, $m)) {
            return $m[2];
        }
        return null;
    }

    /** Index of the ) that closes the ( at position 0, quote aware. */
    private static function matchingParen(string $s): ?int
    {
        $depth = 0;
        $quote = null;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;
                    continue;
                }
                if ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
                if ($depth < 0) {
                    return null;
                }
            }
        }
        return null;
    }

    /** @return string[]|null split on $delim, ignoring it inside quotes and brackets */
    private static function splitTopLevel(string $s, string $delim): ?array
    {
        $parts = [];
        $buf = '';
        $depth = 0;
        $quote = null;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($quote !== null) {
                $buf .= $c;
                if ($c === '\\') {
                    $buf .= $s[++$i] ?? '';
                    continue;
                }
                if ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $buf .= $c;
                continue;
            }
            if ($c === '(' || $c === '[') {
                $depth++;
            } elseif ($c === ')' || $c === ']') {
                $depth--;
                if ($depth < 0) {
                    return null;
                }
            }
            if ($c === $delim && $depth === 0) {
                $parts[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        $parts[] = $buf;
        return $depth === 0 && $quote === null ? $parts : null;
    }
}
