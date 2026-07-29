<?php

// CORS is required once the frontend and backend are served from different
// origins (e.g. a Cloudflare Pages domain calling a shared-hosting API) --
// in local dev the Vite proxy hides this entirely, so nothing here affects
// that setup. Allowed origins come from env so they can be updated without
// a code change whenever the frontend's URL changes.

$origins = array_filter(array_map(
    'trim',
    explode(',', env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:5174')))
));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
