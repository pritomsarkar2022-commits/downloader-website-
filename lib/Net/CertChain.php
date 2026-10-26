<?php
declare(strict_types=1);

/**
 * Completes a certificate chain a file host forgot to send.
 *
 * Small hosts often run Apache with nothing but the leaf certificate on the
 * socket, so a client that does not chase the AIA pointer inside that leaf
 * cannot build a path to a root and curl stops with errno 60. Browsers hide the
 * defect by fetching the issuer over plain http; this does the same fetch, then
 * hands curl a CA file made of the machine's own store plus those issuers.
 *
 * Verification is never switched off. A fetched issuer is only used after it has
 * been checked against the system store, and a self-signed root is never added,
 * so the anchor at the top of the chain still has to be one this machine already
 * trusts. An attacker who could fake the AIA response would still have to
 * produce a chain that reaches a real root and names the host we asked for.
 */
final class CertChain
{
    private const MAX_HOPS = 3;
    private const MAX_ISSUER_BYTES = 65_536;
    private const CACHE_HOURS = 6;

    /** @var array<string,string|null> */
    private static array $done = [];

    /** Path to a CA file that can verify $host, or null when there is nothing to fix. */
    public static function bundleFor(string $host): ?string
    {
        if (array_key_exists($host, self::$done)) {
            return self::$done[$host];
        }
        self::$done[$host] = null;

        $store = self::systemStore();
        if ($store === null) {
            return null;
        }

        $path = self::cachePath($host);
        if (is_file($path) && (int) (@filemtime($path) ?: 0) > time() - self::CACHE_HOURS * 3600) {
            return self::$done[$host] = $path;
        }

        $chain = self::peerChain($host);
        if ($chain === []) {
            return null;
        }

        $extras = self::walk($chain, $store);
        if ($extras === []) {
            return null;
        }

        $top = @openssl_x509_read((string) end($extras));
        if ($top === false || !@openssl_x509_checkpurpose($top, X509_PURPOSE_ANY, [$store])) {
            vd_log('cert chain not trusted', ['host' => $host]);
            return null;
        }

        $bundle = (string) file_get_contents($store) . "\n" . implode("\n", $extras) . "\n";
        if (@file_put_contents($path, $bundle, LOCK_EX) === false) {
            return null;
        }
        @chmod($path, 0600);

        $leaf = @openssl_x509_read((string) $chain[0]);
        if ($leaf === false || !@openssl_x509_checkpurpose($leaf, X509_PURPOSE_SSL_SERVER, [$path])) {
            @unlink($path);
            return null;
        }
        vd_log('certificate chain completed', ['host' => $host, 'added' => count($extras)]);
        return self::$done[$host] = $path;
    }

