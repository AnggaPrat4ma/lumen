<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

/** @var \Laravel\Lumen\Routing\Router $router */

$router->get('/', function () use ($router) {
    return response()->json([
        'message' => 'Event Management API - RBAC System',
        'version' => $router->app->version()
    ]);
});

$router->get('/clear-permission-cache', function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    return 'Permission cache cleared';
});

$router->get('/debug-permission', function (Request $request) {
    $user = $request->user();

    if (!$user) {
        return response()->json(['message' => 'No user authenticated']);
    }

    Log::info('DEBUG PERMISSION CHECK', [
        'user_id' => $user->id_user,
        'user_roles' => $user->getRoleNames()->toArray(),
        'all_permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
        'can_event_create' => $user->can('event.create'),
        'has_role_user' => $user->hasRole('User'),
        'has_role_eo' => $user->hasRole('EO'),
        'has_role_admin' => $user->hasRole('Admin')
    ]);

    return response()->json([
        'user_id' => $user->id_user,
        'nama' => $user->nama,
        'roles' => $user->getRoleNames(),
        'all_permissions' => $user->getAllPermissions()->pluck('name'),
        'can_event_create' => $user->can('event.create'),
        'has_role_user' => $user->hasRole('User'),
        'has_role_eo' => $user->hasRole('EO'),
        'has_role_admin' => $user->hasRole('Admin')
    ]);
});

$router->get('/debug/storage', 'EventController@debugStorage');
// Tambahkan route untuk cleanup
$router->get('/cleanup-banners', function () {
    $events = \App\Models\Event::whereNotNull('banner')->get();
    $cleaned = 0;
    $errors = [];

    foreach ($events as $event) {
        $fullPath = storage_path('app/public/' . $event->banner);

        // Jika file tidak ada, set banner NULL
        if (!file_exists($fullPath)) {
            $errors[] = [
                'event_id' => $event->id_event,
                'event_name' => $event->nama_event,
                'missing_file' => $event->banner
            ];

            $event->update(['banner' => null]);
            $cleaned++;
        }
    }

    return response()->json([
        'success' => true,
        'cleaned' => $cleaned,
        'missing_files' => $errors
    ]);
});

$router->get('/storage/{path:.*}', function ($path) {
    $file = storage_path('app/public/' . $path);

    if (!file_exists($file)) {
        abort(404);
    }

    return response()->file($file, [
        'Access-Control-Allow-Origin' => 'http://localhost:5173',
        'Access-Control-Allow-Methods' => 'GET, OPTIONS',
        'Access-Control-Allow-Headers' => '*',
    ]);
});


// ============================================
// PUBLIC ROUTES (No Auth)
// ============================================

$router->group(['prefix' => 'api'], function () use ($router) {

    // ✅ FIREBASE + JWT AUTHENTICATION
    $router->post('/auth/firebase', 'AuthController@firebaseAuth');     // Firebase → JWT
    $router->post('/auth/login', 'AuthController@login');               // Email/Password → JWT
    $router->post('/auth/register', 'AuthController@register');         // Register → JWT
    $router->post('/auth/verify-token', 'AuthController@verifyToken');  // Verify JWT

    // Public: View events
    $router->get('/events/public', 'EventController@publicEvents');

    // Midtrans Callback - PUBLIC
    $router->post('/midtrans/callback', 'MidtransCallbackController@handleNotification');

    // ⚠️ DEVELOPMENT ONLY - Hapus setelah digunakan!
    $router->get('/generate-event-slugs', function () use ($router) {
        try {
            $events = \App\Models\Event::whereNull('slug')
                ->orWhere('slug', '')
                ->get();

            if ($events->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => '✅ All events already have slugs!',
                    'total' => 0
                ]);
            }

            $results = [];
            $successCount = 0;
            $errorCount = 0;

            foreach ($events as $event) {
                try {
                    $slug = \Illuminate\Support\Str::slug($event->nama_event);
                    $originalSlug = $slug;
                    $count = 1;

                    // Ensure uniqueness
                    while (\App\Models\Event::where('slug', $slug)
                        ->where('id_event', '!=', $event->id_event)
                        ->exists()
                    ) {
                        $slug = $originalSlug . '-' . $count;
                        $count++;
                    }

                    $event->slug = $slug;
                    $event->save();

                    $results[] = [
                        'status' => '✅ success',
                        'id' => $event->id_event,
                        'event' => $event->nama_event,
                        'slug' => $slug
                    ];
                    $successCount++;
                } catch (\Exception $e) {
                    $results[] = [
                        'status' => '❌ error',
                        'id' => $event->id_event,
                        'event' => $event->nama_event,
                        'error' => $e->getMessage()
                    ];
                    $errorCount++;
                }
            }

            return response()->json([
                'success' => true,
                'message' => '🎉 Slug generation complete!',
                'summary' => [
                    'total_processed' => $events->count(),
                    'success' => $successCount,
                    'errors' => $errorCount
                ],
                'results' => $results
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error generating slugs',
                'error' => $e->getMessage()
            ], 500);
        }
    });
});

