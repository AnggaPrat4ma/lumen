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

    // Firebase Authentication
    $router->post('/auth/firebase', 'AuthController@firebaseAuth');
    $router->post('/auth/verify-token', 'AuthController@verifyToken');

    // Public: View events (untuk marketing/landing page)
    $router->get('/events/public', 'EventController@publicEvents');

    // ⭐ MIDTRANS CALLBACK - HARUS PUBLIC (DIPANGGIL OLEH SERVER MIDTRANS)
    $router->post('/midtrans/callback', 'MidtransCallbackController@handleNotification');
});

// ============================================
// PROTECTED ROUTES (Requires Firebase Auth)
// ============================================

$router->group(['prefix' => 'api', 'middleware' => 'cors'], function () use ($router) {
    // Get all events where user is owner or panitia
    $router->get('/events/my-managed', 'EventController@getMyManagedEvents');

    // ============================================
    // AUTH ROUTES
    // ============================================
    $router->get('/auth/me', 'AuthController@me');
    $router->put('/auth/profile', 'AuthController@updateProfile');
    $router->post('/auth/logout', 'AuthController@logout');

    // ============================================
    // MIDTRANS ROUTES (Yang memerlukan auth)
    // ============================================
    $router->group(['prefix' => 'midtrans'], function () use ($router) {
        $router->post('/create-transaction', 'MidtransController@createTransaction');
        $router->get('/status/{orderId}', 'MidtransController@getTransactionStatus');
        $router->get('/check-status/{orderId}', 'MidtransCallbackController@checkPaymentStatus');
    });

    // Scan History Routes
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

    // ✅ ALL authenticated users: View events
    $router->get('/events', 'EventController@index');
    $router->get('/events/{id}', 'EventController@show');
    $router->get('/events/{id}/jenis-tiket', 'EventController@getJenisTiketByEvent');

    // ✅ EO & Admin only: CRUD events
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

    // ✅ ALL authenticated users: View jenis tiket (untuk order)
    $router->get('/jenis-tiket', 'JenisTiketController@index');
    $router->get('/jenis-tiket/{id}', 'JenisTiketController@show');
    $router->get('/jenis-tiket/{id}/available', 'JenisTiketController@checkAvailability');
    $router->get('/jenis-tiket/event/{eventId}', 'JenisTiketController@getByEvent');

    // ✅ EO & Admin only: CRUD jenis tiket
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
    // TIKET ROUTES (Scan & View)
    // ============================================
    // ✅ EO & Panitia: Scan & Verify tickets
    $router->group(['middleware' => 'permission:tiket.scan'], function () use ($router) {
        $router->post('/tiket/scan', 'TicketController@scan');
        $router->post('/tiket/check-in', 'TicketController@checkIn'); // Alias
        $router->get('/tiket/scan-history', 'TicketController@scanHistory');
    });

    // ✅ User: View own tickets
    $router->get('/tiket/my-tickets', 'TicketController@myTickets');
    $router->get('/tiket/{id}', 'TicketController@show');

    // ✅ EO: View tickets from own events
    $router->get('/tiket/event/{eventId}', 'TicketController@getEventTickets');
    $router->get('/tiket/event/{eventId}/statistics', 'TicketController@getEventStatistics');

    $router->group(['middleware' => 'permission:tiket.verify'], function () use ($router) {
        $router->get('/tiket/verify/{qrCode}', 'TicketController@verify');
        $router->get('/tiket/qr/{qrCode}', 'TicketController@getByQrCode');
        $router->post('/tiket/validate', 'TicketController@validateTicket');
    });

    // ✅ User: Cancel own ticket
    $router->post('/tiket/{id}/cancel', 'TicketController@cancel');

    // ============================================
    // TRANSAKSI ROUTES
    // ============================================

    // ✅ Admin only: Approve/Reject transaksi
    $router->group(['middleware' => 'permission:transaksi.approve'], function () use ($router) {
        $router->post('/transaksi/{id}/approve', 'TransaksiController@approve');
        $router->post('/transaksi/{id}/reject', 'TransaksiController@reject');
        $router->get('/transaksi/all', 'TransaksiController@all'); // View all transaksi
    });

    $router->post('/transaksi/register-free', 'TransaksiController@registerFree');
    // ✅ NEW: Check if user can register for event
    $router->get('/transaksi/can-register/{eventId}', 'TransaksiController@canRegister');

    // ✅ User: Create transaksi (order tickets)
    $router->group(['middleware' => 'permission:transaksi.create'], function () use ($router) {
        $router->post('/transaksi', 'TransaksiController@store');
    });

    // ✅ User: View own transaksi
    $router->get('/transaksi', 'TransaksiController@index');
    $router->get('/transaksi/{id}', 'TransaksiController@show');

    // ============================================
    // USER MANAGEMENT (Admin only)
    // ============================================

    $router->group(['middleware' => 'role:Admin,EO'], function () use ($router) {
        // User CRUD
        $router->get('/users', 'UserController@index');
        $router->get('/users/{id}', 'UserController@show');
        $router->post('/users', 'UserController@store');
        $router->put('/users/{id}', 'UserController@update');
        $router->delete('/users/{id}', 'UserController@destroy');

        // Role Management
        $router->post('/users/{id}/assign-role', 'UserController@assignRole');
        $router->post('/users/{id}/remove-role', 'UserController@removeRole');

        // Permission Management (Direct to User)
        $router->get('/users/{id}/permissions', 'UserController@getUserPermissions');
        $router->post('/users/{id}/give-permission', 'UserController@givePermission');
        $router->post('/users/{id}/revoke-permission', 'UserController@revokePermission');
    });

    // ============================================
    // ROLE & PERMISSION MANAGEMENT (Admin only)
    // ============================================

    $router->group(['middleware' => 'role:Admin'], function () use ($router) {
        // Roles
        $router->get('/roles', 'RoleController@index');
        $router->post('/roles', 'RoleController@store');
        $router->put('/roles/{id}', 'RoleController@update');
        $router->delete('/roles/{id}', 'RoleController@destroy');

        // Permissions
        $router->get('/permissions', 'PermissionController@index');
        $router->post('/permissions', 'PermissionController@store');

        // Assign permissions to role
        $router->post('/roles/{id}/assign-permission', 'RoleController@assignPermission');
        $router->post('/roles/{id}/remove-permission', 'RoleController@removePermission');
    });

    // ============================================
    // 🆕 EVENT PANITIA MANAGEMENT
    // ============================================



    // Get panitia list for specific event (Owner only)
    $router->get('/events/{id}/panitia', 'EventController@getEventPanitia');

    // Add panitia to event (Owner only)
    $router->post('/events/{id}/add-panitia', 'EventController@addPanitia');

    // Remove panitia from event (Owner only)
    $router->post('/events/{id}/remove-panitia', 'EventController@removePanitia');

    // Transfer event ownership (Owner only)
    $router->post('/events/{id}/transfer-ownership', 'EventController@transferOwnership');
});
