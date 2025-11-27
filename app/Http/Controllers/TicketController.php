<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Tiket;
use App\Models\Transaksi;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class TicketController extends Controller
{
    /**
     * Get all tickets (with filters)
     * Admin: Lihat semua
     * EO: Lihat tiket dari event miliknya
     * Panitia: Lihat tiket dari event yang dia handle
     * User: Lihat tiket miliknya sendiri
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $query = Tiket::with(['transaksi.user', 'transaksi.jenisTiket.event']);

            // ✅ RBAC: Filter based on role
            if ($user->hasRole('User') && !$user->hasAnyRole(['Admin', 'EO', 'Panitia'])) {
                // User hanya bisa lihat tiketnya sendiri
                $query->whereHas('transaksi', function ($q) use ($user) {
                    $q->where('id_user', $user->id_user);
                });
            } elseif ($user->hasRole('EO') && !$user->hasRole('Admin')) {
                // EO hanya bisa lihat tiket dari eventnya
                $query->whereHas('transaksi.jenisTiket.event', function ($q) use ($user) {
                    $q->where('id_user', $user->id_user);
                });
            }
            // Admin & Panitia bisa lihat semua

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filter by kehadiran
            if ($request->has('kehadiran')) {
                $query->where('kehadiran', $request->kehadiran);
            }

            // Filter by event
            if ($request->has('id_event')) {
                $query->whereHas('transaksi.jenisTiket', function ($q) use ($request) {
                    $q->where('id_event', $request->id_event);
                });
            }

            // Filter by user (Admin only)
            if ($request->has('id_user') && $user->hasRole('Admin')) {
                $query->whereHas('transaksi', function ($q) use ($request) {
                    $q->where('id_user', $request->id_user);
                });
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            $tickets = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Data tiket berhasil diambil',
                'data' => $tickets,
            ]);
        } catch (Exception $e) {
            Log::error('Get Tickets Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data tiket',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get ticket detail by ID
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();
            $ticket = Tiket::with([
                'transaksi.user',
                'transaksi.jenisTiket.event'
            ])->findOrFail($id);

            // ✅ OWNERSHIP CHECK: User hanya bisa lihat tiketnya sendiri
            if ($user->hasRole('User') && !$user->hasAnyRole(['Admin', 'EO', 'Panitia'])) {
                if ($ticket->transaksi->id_user !== $user->id_user) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You can only view your own tickets'
                    ], 403);
                }
            }

            // ✅ EO: Hanya bisa lihat tiket dari eventnya
            if ($user->hasRole('EO') && !$user->hasRole('Admin')) {
                if ($ticket->transaksi->jenisTiket->event->id_user !== $user->id_user) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You can only view tickets from your events'
                    ], 403);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Detail tiket berhasil diambil',
                'data' => [
                    'ticket' => $ticket,
                    'detail' => $ticket->getTicketDetail(),
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Get Ticket Detail Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Tiket tidak ditemukan',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Get tickets by event
     * EO: Hanya dari eventnya
     * Admin/Panitia: Semua event
     */
    public function getEventTickets(Request $request, $eventId)
    {
        try {
            $user = $request->user();

            // ✅ EO: Check ownership
            if ($user->hasRole('EO') && !$user->hasRole('Admin')) {
                $event = \App\Models\Event::find($eventId);
                if (!$event || $event->id_user !== $user->id_user) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You can only view tickets from your own events'
                    ], 403);
                }
            }

            $query = Tiket::with(['transaksi.user', 'transaksi.jenisTiket'])
                ->whereHas('transaksi', function ($q) use ($eventId) {
                    $q->where('status', 'paid')
                        ->whereHas('jenisTiket', function ($q2) use ($eventId) {
                            $q2->where('id_event', $eventId);
                        });
                });

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filter by kehadiran
            if ($request->has('kehadiran')) {
                $query->where('kehadiran', $request->kehadiran);
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            $tickets = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Tiket event berhasil diambil',
                'data' => $tickets,
            ]);
        } catch (Exception $e) {
            Log::error('Get Event Tickets Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil tiket event',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user's tickets
     * User: Hanya miliknya
     * Admin: Bisa specify user_id
     */
    public function getUserTickets(Request $request, $userId)
    {
        try {
            $user = $request->user();

            // ✅ User hanya bisa lihat tiketnya sendiri
            if ($user->hasRole('User') && !$user->hasAnyRole(['Admin', 'EO'])) {
                if ($user->id_user != $userId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You can only view your own tickets'
                    ], 403);
                }
            }

            $query = Tiket::with(['transaksi.jenisTiket.event'])
                ->whereHas('transaksi', function ($q) use ($userId) {
                    $q->where('id_user', $userId)
                        ->where(function ($s) {
                            $s->where('status', 'paid')
                                ->orWhere('status', 'free');
                        });
                });

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filter by upcoming events only
            if ($request->has('upcoming') && $request->upcoming == 'true') {
                $query->whereHas('transaksi.jenisTiket.event', function ($q) {
                    $q->where('start_time', '>', Carbon::now());
                });
            }

            $query->orderBy('created_at', 'desc');

            $perPage = $request->get('per_page', 10);
            $tickets = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Tiket user berhasil diambil',
                'data' => $tickets,
            ]);
        } catch (Exception $e) {
            Log::error('Get User Tickets Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil tiket user',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get my tickets (current user)
     * Shortcut untuk getUserTickets dengan user saat ini
     */
    public function myTickets(Request $request)
    {
        return $this->getUserTickets($request, $request->user()->id_user);
    }

    /**
     * Get ticket by QR code
     * Panitia & Admin: Untuk scanning
     * ⚠️ Middleware 'permission:tiket.verify' di routes
     */
    public function getByQrCode($qrCode)
    {
        try {
            $ticket = Tiket::with([
                'transaksi.user',
                'transaksi.jenisTiket.event'
            ])->where('qr_code', $qrCode)->firstOrFail();

            return response()->json([
                'success' => true,
                'message' => 'Detail tiket berhasil diambil',
                'data' => [
                    'ticket' => $ticket,
                    'detail' => $ticket->getTicketDetail(),
                    'can_check_in' => $ticket->canBeUsed(),
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Get Ticket by QR Code Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'QR Code tidak valid',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Validate ticket (check sebelum scan)
     * Panitia & Admin only
     * ⚠️ Middleware 'permission:tiket.verify' di routes
     */
    public function validateTicket(Request $request)
    {
        try {
            $validator = app('validator')->make($request->all(), [
                'qr_code' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $ticket = Tiket::with([
                'transaksi.user',
                'transaksi.jenisTiket.event'
            ])->where('qr_code', $request->qr_code)->first();

            if (!$ticket) {
                return response()->json([
                    'success' => false,
                    'message' => 'QR Code tidak valid',
                ], 404);
            }

            // Check if can be used
            if (!$ticket->canBeUsed()) {
                return response()->json([
                    'success' => false,
                    'message' => $ticket->getCheckInErrorMessage(),
                    'data' => $ticket->getTicketDetail(),
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Tiket valid dan dapat digunakan',
                'data' => [
                    'ticket' => $ticket,
                    'user' => $ticket->transaksi->user,
                    'event' => $ticket->transaksi->jenisTiket->event,
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Validate Ticket Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat validasi tiket',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Scan/Check-in ticket (SCAN QR)
     * Panitia & Admin only
     * ⚠ Middleware 'permission:tiket.scan' di routes
     */
    public function scan(Request $request)
    {
        DB::beginTransaction();

        try {
            $validator = app('validator')->make($request->all(), [
                'qr_code' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $ticket = Tiket::with([
                'transaksi.user',
                'transaksi.jenisTiket.event'
            ])->where('qr_code', $request->qr_code)->first();

            if (!$ticket) {
                return response()->json([
                    'success' => false,
                    'message' => 'QR Code tidak valid',
                ], 404);
            }

            // 🆕 CEK APAKAH SUDAH PERNAH DI-SCAN (dari scan_history)
            $existingScan = \App\Models\ScanHistory::where('id_tiket', $ticket->id_tiket)->first();

            if ($existingScan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tiket sudah pernah di-scan sebelumnya!',
                    'data' => [
                        'ticket' => $ticket,
                        'scan_history' => $existingScan,
                        'scanned_by' => $existingScan->user->nama ?? 'Unknown',
                        'scanned_at' => $existingScan->scan_time->format('d M Y H:i'),
                    ],
                ], 409);
            }

            // Perform check-in
            $result = $ticket->checkIn();

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                    'data' => $ticket->getTicketDetail(),
                ], 400);
            }

            // 🆕 SIMPAN KE SCAN HISTORY
            $scanHistory = \App\Models\ScanHistory::create([
                'id_tiket' => $ticket->id_tiket,
                'id_user' => $request->user()->id_user,
                'scan_time' => carbon::now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Check-in berhasil!',
                'data' => [
                    'ticket' => $ticket->fresh(),
                    'user' => $ticket->transaksi->user,
                    'event' => $ticket->transaksi->jenisTiket->event,
                    'jenis_tiket' => $ticket->transaksi->jenisTiket,
                    'checked_in_at' => Carbon::now()->format('d M Y H:i'),
                    'scanned_by' => $request->user()->nama,
                    'scan_history' => $scanHistory, // 🆕 Return scan history
                ],
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Check-in Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat check-in',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Alias untuk scan (backward compatibility)
     */
    public function checkIn(Request $request)
    {
        return $this->scan($request);
    }

    /**
     * Verify tiket (check status tanpa scan)
     * Panitia & Admin only
     * ⚠️ Middleware 'permission:tiket.verify' di routes
     */
    public function verify(Request $request, $qrCode)
    {
        return $this->getByQrCode($qrCode);
    }

    /**
     * Get scan history
     * Panitia: Lihat history scannya sendiri
     * Admin: Lihat semua
     */
    public function scanHistory(Request $request)
    {
        try {
            $user = $request->user();

            // Implementasi tergantung apakah Anda punya model Pengecekan
            // Untuk sementara, return tiket yang sudah di-scan

            $query = Tiket::with(['transaksi.user', 'transaksi.jenisTiket.event'])
                ->where('kehadiran', 'hadir');

            // Panitia hanya lihat scan miliknya (jika ada relasi)
            // if ($user->hasRole('Panitia') && !$user->hasRole('Admin')) {
            //     $query->whereHas('pengecekan', function($q) use ($user) {
            //         $q->where('id_user', $user->id_user);
            //     });
            // }

            $tickets = $query->orderBy('updated_at', 'desc')->paginate(20);

            return response()->json([
                'success' => true,
                'message' => 'Scan history berhasil diambil',
                'data' => $tickets
            ]);
        } catch (Exception $e) {
            Log::error('Get Scan History Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil scan history',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cancel ticket
     * User: Hanya tiketnya sendiri
     * Admin: Semua tiket
     */
    public function cancel(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $user = $request->user();
            $ticket = Tiket::findOrFail($id);

            // ✅ OWNERSHIP CHECK
            if ($user->hasRole('User') && !$user->hasRole('Admin')) {
                if ($ticket->transaksi->id_user !== $user->id_user) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You can only cancel your own tickets'
                    ], 403);
                }
            }

            $result = $ticket->cancel();

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                ], 400);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => $ticket->fresh(),
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Cancel Ticket Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal membatalkan tiket',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get ticket statistics for event
     * EO: Hanya eventnya
     * Admin: Semua event
     */
    public function getEventStatistics(Request $request, $eventId)
    {
        try {
            $user = $request->user();

            // ✅ EO: Check ownership
            if ($user->hasRole('EO') && !$user->hasRole('Admin')) {
                $event = \App\Models\Event::find($eventId);
                if (!$event || $event->id_user !== $user->id_user) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You can only view statistics from your own events'
                    ], 403);
                }
            }

            $stats = [
                'total_tickets' => Tiket::whereHas('transaksi', function ($q) use ($eventId) {
                    $q->where('status', 'paid')
                        ->whereHas('jenisTiket', function ($q2) use ($eventId) {
                            $q2->where('id_event', $eventId);
                        });
                })->count(),

                'active_tickets' => Tiket::whereHas('transaksi', function ($q) use ($eventId) {
                    $q->where('status', 'paid')
                        ->whereHas('jenisTiket', function ($q2) use ($eventId) {
                            $q2->where('id_event', $eventId);
                        });
                })->aktif()->count(),

                'used_tickets' => Tiket::whereHas('transaksi', function ($q) use ($eventId) {
                    $q->where('status', 'paid')
                        ->whereHas('jenisTiket', function ($q2) use ($eventId) {
                            $q2->where('id_event', $eventId);
                        });
                })->digunakan()->count(),

                'checked_in' => Tiket::whereHas('transaksi', function ($q) use ($eventId) {
                    $q->where('status', 'paid')
                        ->whereHas('jenisTiket', function ($q2) use ($eventId) {
                            $q2->where('id_event', $eventId);
                        });
                })->hadir()->count(),

                'not_checked_in' => Tiket::whereHas('transaksi', function ($q) use ($eventId) {
                    $q->where('status', 'paid')
                        ->whereHas('jenisTiket', function ($q2) use ($eventId) {
                            $q2->where('id_event', $eventId);
                        });
                })->belumHadir()->count(),
            ];

            // Calculate percentage
            if ($stats['total_tickets'] > 0) {
                $stats['check_in_percentage'] = round(($stats['checked_in'] / $stats['total_tickets']) * 100, 2);
            } else {
                $stats['check_in_percentage'] = 0;
            }

            return response()->json([
                'success' => true,
                'message' => 'Statistik tiket berhasil diambil',
                'data' => $stats,
            ]);
        } catch (Exception $e) {
            Log::error('Get Event Statistics Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil statistik',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
