<?php

require_once __DIR__ . '/../vendor/autoload.php';

(new Laravel\Lumen\Bootstrap\LoadEnvironmentVariables(
    dirname(__DIR__)
))->bootstrap();

date_default_timezone_set(env('APP_TIMEZONE', 'UTC'));

$app = new Laravel\Lumen\Application(dirname(__DIR__));

/*
|--------------------------------------------------------------------------
| Helper Function: config_path
|--------------------------------------------------------------------------
| Lumen tidak punya helper config_path() secara bawaan.
| Ini diperlukan untuk package seperti Spatie dan Firebase.
*/
if (!function_exists('config_path')) {
    function config_path($path = '')
    {
        return app()->basePath() . '/config' . ($path ? '/' . $path : $path);
    }
}

/*
|--------------------------------------------------------------------------
| Enable Facades & Eloquent ORM
|--------------------------------------------------------------------------
*/
$app->withFacades();
$app->withEloquent();

/*
|--------------------------------------------------------------------------
| Load Configuration Files
|--------------------------------------------------------------------------
*/
$app->configure('app');
$app->configure('auth');
$app->configure('database');
$app->configure('cache');
$app->configure('cors');
$app->configure('firebase');
$app->configure('permission');
$app->configure('midtrans'); // Masih kamu gunakan, jadi tetap diaktifkan
$app->configure('filesystems');

/*
|--------------------------------------------------------------------------
| Register Global & Route Middleware
|--------------------------------------------------------------------------
*/
$app->middleware([
    App\Http\Middleware\CorsMiddleware::class,
]);

$app->routeMiddleware([
    'auth' => App\Http\Middleware\Authenticate::class,
    'role' => App\Http\Middleware\RoleMiddleware::class,
    'permission' => App\Http\Middleware\PermissionMiddleware::class,
    'cors' => App\Http\Middleware\CorsMiddleware::class,
    // 'role_or_permission' => Spatie\Permission\Middlewares\RoleOrPermissionMiddleware::class,
]);

/*
|--------------------------------------------------------------------------
| Register Service Providers
|--------------------------------------------------------------------------
*/
$app->alias('cache', \Illuminate\Cache\CacheManager::class);

// Provider default & tambahan yang kamu butuhkan
$app->register(App\Providers\AuthServiceProvider::class);
$app->register(Kreait\Laravel\Firebase\ServiceProvider::class);
$app->register(Flipbox\LumenGenerator\LumenGeneratorServiceProvider::class);
$app->register(App\Providers\LumenPermissionServiceProvider::class);
// $app->register(Spatie\Permission\PermissionServiceProvider::class);
// $app->alias('cache', Illuminate\Cache\CacheManager::class);


/*
|--------------------------------------------------------------------------
| Register Exception & Console Kernel
|--------------------------------------------------------------------------
*/
$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

/*
|--------------------------------------------------------------------------
| Load Application Routes
|--------------------------------------------------------------------------
*/
$app->router->group([
    'namespace' => 'App\Http\Controllers',
], function ($router) {
    require __DIR__ . '/../routes/web.php';
});

return $app;
