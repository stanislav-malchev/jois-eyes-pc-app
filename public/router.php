<?php

// Router for `php -S`: lets the built-in server serve existing static
// files (CSS/JS/fonts under public/bundles, etc.) directly instead of
// running them through Symfony's front controller, which otherwise
// crashes (autoload_runtime.php requires SCRIPT_FILENAME expecting the
// app callable, not raw asset bytes).
$path = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if ($path !== '/' && is_file(__DIR__.$path)) {
    return false;
}

require __DIR__.'/index.php';
