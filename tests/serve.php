<?php
// Local dev router for `php -S 127.0.0.1:8080 tests/serve.php`
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
// Mirror the .htaccess rule so a local run cannot serve internals either.
if (preg_match('#^/(lib|config|views|tests|storage)(/|$)#', $path)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}
$file = __DIR__ . '/../' . ltrim($path, '/');
if ($path !== '/' && $path !== '/index.php' && is_file($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $types = ['css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml', 'json' => 'application/json', 'woff2' => 'font/woff2'];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    readfile($file);
    return true;
}
require __DIR__ . '/../index.php';
