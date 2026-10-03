<?php

// Fixture project for Starlite's own tests: same shape as an app's config/app.php.
return [
    'secret' => getenv('APP_SECRET') ?: throw new RuntimeException('APP_SECRET is not set.'),
    'debug' => false,
    'url' => 'https://example.test',
    'trusted_proxies' => [],
    'language' => 'en',
    'languages' => [
        'en' => ['name' => 'English', 'locale' => 'en_US'],
        'it' => ['name' => 'Italiano', 'locale' => 'it_IT'],
    ],
    'blog' => ['per_page' => 20],
    'site' => [
        'name' => 'Fixture',
        'description' => 'Fixture site',
        'image' => null,
        'author' => 'Ada',
    ],
];
