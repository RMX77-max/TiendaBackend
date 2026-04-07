<?php

return [
    'paths' => ['api/*', 'index.php/api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_filter([
        env('FRONTEND_URL', 'http://localhost:9000'),
        'https://microcenter-bolivia.com',
        'http://127.0.0.1:9000',
        'http://localhost:9000',
        'http://127.0.0.1:9001',
        'http://localhost:9001',
    ]),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
