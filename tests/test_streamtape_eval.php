<?php
declare(strict_types=1);
/** Usage: php tests/test_streamtape_eval.php  (no network, asserts the js evaluator) */
require __DIR__ . '/../lib/support.php';

$live = "document.getElementById('captchalink').innerHTML = '//streamtape.com/get_video?id='+ ('defg74AwoLa6xQCAAQ4&expires=1792674117&ip=GHqsF0AnDS9X&token=lgLw_dA8kbiI').substring(4);";
$decoyA = "document.getElementById('ideoooolink').innerHTML = \"/streamtape.com/get_video?id=7\" + ''+ ('xcdd4AwoLa6xQCAAQ4&expires=1792674117&ip=GHqsF0AnDS9X&token=lgLw_dA8kbiI').substring(1).substring(2);";
$decoyB = "document.getElementById('norobotlink').innerHTML = '//streamtape.com/get_video?id=' + ('xcd74AwoLa6xQCAAQ4&expires=1792674117&ip=GHqsF0AnDS9X&token=lgLw_dA8kbiI').substring(1).substring(2);";

// The evaluator works on the right-hand side only, so strip the assignment.
$rhs = static fn(string $s): string => trim(explode('=', $s, 2)[1] ?? '', " ;");

$checks = [
    'live line yields the real id' => [StreamtapeExtractor::evalConcat($rhs($live)), "//streamtape.com/get_video?id=74AwoLa6xQCAAQ4&expires=1792674117&ip=GHqsF0AnDS9X&token=lgLw_dA8kbiI"],
    'decoy A corrupted id' => [StreamtapeExtractor::evalConcat($rhs($decoyA)), "/streamtape.com/get_video?id=7d4AwoLa6xQCAAQ4&expires=1792674117&ip=GHqsF0AnDS9X&token=lgLw_dA8kbiI"],
    // substring(1) drops 'x', then substring(2) drops 'cd' of the remainder: 3 chars in total.
    'decoy B lands on the real id' => [StreamtapeExtractor::evalConcat($rhs($decoyB)), "//streamtape.com/get_video?id=74AwoLa6xQCAAQ4&expires=1792674117&ip=GHqsF0AnDS9X&token=lgLw_dA8kbiI"],
    'substring chain caps at the string end' => [StreamtapeExtractor::evalConcat("('abcdef').substring(2).substring(6)"), ''],
    'plain literal' => [StreamtapeExtractor::evalConcat("'a' + 'b'"), 'ab'],
    'rejects unknown variable' => [StreamtapeExtractor::evalConcat("'a' + token"), null],
    'rejects unbalanced parens' => [StreamtapeExtractor::evalConcat("('a'"), null],
];

$fail = 0;
foreach ($checks as $name => [$got, $want]) {
    $ok = $got === $want;
    if (!$ok) {
        $fail++;
    }
    printf("%s %s\n", $ok ? '[PASS] ' : '[FAIL] ', $name);
    if (!$ok) {
        echo "       got:  " . var_export($got, true) . "\n       want: " . var_export($want, true) . "\n";
    }
}

// And the whole page parse: rank the live candidate first by id match.
$html = "<html><body>" . $live . $decoyA . $decoyB
    . '<div id="ideoooolink" style="display:none;">/streamtape.com/get_video?id=74AwoLa6xQCAAQ4&expires=1792674117&ip=GHqsF0AnDS9X&token=lgLw_dA8kbzZ</div>'
    . '</body></html>';
$cands = StreamtapeExtractor::linkCandidates($html, 'https://streamtape.com/v/74AwoLa6xQCAAQ4/x.mp4');
echo "\ncandidates (" . count($cands) . "):\n";
foreach ($cands as $c) {
    echo '  ' . $c . "\n";
}
$firstOk = isset($cands[0]) && str_contains($cands[0], 'id=74AwoLa6xQCAAQ4&');
printf("%s first candidate carries the true page id\n", $firstOk ? '[PASS]' : '[FAIL]');
if (!$firstOk) {
    $fail++;
}
exit($fail === 0 ? 0 : 1);
