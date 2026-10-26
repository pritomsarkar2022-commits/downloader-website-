<?php
// One-off generator: turns assets/icons/*.svg into views/parts/sprite.php.
$dir = dirname(__DIR__) . '/assets/icons';
$symbols = [];
foreach (glob($dir . '/*.svg') ?: [] as $f) {
    $name = basename($f, '.svg');
    $svg = (string) file_get_contents($f);
    $vb = preg_match('/viewBox="([^"]+)"/', $svg, $v) ? $v[1] : '0 0 24 24';
    if (!preg_match('/<path[^>]*\bd="([^"]*)"/', $svg, $p)) {
        fwrite(STDERR, "skip $name\n");
        continue;
    }
    $symbols[$name] = sprintf('<symbol id="ic-%s" viewBox="%s"><path d="%s"/></symbol>', $name, $vb, $p[1]);
}
ksort($symbols);
$blob = '<svg xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false" style="position:absolute;width:0;height:0;overflow:hidden">'
    . implode('', $symbols) . '</svg>';
$php = "<" . "?php declare(strict_types=1); ?" . ">\n" . $blob . "\n";
file_put_contents(dirname(__DIR__) . '/views/parts/sprite.php', $php);
printf("%d symbols, %d bytes\n%s\n", count($symbols), strlen($blob), implode(' ', array_keys($symbols)));
