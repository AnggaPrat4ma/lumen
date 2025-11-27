<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Transaksi;
use App\Models\User;
use App\Models\JenisTiket;
use App\Models\Tiket;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TransaksiController extends Controller
{
    /**
     * Get all transactions (with RBAC filters)
     * User: Own transactions only
     * EO: Transactions from own events only
     * Admin: All transactions
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $query = Transaksi::with(['user', 'jenisTiket.event', 'tikets']);

            // ✅ RBAC: Filter based on role
            if ($user->hasRole('User') && !$user->hasAnyRole(['Admin', 'EO'])) {
                // User hanya lihat transaksi sendiri
                $query->where('id_user', $user->id_user);
            } elseif ($user->hasRole('EO') && !$user->hasRole('Admin')) {
                // EO hanya lihat transaksi dari eventnya
                $query->whereHas('jenisTiket.event', function($q) use ($user) {
                    $q->where('id_user', $user->id_user);
                });
            }
            // Admin bisa lihat semua

            // Filter by user (Admin only)
            if ($request->has('id_user') && $user->hasRole('Admin')) {
                $query->where('id_user', $request->id_user);
            }

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filter by date range
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->whereBetween('waktu_transaksi', [
                    $request->start_date,
                    $request->end_date
                ]);
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'waktu_transaksi');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 10);
            $transaksi = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Data transaksi berhasil diambil',
                'data' => $transaksi,
            ]);

        } catch (Exception $e) {
            Log::error('Get Transactions Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data transaksi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get transaction detail by ID (with ownership check)
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();
            $transaksi = Transaksi::with([
                'user',
                'jenisTiket.event',
                'tikets'
            ])->findOrFail($id);

            // ✅ OWNERSHIP CHECK
            if ($user->hasRole('User') && !$user->hasAnyRole(['Admin', 'EO'])) {
                if ($transaksi->id_user !== $user->id_user) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You can only view your own transactions'
                    ], 403);
                }
            }

            // ✅ EO: Check event ownership
            if ($user->hasRole('EO') && !$user->hasRole('Admin')) {
                if ($transaksi->jenisTiket->event->id_user !== $user->id_user) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You can only view transactions from your own events'
                    ], 403);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Detail transaksi berhasil diambil',
                'data' => $transaksi,
            ]);

        } catch (Exception $e) {
            Log::error('Get Transaction Detail Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Transaksi tidak ditemukan',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * ✅ NEW METHOD: Register for FREE ticket
     * POST /api/transaksi/register-free
     * 
     * Flow:
     * 1. Validate jenis_tiket (harus harga = 0)
     * 2. Check kuota
     * 3. Create transaksi with status 'free'
     * 4. Auto-generate tikets
     * 5. Decrease kuota
     */
    public function registerFree(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_jenis_tiket' => 'required|exists:jenis_tiket,id_jenis_tiket',
            'jumlah_tiket' => 'required|integer|min:1|max:5', // Max 5 tiket gratis per user
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $user = $request->user();
            $jenisTiket = JenisTiket::with('event')->findOrFail($request->id_jenis_tiket);

            // ✅ CRITICAL: Validate that ticket is FREE
            if ($jenisTiket->harga > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tiket ini berbayar. Gunakan flow pembayaran normal.'
                ], 400);
            }

            // ✅ Check if event is free
            if ($jenisTiket->event->berbayar) {
                return response()->json([
                    'success' => false,
                    'message' => 'Event ini berbayar. Gunakan flow pembayaran normal.'
                ], 400);
            }

            // ✅ Check if user already registered for this event
            $alreadyRegistered = Transaksi::where('id_user', $user->id_user)
                ->whereHas('jenisTiket', function($q) use ($jenisTiket) {
                    $q->where('id_event', $jenisTiket->id_event);
                })
                ->whereIn('status', ['free', 'paid'])
                ->exists();

            if ($alreadyRegistered) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda sudah terdaftar untuk event ini'
                ], 400);
            }

            // ✅ Check kuota availability
            if (!$jenisTiket->isAvailable($request->jumlah_tiket)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kuota tidak mencukupi. Tersisa: ' . $jenisTiket->kuota
                ], 400);
            }

            // ✅ Check if event has not finished
            if ($jenisTiket->event->isFinished()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Event sudah selesai'
                ], 400);
            }

            // ✅ Generate order_id
            $orderId = 'FREE-' . time() . '-' . strtoupper(\Illuminate\Support\Str::random(8));

            // ✅ Create transaksi with status 'free'
            $transaksi = Transaksi::create([
                'id_user' => $user->id_user,
                'id_jenis_tiket' => $jenisTiket->id_jenis_tiket,
                'order_id' => $orderId,
                'jumlah_tiket' => $request->jumlah_tiket,
                'total_harga' => 0, // Free!
                'status' => 'free', // ✅ NEW STATUS
                'payment_type' => 'free',
                'waktu_transaksi' => Carbon::now(),
                'transaction_time' => Carbon::now(),
            ]);

            // ✅ Auto-generate tikets
            $generatedTickets = [];
            for ($i = 0; $i < $request->jumlah_tiket; $i++) {
                $qrCode = 'TKT-' . $transaksi->id_transaksi . '-' . ($i + 1) . '-' . uniqid();
                
                $tiket = Tiket::create([
                    'id_transaksi' => $transaksi->id_transaksi,
                    'qr_code' => $qrCode,
                    'status' => 'aktif',
                    'kehadiran' => 'belum_hadir',
                ]);

                $generatedTickets[] = $tiket;
            }

            // ✅ Decrease kuota
            $jenisTiket->decreaseQuota($request->jumlah_tiket);

            DB::commit();

            Log::info('FREE TICKET REGISTERED', [
                'user_id' => $user->id_user,
                'event_id' => $jenisTiket->id_event,
                'transaksi_id' => $transaksi->id_transaksi,
                'jumlah_tiket' => $request->jumlah_tiket
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Pendaftaran berhasil! Tiket gratis Anda telah dibuat.',
                'data' => [
                    'transaksi' => $transaksi->load(['jenisTiket.event']),
                    'tikets' => $generatedTickets,
                    'event' => [
                        'id_event' => $jenisTiket->event->id_event,
                        'nama_event' => $jenisTiket->event->nama_event,
                        'lokasi' => $jenisTiket->event->lokasi,
                        'start_time' => $jenisTiket->event->start_time,
                        'end_time' => $jenisTiket->event->end_time,
                    ]
                ]
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('FREE TICKET REGISTRATION ERROR: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal melakukan pendaftaran',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ✅ MODIFIED: Original store method - sekarang check harga dulu
     * POST /api/transaksi
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_jenis_tiket' => 'required|exists:jenis_tiket,id_jenis_tiket',
            'jumlah_tiket' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $user = $request->user();
            $jenisTiket = JenisTiket::with('event')->findOrFail($request->id_jenis_tiket);

            // ✅ REDIRECT: If ticket is free, use registerFree instead
            if ($jenisTiket->harga == 0) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Tiket gratis. Gunakan endpoint /api/transaksi/register-free',
                    'redirect' => '/api/transaksi/register-free'
                ], 400);
            }

            // ✅ Check kuota
            if (!$jenisTiket->isAvailable($request->jumlah_tiket)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kuota tidak mencukupi. Tersisa: ' . $jenisTiket->kuota
                ], 400);
            }

            // Generate order ID
            $orderId = 'TRX-' . time() . '-' . $user->id_user;

            // Calculate total
            $totalHarga = $jenisTiket->harga * $request->jumlah_tiket;

            // Create transaksi (status: pending, menunggu payment)
            $transaksi = Transaksi::create([
                'id_user' => $user->id_user,
                'id_jenis_tiket' => $jenisTiket->id_jenis_tiket,
                'order_id' => $orderId,
                'jumlah_tiket' => $request->jumlah_tiket,
                'total_harga' => $totalHarga,
                'status' => 'pending',
                'waktu_transaksi' => Carbon::now(),
            ]);

            // ✅ Continue to Midtrans payment flow...
            // (Your existing Midtrans integration here)

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Transaksi berhasil dibuat. Lanjutkan ke pembayaran.',
                'data' => $transaksi->load(['jenisTiket.event'])
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Create Transaction Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat transaksi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ✅ HELPER: Check if user can register for event
     * GET /api/transaksi/can-register/{eventId}
     */
    public function canRegister(Request $request, $eventId)
    {
        try {
            $user = $request->user();

            $alreadyRegistered = Transaksi::where('id_user', $user->id_user)
                ->whereHas('jenisTiket', function($q) use ($eventId) {
                    $q->where('id_event', $eventId);
                })
                ->whereIn('status', ['free', 'paid', 'pending'])
                ->exists();

            return response()->json([
                'success' => true,
                'data' => [
                    'can_register' => !$alreadyRegistered,
                    'message' => $alreadyRegistered 
                        ? 'Anda sudah terdaftar untuk event ini' 
                        : 'Anda dapat mendaftar'
                ]
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error checking registration status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve transaction (Admin only)
     * ⚠️ Middleware: permission:transaksi.approve
     */
    public function approve(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $transaksi = Transaksi::findOrFail($id);

            if ($transaksi->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending transactions can be approved'
                ], 400);
            }

            // Update status
            $transaksi->update(['status' => 'paid']);

            // Generate tickets
            for ($i = 0; $i < $transaksi->jumlah_tiket; $i++) {
                $qrCode = 'TKT-' . $transaksi->id_transaksi . '-' . ($i + 1) . '-' . uniqid();
                
                Tiket::create([
                    'id_transaksi' => $transaksi->id_transaksi,
                    'qr_code' => $qrCode,
                    'status' => 'active',
                    'kehadiran' => 'belum hadir',
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Transaction approved and tickets generated',
                'data' => $transaksi->fresh()->load('tikets')
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Approve Transaction Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve transaction',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reject transaction (Admin only)
     * ⚠️ Middleware: permission:transaksi.approve (same as approve)
     */
    public function reject(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $transaksi = Transaksi::findOrFail($id);

            if ($transaksi->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending transactions can be rejected'
                ], 400);
            }

            $transaksi->update(['status' => 'failed']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Transaction rejected',
                'data' => $transaksi
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Reject Transaction Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to reject transaction',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all transactions (Admin only)
     * ⚠️ Middleware: permission:transaksi.view-all
     */
    public function all(Request $request)
    {
        try {
            $query = Transaksi::with(['user', 'jenisTiket.event', 'tikets']);

            // Filters
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $query->orderBy('waktu_transaksi', 'desc');

            $perPage = $request->get('per_page', 15);
            $transaksi = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $transaksi
            ]);

        } catch (Exception $e) {
            Log::error('Get All Transactions Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to get transactions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get transaction by order_id
     */
    public function getByOrderId(Request $request, $orderId)
    {
        try {
            $user = $request->user();
            $transaksi = Transaksi::with([
                'user',
                'jenisTiket.event',
                'tikets'
            ])->where('order_id', $orderId)->firstOrFail();

            // Ownership check
            if ($user->hasRole('User') && !$user->hasAnyRole(['Admin', 'EO'])) {
                if ($transaksi->id_user !== $user->id_user) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized'
                    ], 403);
                }
            }

            return response()->json([
                'success' => true,
                'data' => $transaksi,
            ]);

        } catch (Exception $e) {
            Log::error('Get Transaction by Order ID Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Transaction not found',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Cancel transaction (User: own only)
     */
    public function cancel(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $user = $request->user();
            $transaksi = Transaksi::findOrFail($id);

            // ✅ OWNERSHIP CHECK
            if ($user->hasRole('User') && !$user->hasRole('Admin')) {
                if ($transaksi->id_user !== $user->id_user) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You can only cancel your own transactions'
                    ], 403);
                }
            }

            if ($transaksi->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending transactions can be cancelled'
                ], 400);
            }

            $transaksi->update(['status' => 'expired']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Transaction cancelled',
                'data' => $transaksi,
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Cancel Transaction Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel transaction',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get transaction statistics
     * EO: Own events only
     * Admin: All
     */
    public function getStatistics(Request $request)
    {
        try {
            $user = $request->user();
            $query = Transaksi::query();

            // ✅ RBAC Filter
            if ($user->hasRole('EO') && !$user->hasRole('Admin')) {
                $query->whereHas('jenisTiket.event', function($q) use ($user) {
                    $q->where('id_user', $user->id_user);
                });
            }

            // Filter by date range
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->whereBetween('waktu_transaksi', [
                    $request->start_date,
                    $request->end_date
                ]);
            }

            // Filter by event
            if ($request->has('id_event')) {
                $query->whereHas('jenisTiket', function ($q) use ($request) {
                    $q->where('id_event', $request->id_event);
                });
            }

            $statistics = [
                'total_transactions' => $query->count(),
                'total_revenue' => $query->where('status', 'paid')->sum('total_harga'),
                'pending_transactions' => (clone $query)->where('status', 'pending')->count(),
                'paid_transactions' => (clone $query)->where('status', 'paid')->count(),
                'failed_transactions' => (clone $query)->where('status', 'failed')->count(),
                'expired_transactions' => (clone $query)->where('status', 'expired')->count(),
                'total_tickets_sold' => (clone $query)->where('status', 'paid')->sum('jumlah_tiket'),
            ];

            return response()->json([
                'success' => true,
                'data' => $statistics,
            ]);

        } catch (Exception $e) {
            Log::error('Get Statistics Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to get statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete transaction (Admin only)
     */
    public function destroy(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $transaksi = Transaksi::findOrFail($id);

            if ($transaksi->status === 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete paid transaction'
                ], 400);
            }

            Tiket::where('id_transaksi', $id)->delete();
            $transaksi->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Transaction deleted successfully',
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Delete Transaction Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete transaction',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}