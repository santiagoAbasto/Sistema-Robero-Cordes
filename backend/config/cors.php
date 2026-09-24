<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The React SPA runs on a different origin (Vite dev server) and talks to
    | this API with Bearer tokens, so we expose the API paths to that origin.
    |
    */

    'paths' => ['api/*', 'login', 'logout', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:5173'),
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Bearer-token auth — no cross-site cookies required.
    'supports_credentials' => false,

];
