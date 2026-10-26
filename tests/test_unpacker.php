<?php
declare(strict_types=1);
/**
 * Usage: php tests/test_unpacker.php
 * Runs the JS unpacker + media sniffer against saved real pages.
 */
require __DIR__ . '/../lib/support.php';

// The fixtures are captures from real hosts, so they are not in the repository.
// Without them this file checks nothing, and a quiet pass would be a false claim.
if ((glob(__DIR__ . '/fixtures/*.html') ?: []) === []) {
    fwrite(STDERR, "SKIPPED: tests/fixtures/*.html are missing, so no real page was unpacked. See tests/fixtures/README.md\n");
    exit(3);
}

$MEDIA_RE = '~(?:https?:)?//[^\s"\'`<>(){}|\\\\]+?\.(?:m3u8|mp4|mkv|webm|ts)(?:\?[^\s"\'`<>(){}|\\\\]{0,220})?~i';

foreach (glob(__DIR__ . '/fixtures/*.html') ?: [] as $f) {
    $html = (string) file_get_contents($f);
    $js = JsUnpacker::unescape($html);
    $unpacked = JsUnpacker::unpack($js);
    $joined = JsUnpacker::joinConcatenations($unpacked);
    $changed = strlen($unpacked) !== strlen($js);
    echo '== ' . basename($f) . '  unpacked=' . ($changed ? 'yes' : 'NO')
        . ' (' . strlen($js) . ' -> ' . strlen($unpacked) . " bytes)\n";

    preg_match_all($MEDIA_RE, $joined, $mm);
    $found = array_values(array_unique($mm[0] ?? []));
    foreach (array_slice($found, 0, 12) as $u) {
        echo '   ' . $u . "\n";
    }
    if (!$found) {
        echo "   (no media url found)\n";
    }
    file_put_contents($f . '.unpacked', $unpacked);
}
echo "\nsniff regex ok: " . (preg_match($MEDIA_RE, 'x https://a.b/c/master.m3u8?t=1&s=2 y') ? 'yes' : 'NO') . "\n";
