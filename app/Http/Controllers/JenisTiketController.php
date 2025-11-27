<?php

namespace App\Http\Controllers;

use App\Models\JenisTiket;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class JenisTiketController extends Controller
{
    /**
     * GET /api/jenis-tiket
     * Semua authenticated user bisa lihat
     * EO: Hanya jenis tiket dari eventnya
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        $query = JenisTiket::with('event');

        // ✅ RBAC: EO hanya lihat jenis tiket dari eventnya
        if ($user && $user->hasRole('EO') && !$user->hasRole('Admin')) {
            // Get event IDs yang user own
            $ownedEventIds = DB::table('user_has_event')
                ->where('id_user', $user->id_user)
                ->where('is_owner', 1)
                ->pluck('id_event');

            $query->whereIn('id_event', $ownedEventIds);
        }

        // Filter by event
        if ($request->has('id_event')) {
            $query->where('id_event', $request->id_event);
        }

        $data = $query->get();

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * GET /api/jenis-tiket/{id}
     * Lihat detail jenis tiket
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $tiket = JenisTiket::with('event')->find($id);
        
        if (!$tiket) {
            return response()->json([
                'success' => false,
                'message' => 'Jenis tiket tidak ditemukan'
            ], 404);
        }

        // ✅ RBAC: EO hanya bisa lihat jenis tiket dari eventnya
        if ($user && $user->hasRole('EO') && !$user->hasRole('Admin')) {
            // Check if user owns the event
            $isOwner = DB::table('user_has_event')
                ->where('id_user', $user->id_user)
                ->where('id_event', $tiket->id_event)
                ->where('is_owner', 1)
                ->exists();

            if (!$isOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only view ticket types from your own events'
                ], 403);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $tiket
        ]);
    }

    /**
     * POST /api/jenis-tiket
     * Create jenis tiket - Hanya EO & Admin
     * ⚠️ Middleware 'permission:jenis-tiket.create' sudah di-handle di routes
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_event'   => 'required|integer|exists:event,id_event',
            'nama_tiket' => 'required|string|max:100',
            'harga'      => 'required|numeric|min:0',
            'kuota'      => 'required|integer|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        // ✅ OWNERSHIP CHECK: EO hanya bisa create jenis tiket untuk eventnya
        if ($user->hasRole('EO') && !$user->hasRole('Admin')) {
            $event = Event::find($request->id_event);
            
            if (!$event) {
                return response()->json([
                    'success' => false,
                    'message' => 'Event not found'
                ], 404);
            }

            // ✅ FIX: Check ownership via user_has_event table
            $isOwner = DB::table('user_has_event')
                ->where('id_user', $user->id_user)
                ->where('id_event', $request->id_event)
                ->where('is_owner', 1)
                ->exists();

            if (!$isOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only create ticket types for your own events'
                ], 403);
            }
        }

        $tiket = JenisTiket::create($request->only([
            'id_event', 'nama_tiket', 'harga', 'kuota'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Jenis tiket berhasil dibuat',
            'data' => $tiket->load('event')
        ], 201);
    }

    /**
     * PUT /api/jenis-tiket/{id}
     * Update jenis tiket - Hanya owner event atau Admin
     * ⚠️ Middleware 'permission:jenis-tiket.update' sudah di-handle di routes
     */
    public function update(Request $request, $id)
    {
        $tiket = JenisTiket::with('event')->find($id);
        
        if (!$tiket) {
            return response()->json([
                'success' => false,
                'message' => 'Jenis tiket tidak ditemukan'
            ], 404);
        }

        $user = $request->user();

        // ✅ OWNERSHIP CHECK: EO hanya bisa update jenis tiket dari eventnya
        if ($user->hasRole('EO') && !$user->hasRole('Admin')) {
            // ✅ FIX: Check ownership via user_has_event table
            $isOwner = DB::table('user_has_event')
                ->where('id_user', $user->id_user)
                ->where('id_event', $tiket->id_event)
                ->where('is_owner', 1)
                ->exists();

            if (!$isOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only update ticket types from your own events'
                ], 403);
            }
        }

        $validator = Validator::make($request->all(), [
            'id_event'   => 'sometimes|required|integer|exists:event,id_event',
            'nama_tiket' => 'sometimes|required|string|max:100',
            'harga'      => 'sometimes|required|numeric|min:0',
            'kuota'      => 'sometimes|required|integer|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Jika mengubah id_event, check ownership event baru
        if ($request->has('id_event') && $request->id_event != $tiket->id_event) {
            if ($user->hasRole('EO') && !$user->hasRole('Admin')) {
                $newEvent = Event::find($request->id_event);
                
                if (!$newEvent) {
                    return response()->json([
                        'success' => false,
                        'message' => 'New event not found'
                    ], 404);
                }

                // ✅ FIX: Check ownership via user_has_event table
                $isOwnerOfNewEvent = DB::table('user_has_event')
                    ->where('id_user', $user->id_user)
                    ->where('id_event', $request->id_event)
                    ->where('is_owner', 1)
                    ->exists();

                if (!$isOwnerOfNewEvent) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You can only move ticket types to your own events'
                    ], 403);
                }
            }
        }

        $tiket->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Jenis tiket berhasil diperbarui',
            'data' => $tiket->fresh()->load('event')
        ]);
    }

    /**
     * DELETE /api/jenis-tiket/{id}
     * Delete jenis tiket - Hanya owner event atau Admin
     * ⚠️ Middleware 'permission:jenis-tiket.delete' sudah di-handle di routes
     */
    public function destroy(Request $request, $id)
    {
        $tiket = JenisTiket::with('event')->find($id);
        
        if (!$tiket) {
            return response()->json([
                'success' => false,
                'message' => 'Jenis tiket tidak ditemukan'
            ], 404);
        }

        $user = $request->user();

        // ✅ OWNERSHIP CHECK: EO hanya bisa delete jenis tiket dari eventnya
        if ($user->hasRole('EO') && !$user->hasRole('Admin')) {
            // ✅ FIX: Check ownership via user_has_event table
            $isOwner = DB::table('user_has_event')
                ->where('id_user', $user->id_user)
                ->where('id_event', $tiket->id_event)
                ->where('is_owner', 1)
                ->exists();

            if (!$isOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only delete ticket types from your own events'
                ], 403);
            }
        }

        // ⚠️ Check apakah ada transaksi terkait (optional business logic)
        $hasTransactions = \App\Models\Transaksi::where('id_jenis_tiket', $id)
            ->where('status', 'paid')
            ->exists();

        if ($hasTransactions) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete ticket type with existing paid transactions'
            ], 400);
        }

        $tiket->delete();

        return response()->json([
            'success' => true,
            'message' => 'Jenis tiket berhasil dihapus'
        ]);
    }

    /**
     * GET /api/jenis-tiket/event/{eventId}
     * Get all ticket types for specific event
     * Public access (untuk frontend booking)
     */
    public function getByEvent($eventId)
    {
        $event = Event::find($eventId);
        
        if (!$event) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found'
            ], 404);
        }

        $jenisTiket = JenisTiket::where('id_event', $eventId)
            ->with('event')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'event' => [
                    'id_event' => $event->id_event,
                    'nama_event' => $event->nama_event,
                    'lokasi' => $event->lokasi,
                    'start_time' => $event->start_time,
                ],
                'jenis_tiket' => $jenisTiket
            ]
        ]);
    }

    /**
     * GET /api/jenis-tiket/{id}/available
     * Check ketersediaan kuota tiket
     */
    public function checkAvailability($id)
    {
        $tiket = JenisTiket::find($id);
        
        if (!$tiket) {
            return response()->json([
                'success' => false,
                'message' => 'Jenis tiket tidak ditemukan'
            ], 404);
        }

        // Hitung tiket terjual
        $sold = \App\Models\Transaksi::where('id_jenis_tiket', $id)
            ->where('status', 'paid')
            ->sum('jumlah_tiket');

        $available = $tiket->kuota - $sold;

        return response()->json([
            'success' => true,
            'data' => [
                'id_jenis_tiket' => $tiket->id_jenis_tiket,
                'nama_tiket' => $tiket->nama_tiket,
                'harga' => $tiket->harga,
                'kuota_total' => $tiket->kuota,
                'terjual' => $sold,
                'tersedia' => max(0, $available),
                'is_available' => $available > 0,
            ]
        ]);
    }
}