    /**
     * The certificates the host presents, leaf first, as PEM.
     *
     * @return string[]
     */
    private static function peerChain(string $host): array
    {
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'capture_peer_cert' => true,
            'capture_peer_cert_chain' => true,
            'peer_name' => $host,
        ]]);
        $sock = @stream_socket_client('tls://' . $host . ':443', $errno, $err, 8, STREAM_CLIENT_CONNECT, $ctx);
        if ($sock === false) {
            return [];
        }
        $sent = stream_context_get_params($ctx)['options']['ssl']['peer_certificate_chain'] ?? [];
        fclose($sock);

        $out = [];
        foreach ((array) $sent as $cert) {
            $pem = null;
            if (@openssl_x509_export($cert, $pem) && is_string($pem) && str_contains($pem, 'BEGIN CERTIFICATE')) {
                $out[] = trim($pem);
            }
        }
        return $out;
    }

    /**
     * Issuer certificates above whatever the host already sent, fetched from the
     * AIA pointer each certificate carries.
     *
     * @param string[] $chain
     * @return string[]
     */
    private static function walk(array $chain, string $store): array
    {
        $extras = array_slice($chain, 1);
        $seen = array_map(static fn(string $pem): string => self::fingerprint($pem), $chain);
        $cursor = (string) end($chain);

        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            $next = null;
            foreach (self::aiaUris($cursor) as $uri) {
                $next = self::fetchIssuer($uri);
                if ($next !== null) {
                    break;
                }
            }
            if ($next === null) {
                break;
            }
            $fp = self::fingerprint($next);
            if ($fp === '' || in_array($fp, $seen, true)) {
                break;
            }
            if (self::isSelfSigned($next)) {
                break; // a root belongs to the machine's store, not to us
            }
            $extras[] = $next;
            $seen[] = $fp;
            $cursor = $next;

            $parsed = @openssl_x509_read($cursor);
            if ($parsed !== false && @openssl_x509_checkpurpose($parsed, X509_PURPOSE_ANY, [$store])) {
                break; // it reaches a root the machine already trusts
            }
        }
        return $extras;
    }

    /**
     * UniformResourceIdentifier entries tagged as id-ad-caIssuers
     * (1.3.6.1.5.5.7.48.2) inside the DER.
     *
     * @return string[]
     */
    public static function aiaUris(string $pem): array
    {
        $der = self::der($pem);
        if ($der === '') {
            return [];
        }
        // OID 06 08 2B 06 01 05 05 07 30 02 followed by [6] IA5String (0x86)
        $needle = chr(0x06) . chr(0x08) . "\x2b\x06\x01\x05\x05\x07\x30\x02" . "\x86";
        $out = [];
        $pos = 0;
        while (($i = strpos($der, $needle, $pos)) !== false) {
            $pos = $i + strlen($needle);
            $len = ord($der[$pos] ?? "\x00");
            $offset = 1;
            if ($len >= 0x81 && $len <= 0x84) {
                $count = $len & 0x7f;
                $len = 0;
                for ($k = 0; $k < $count; $k++) {
                    $len = ($len << 8) | ord($der[$pos + 1 + $k] ?? "\x00");
                }
                $offset = 1 + $count;
            }
            $uri = substr($der, $pos + $offset, $len);
            if ($uri !== false && preg_match('~^https?://\S{1,300}$~', $uri)) {
                $out[] = $uri;
            }
        }
        return array_values(array_unique($out));
    }

    private static function fetchIssuer(string $uri): ?string
    {
        if (!filter_var($uri, FILTER_VALIDATE_URL)) {
            return null;
        }
        try {
            UrlGuard::assertSafe($uri);
        } catch (UrlGuardException $e) {
            return null;
        }
        $ctx = stream_context_create(['http' => [
            'timeout' => 8,
            'follow_location' => 1,
            'max_redirects' => 3,
            'ignore_errors' => true,
            'protocol_version' => 1.1,
        ]]);
        $body = @file_get_contents($uri, false, $ctx);
        if (!is_string($body) || strlen($body) < 200 || strlen($body) > self::MAX_ISSUER_BYTES) {
            return null;
        }
        if (str_contains($body, 'BEGIN CERTIFICATE')) {
            $pem = substr($body, (int) strpos($body, '-----BEGIN CERTIFICATE-----'));
            $end = strpos($pem, '-----END CERTIFICATE-----');
            $pem = $end === false ? $pem : substr($pem, 0, $end + 25);
            return @openssl_x509_read($pem) === false ? null : trim($pem);
        }
        $pem = "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(base64_encode($body), 64, "\n")
            . "-----END CERTIFICATE-----\n";
        return @openssl_x509_read($pem) === false ? null : trim($pem);
    }

    private static function der(string $pem): string
    {
        if (!preg_match('~-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----~s', $pem, $m)) {
            return '';
        }
        return (string) base64_decode(preg_replace('~\s+~', '', $m[1]) ?? '');
    }

    private static function fingerprint(string $pem): string
    {
        $der = self::der($pem);
        return $der === '' ? '' : hash('sha256', $der);
    }

    private static function isSelfSigned(string $pem): bool
    {
        $p = @openssl_x509_parse($pem);
        if (!is_array($p) || !isset($p['subject'], $p['issuer'])) {
            return true; // unreadable: refuse rather than treat it as an issuer
        }
        return $p['subject'] == $p['issuer'];
    }

    /** Path to the bundle of roots this machine already trusts. */
    private static function systemStore(): ?string
    {
        $candidates = array_values(array_filter([
            (string) ini_get('curl.cainfo'),
            (string) ini_get('openssl.cafile'),
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/etc/ssl/cert.pem',
        ]));
        foreach ($candidates as $file) {
            if (is_readable($file) && (int) (@filesize($file) ?: 0) > 4096
                && str_contains((string) @file_get_contents($file, false, null, 0, 4096), 'BEGIN CERTIFICATE')) {
                return $file;
            }
        }
        return null;
    }

    private static function cachePath(string $host): string
    {
        $dir = VD_ROOT . '/storage/cert';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir . '/' . substr(hash('sha256', strtolower($host)), 0, 24) . '.pem';
    }
}
