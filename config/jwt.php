<?php

return [
    'secret' => env('JWT_SECRET'),
    'ttl' => env('JWT_TTL', 60),
    'refresh_ttl' => env('JWT_REFRESH_TTL', 20160),
    'algo' => env('JWT_ALGO', 'HS256'),

    'required_claims' => ['iss', 'iat', 'exp', 'nbf', 'sub', 'jti'],
    'blacklist_enabled' => env('JWT_BLACKLIST_ENABLED', true),

    'providers' => [
        'jwt' => PhpOpenSourceSaver\JWTAuth\Providers\JWT\Lcobucci::class,
        'auth' => PhpOpenSourceSaver\JWTAuth\Providers\Auth\Illuminate::class,
        'storage' => PhpOpenSourceSaver\JWTAuth\Providers\Storage\Illuminate::class,
    ],
];
