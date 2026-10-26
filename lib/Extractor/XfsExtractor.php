<?php
declare(strict_types=1);

/**
 * The XFS file host family (dupload.net and its mirrors)
 *
 * One form does everything: the page posts its own hidden fields back and the
 * answer is a 302 straight to the file, whose name is in the path. The link is
 * minted per request and the token folder changes every time, so it must never
 * be cached.
 *
 * Mirrors of the same script differ only in what they put in front of that form.
 * When a captcha or a countdown is on the page we say so instead of pretending
 * the link is broken, because defeating either one is not something this tool does.
 */
final class XfsExtractor extends BaseExtractor
{
    public function hosts(): array
    {
        return ['dupload.net', 'dupload.pro', 'dupload.info'];
    }

    public function priority(): int
    {
        return 20;
    }

    public function extract(string $pageUrl, HttpClient $http, array $visited = []): MediaBundle
    {
        $b = $this->newBundle($pageUrl, 'xfs');

        $res = $http->get($pageUrl, ['headers' => ['Referer' => $pageUrl]]);
        $this->fillMeta($b, $res);
        if (!$res->ok()) {
            throw new ExtractException('That file host answered HTTP ' . $res->status . '.', 'The link is probably dead.', 'http_' . $res->status);
        }

        $fields = self::formFields($res->body);
        if ($fields === []) {
            throw new ExtractException('That page has no download form on it.', 'Use the address of the file page.', 'no_form');
        }
        $gate = self::gateOn($res->body);
        if ($gate !== '') {
            throw new ExtractException('This host puts a ' . $gate . ' in front of the file.', 'That is their rule, and this tool will not get around it. Download from their own page instead.', 'gated');
        }

        $fields['method_free'] = $fields['method_free'] ?? 'Normal Download';
        $fields['adblock_detected'] = '0';

        $post = $http->post($res->finalUrl, '', [
            'form' => $fields,
            'headers' => ['Referer' => $res->finalUrl],
            'max_redirects' => 0,
            'timeout' => 20,
        ]);
        $location = (string) ($post->header('location') ?? '');
        if ($post->status !== 302 || $location === '') {
            throw new ExtractException('The host did not hand over a file.', 'Its free queue is probably full. Try again in a minute.', 'no_redirect');
        }

        $media = url_absolutize($res->finalUrl, $location);
        $s = Source::make($media, str_contains($media, '.m3u8') ? Source::HLS : Source::MP4);
        $name = rawurldecode((string) (parse_url($media, PHP_URL_PATH) ?: ''));
        $base = basename($name);
        if ($base !== '' && str_contains($base, '.')) {
            $s->label = 'Original file';
            $b->title = $b->title ?? preg_replace('~\.[a-z0-9]{2,4}$~i', '', $base);
        }
        $s->headers = ['Referer' => $res->finalUrl];
        $b->addSource($s);

        $b->sources = $this->verifyAll($b->sources, $http, $b);
        if (!$b->sources) {
            throw new ExtractException('The file address that host gave was refused.', 'Its links expire almost immediately. Paste the page again.', 'token_expired');
        }
        $s->note = 'This host mints a new file address every time the page opens, so paste the link again if this one stops working.';
        $b->confidence = 0.88;
        return $b;
    }

    /**
     * Hidden inputs of the download form, kept as the page sent them.
     *
     * @return array<string,string>
     */
    public static function formFields(string $html): array
    {
        if (!preg_match('~<form[^>]+name=["\']F1["\'](.{0,4000}?)</form>~is', $html, $f)) {
            if (!preg_match('~<form(.{0,4000}?)</form>~is', $html, $f)) {
                return [];
            }
        }
        $out = [];
        preg_match_all('~<input[^>]+type=["\']hidden["\'][^>]*>~i', $f[1], $inputs);
        foreach ($inputs[0] as $tag) {
            if (!preg_match('~name=["\']([A-Za-z0-9_]+)["\']~', $tag, $n)) {
                continue;
            }
            $value = preg_match('~value=["\']([^"\']*)["\']~', $tag, $v) ? $v[1] : '';
            $out[$n[1]] = html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        return isset($out['op']) && isset($out['id']) ? $out : [];
    }

    /** What the host put between the visitor and the file, or '' when there is nothing. */
    public static function gateOn(string $html): string
    {
        if (stripos($html, 'h-captcha') !== false || stripos($html, 'hcaptcha.com') !== false) {
            return 'captcha check';
        }
        if (stripos($html, 'g-recaptcha') !== false || stripos($html, 'recaptcha/api.js') !== false) {
            return 'captcha check';
        }
        if (preg_match('~id=["\']countdown["\']~i', $html) && preg_match('~Wait\s*<|<span[^>]*id=["\']seconds["\']~i', $html)) {
            return 'wait timer';
        }
        return '';
    }
}
