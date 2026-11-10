<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'storage/*'],

    'allowed_methods' => ['*'],

    // ضيف هون كل دومين الفرونت إند الفعلي (تطوير + إنتاج) — لا تستخدم '*'
    // لأنه لما supports_credentials = true، المتصفح بيرفض '*' أصلاً.
    //
    // ⚠️ `CORS_ALLOWED_ORIGINS` lives in .env, and **.env is git-ignored**, so a
    // `git pull` never refreshes it. A server whose .env predates a new domain
    // keeps rejecting that domain no matter what the code says — that is exactly
    // how the deployed site ended up blocked while the code looked correct.
    //
    // The production origins are therefore merged in *here*, from code that does
    // travel with the deploy. The env var stays authoritative for everything
    // else (previews, tunnels, a new staging host); this list only guarantees
    // the sites we know must never break.
    'allowed_origins' => array_values(array_unique(array_merge(
        [
            'https://skillspan-iota.vercel.app',
        ],
        array_filter(array_map(
            'trim',
            explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://localhost:3000'))
        ))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
