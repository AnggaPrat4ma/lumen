<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Firebase Credentials
    |--------------------------------------------------------------------------
    */
    'credentials' => [
        // Path ke file service account JSON kamu
        'file' => env('FIREBASE_CREDENTIALS', base_path('firebase/firebase-service-account.json')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Firebase Auth
    |--------------------------------------------------------------------------
    */
    'auth' => [
        'tenant_id' => env('FIREBASE_AUTH_TENANT_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Firebase Realtime Database
    |--------------------------------------------------------------------------
    */
    'database' => [
        'url' => env('FIREBASE_DATABASE_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Storage
    |--------------------------------------------------------------------------
    */
    'storage' => [
        'default_bucket' => env('FIREBASE_STORAGE_DEFAULT_BUCKET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Firebase Dynamic Links
    |--------------------------------------------------------------------------
    */
    'dynamic_links' => [
        'default_domain' => env('FIREBASE_DYNAMIC_LINKS_DEFAULT_DOMAIN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching & Logging
    |--------------------------------------------------------------------------
    */
    'cache_store' => env('FIREBASE_CACHE_STORE', 'file'),

    'logging' => [
        'http_log_channel' => env('FIREBASE_HTTP_LOG_CHANNEL'),
        'http_debug_log_channel' => env('FIREBASE_HTTP_DEBUG_LOG_CHANNEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Options
    |--------------------------------------------------------------------------
    */
    'http_client_options' => [
        'proxy' => env('FIREBASE_HTTP_CLIENT_PROXY'),
        'timeout' => env('FIREBASE_HTTP_CLIENT_TIMEOUT'),
    ],

];
