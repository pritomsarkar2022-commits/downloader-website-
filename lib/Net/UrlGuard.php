<?php
declare(strict_types=1);

/**
 * SSRF wall. This server fetches attacker-supplied urls, so every url and every
 * redirect hop must be proven public before curl is allowed to touch it.
 */
final class UrlGuard
{
    /** @var array<string,bool> */
    private static array $dnsCache = [];

    public static function assertSafe(string $url): string
    {
        $p = parse_url($url);
        if ($p === false || empty($p['scheme']) || empty($p['host'])) {
            throw new UrlGuardException('That does not look like a link.');
        }
        $scheme = strtolower($p['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new UrlGuardException('Only web links work here, http or https.');
        }
        $host = strtolower(rtrim($p['host'], '.'));
        if (strlen($host) > 253 || str_contains($host, '@') || str_contains($url, '\\')) {
            throw new UrlGuardException('That link is not shaped like a web address.');
        }
        // Bracketed ipv6 literals and single-label names are never a public video
        // site, and decimal, octal or hex encoded hosts (0x7f000001, 2130706433)
        // are how a url reaches the machine itself while looking like a number.
        $labels = explode('.', $host);
        $last = (string) end($labels);
        if (count($labels) < 2 || str_contains($host, ':') || !preg_match('/^[a-z][a-z0-9-]*$/', $last)) {
            throw new UrlGuardException('That link points at a machine address, not a video site.');
        }
        if (str_ends_with($host, '.local') || str_ends_with($host, '.internal') || $host === 'localhost') {
            throw new UrlGuardException('That site is not reachable from here.');
        }

        if (self::isPrivateHost($host)) {
            throw new UrlGuardException('That link points inside a private network.');
        }

        $port = $p['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443, 8080, 8443], true)) {
            throw new UrlGuardException('That link uses an address this tool does not open.');
        }
        return $scheme . '://' . $host;
    }

    private static function isPrivateHost(string $host): bool
    {
        $ip = filter_var($host, FILTER_VALIDATE_IP)
            ? $host
            : (self::$dnsCache[$host] ??= (gethostbyname($host) ?: ''));

        if ($ip === '' || $ip === $host) { // unresolved name: let curl fail naturally
            return false;
        }
        if (preg_match('/^169\.254\./', $ip)) {
            return true; // cloud metadata
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
