<?php

declare(strict_types=1);

// Runs the fixture app in production the way public/index.php does (Kernel::run(), with its PHP error
// handling), in a process of its own: for LogTest. Arguments: URL path, cache directory, log file.

use Starlite\Kernel;

require __DIR__ . '/../../vendor/autoload.php';

[, $path, $cacheDir, $log] = $argv;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = $path;

$app = Kernel::boot(__DIR__ . '/project', false, ['content_dir' => __DIR__ . '/content', 'cache_dir' => $cacheDir, 'log' => ['path' => $log]]);
$app->get('/warning', static function (): string {
    $values = [];
    trigger_error('An old way of doing things', E_USER_DEPRECATED);

    return 'still rendered' . $values['missing']; // a warning: undefined array key
});
$app->run();