// ============================================
// PROTECTED ROUTES (Requires JWT Auth + DB Check)
// ============================================

$router->group(['prefix' => 'api', 'middleware' => ['auth', 'jwt.db']], function () use ($router) {

    // ============================================
    // AUTH ROUTES
    // ============================================
    $router->get('/auth/me', 'AuthController@me');
    $router->put('/auth/profile', 'AuthController@updateProfile');
    $router->post('/auth/logout', 'AuthController@logout');              // ✅ Logout (hapus current token)
    $router->post('/auth/logout-all', 'AuthController@logoutAll');       // ✅ Logout all devices
    $router->post('/auth/refresh', 'AuthController@refresh');            // ✅ Refresh token

    // Get managed events
    $router->get('/events/my-managed', 'EventController@getMyManagedEvents');
    // Cek apakah user bisa akses halaman panitia
    $router->get('/check-panitia-access', 'EventController@checkPanitiaAccess');

    // Get event yang di-assign ke user (khusus panitia)
    $router->get('/events/my-assigned', 'EventController@getMyAssignedEvents');

    $router->get('/tiket/{id}/download-pdf', 'TicketController@downloadPdf');

    // ============================================
    // MIDTRANS ROUTES
    // ============================================
    $router->group(['prefix' => 'midtrans'], function () use ($router) {
        $router->post('/create-transaction', 'MidtransController@createTransaction');
        $router->get('/status/{orderId}', 'MidtransController@getTransactionStatus');
        $router->get('/check-status/{orderId}', 'MidtransCallbackController@checkPaymentStatus');
    });

    // ============================================
    // SCAN HISTORY ROUTES
    // ============================================
    $router->post('/scan-history', 'ScanHistoryController@scanTiket');
    $router->get('/scan-history/check/{idTiket}', 'ScanHistoryController@checkTiketScan');
    $router->get('/scan-history/tiket/{idTiket}', 'ScanHistoryController@getScanHistoryByTiket');
    $router->get('/scan-history/user/{idUser}', 'ScanHistoryController@getScanHistoryByUser');
    $router->get('/scan-history/event/{eventId}', 'ScanHistoryController@getScanHistoryByEvent');
    $router->get('/scan-history', 'ScanHistoryController@getAllScanHistory');
    $router->get('/scan-history/statistics', 'ScanHistoryController@getStatistics');
    $router->delete('/scan-history/{idScan}', 'ScanHistoryController@deleteScanHistory');

    // ============================================
    // EVENT ROUTES
    // ============================================
    $router->get('/events', 'EventController@index');
    $router->get('/events/{slug}', 'EventController@show');
    $router->get('/events/{id}/jenis-tiket', 'EventController@getJenisTiketByEvent');

    $router->group(['middleware' => 'permission:event.create'], function () use ($router) {
        $router->post('/events', 'EventController@store');
    });

    $router->group(['middleware' => 'permission:event.update'], function () use ($router) {
        $router->put('/events/{id}', 'EventController@update');
        $router->patch('/events/{id}', 'EventController@update');
    });

    $router->group(['middleware' => 'permission:event.delete'], function () use ($router) {
        $router->delete('/events/{id}', 'EventController@destroy');
    });

    // ============================================
    // JENIS TIKET ROUTES
    // ============================================
    $router->get('/jenis-tiket', 'JenisTiketController@index');
    $router->get('/jenis-tiket/{id}', 'JenisTiketController@show');
    $router->get('/jenis-tiket/{id}/available', 'JenisTiketController@checkAvailability');
    $router->get('/jenis-tiket/event/{eventId}', 'JenisTiketController@getByEvent');

    $router->group(['middleware' => 'permission:jenis-tiket.create'], function () use ($router) {
        $router->post('/jenis-tiket', 'JenisTiketController@store');
    });

    $router->group(['middleware' => 'permission:jenis-tiket.update'], function () use ($router) {
        $router->put('/jenis-tiket/{id}', 'JenisTiketController@update');
    });

    $router->group(['middleware' => 'permission:jenis-tiket.delete'], function () use ($router) {
        $router->delete('/jenis-tiket/{id}', 'JenisTiketController@destroy');
    });

    // ============================================
    // TIKET ROUTES
    // ============================================
    $router->group(['middleware' => 'permission:tiket.scan'], function () use ($router) {
        $router->post('/tiket/scan', 'TicketController@scan');
        $router->post('/tiket/check-in', 'TicketController@checkIn');
        $router->get('/tiket/scan-history', 'TicketController@scanHistory');
    });

    $router->get('/tiket/my-tickets', 'TicketController@myTickets');
    $router->get('/tiket/{id}', 'TicketController@show');
    $router->get('/tiket/event/{eventId}', 'TicketController@getEventTickets');
    $router->get('/tiket/event/{eventId}/statistics', 'TicketController@getEventStatistics');

    $router->group(['middleware' => 'permission:tiket.verify'], function () use ($router) {
        $router->get('/tiket/verify/{qrCode}', 'TicketController@verify');
        $router->get('/tiket/qr/{qrCode}', 'TicketController@getByQrCode');
        $router->post('/tiket/validate', 'TicketController@validateTicket');
    });

    $router->post('/tiket/{id}/cancel', 'TicketController@cancel');

    // ============================================
    // TRANSAKSI ROUTES
    // ============================================
    $router->group(['middleware' => 'permission:transaksi.approve'], function () use ($router) {
        $router->post('/transaksi/{id}/approve', 'TransaksiController@approve');
        $router->post('/transaksi/{id}/reject', 'TransaksiController@reject');
        $router->get('/transaksi/all', 'TransaksiController@all');
    });

    $router->post('/transaksi/register-free', 'TransaksiController@registerFree');
    $router->get('/transaksi/can-register/{eventId}', 'TransaksiController@canRegister');

    $router->group(['middleware' => 'permission:transaksi.create'], function () use ($router) {
        $router->post('/transaksi', 'TransaksiController@store');
    });

    $router->get('/transaksi', 'TransaksiController@index');
    $router->get('/transaksi/{id}', 'TransaksiController@show');

    // ============================================
    // USER MANAGEMENT
    // ============================================
    $router->group(['middleware' => 'role:Admin,EO'], function () use ($router) {
        $router->get('/users', 'UserController@index');
        $router->get('/users/{id}', 'UserController@show');
        $router->post('/users', 'UserController@store');
        $router->put('/users/{id}', 'UserController@update');
        $router->delete('/users/{id}', 'UserController@destroy');

        $router->post('/users/{id}/assign-role', 'UserController@assignRole');
        $router->post('/users/{id}/remove-role', 'UserController@removeRole');

        $router->get('/users/{id}/permissions', 'UserController@getUserPermissions');
        $router->post('/users/{id}/give-permission', 'UserController@givePermission');
        $router->post('/users/{id}/revoke-permission', 'UserController@revokePermission');
    });

    // ============================================
    // ROLE & PERMISSION MANAGEMENT
    // ============================================
    $router->group(['middleware' => 'role:Admin'], function () use ($router) {
        $router->get('/roles', 'RoleController@index');
        $router->post('/roles', 'RoleController@store');
        $router->put('/roles/{id}', 'RoleController@update');
        $router->delete('/roles/{id}', 'RoleController@destroy');

        $router->get('/permissions', 'PermissionController@index');
        $router->post('/permissions', 'PermissionController@store');

        $router->post('/roles/{id}/assign-permission', 'RoleController@assignPermission');
        $router->post('/roles/{id}/remove-permission', 'RoleController@removePermission');
    });

    // ============================================
    // EVENT PANITIA MANAGEMENT
    // ============================================
    $router->get('/events/{id}/panitia', 'EventController@getEventPanitia');
    $router->post('/events/{id}/add-panitia', 'EventController@addPanitia');
    $router->post('/events/{id}/remove-panitia', 'EventController@removePanitia');
    $router->post('/events/{id}/transfer-ownership', 'EventController@transferOwnership');
});
