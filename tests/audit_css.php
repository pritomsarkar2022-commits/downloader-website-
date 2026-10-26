<?php
declare(strict_types=1);

/** Report class tokens used in views and js that no stylesheet defines. */
$root = dirname(__DIR__);
$css = '';
foreach (glob($root . '/assets/css/*.css') ?: [] as $f) {
    $css .= file_get_contents($f);
}
preg_match_all('~\.([a-zA-Z][\w-]*)~', preg_replace('~/\*.*?\*/~s', '', $css) ?? '', $c);
$defined = array_flip($c[1]);

$used = [];
$files = array_merge(
    glob($root . '/views/*.php') ?: [],
    glob($root . '/views/parts/*.php') ?: [],
    [$root . '/assets/js/app.js']
);
foreach ($files as $f) {
    $body = (string) file_get_contents($f);
    preg_match_all('~class(?:Name)?\s*=\s*(?:"|\'|`)([^"\'`]{2,200})~', $body, $m);
    foreach ($m[1] as $attr) {
        if (str_contains($attr, '<?') || str_contains($attr, '${')) {
            continue;
        }
        foreach (preg_split('~\s+~', trim($attr)) ?: [] as $tok) {
            if ($tok !== '' && !isset($defined[$tok])) {
                $used[$tok][] = basename($f);
            }
        }
    }
}
ksort($used);
foreach ($used as $tok => $where) {
    printf("%-22s %s\n", $tok, implode(',', array_unique($where)));
}
echo count($used) . " undefined class tokens\n";
