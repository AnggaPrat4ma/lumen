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
                    $q->whereIn('status', ['paid', 'free'])
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
                    $q->whereIn('status', ['paid', 'free'])
                        ->whereHas('jenisTiket', function ($q2) use ($eventId) {
                            $q2->where('id_event', $eventId);
                        });
                })->count(),

                'active_tickets' => Tiket::whereHas('transaksi', function ($q) use ($eventId) {
                    $q->whereIn('status', ['paid', 'free'])
                        ->whereHas('jenisTiket', function ($q2) use ($eventId) {
                            $q2->where('id_event', $eventId);
                        });
                })->aktif()->count(),

                'used_tickets' => Tiket::whereHas('transaksi', function ($q) use ($eventId) {
                    $q->whereIn('status', ['paid', 'free'])
                        ->whereHas('jenisTiket', function ($q2) use ($eventId) {
                            $q2->where('id_event', $eventId);
                        });
                })->digunakan()->count(),

                'checked_in' => Tiket::whereHas('transaksi', function ($q) use ($eventId) {
                    $q->whereIn('status', ['paid', 'free'])
                        ->whereHas('jenisTiket', function ($q2) use ($eventId) {
                            $q2->where('id_event', $eventId);
                        });
                })->hadir()->count(),

                'not_checked_in' => Tiket::whereHas('transaksi', function ($q) use ($eventId) {
                    $q->whereIn('status', ['paid', 'free'])
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

    /**
 * Download ticket as PDF (using mPDF - no GD required)
 * User: Hanya tiketnya sendiri
 * Admin/EO: Bisa download tiket dari event mereka
 */
public function downloadPdf(Request $request, $id)
{
    try {
        $user = $request->user();
        $ticket = Tiket::with([
            'transaksi.user',
            'transaksi.jenisTiket.event'
        ])->findOrFail($id);

        // ✅ OWNERSHIP CHECK
        if ($user->hasRole('User') && !$user->hasAnyRole(['Admin', 'EO', 'Panitia'])) {
            if ($ticket->transaksi->id_user !== $user->id_user) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only download your own tickets'
                ], 403);
            }
        }

        // ✅ EO: Check ownership
        if ($user->hasRole('EO') && !$user->hasRole('Admin')) {
            if ($ticket->transaksi->jenisTiket->event->id_user !== $user->id_user) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only download tickets from your events'
                ], 403);
            }
        }

        // Get ticket details
        $event = $ticket->transaksi->jenisTiket->event;
        $jenisTiket = $ticket->transaksi->jenisTiket;
        $buyer = $ticket->transaksi->user;

        // Generate QR Code URL (mPDF will fetch it)
        $qrCodeUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($ticket->qr_code);

        // Generate HTML for PDF
        $html = $this->generateTicketHtml($ticket, $event, $jenisTiket, $buyer, $qrCodeUrl);

        // Generate PDF using mPDF
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 15,
            'margin_bottom' => 15,
        ]);

        $mpdf->WriteHTML($html);

        // Generate filename
        $filename = 'Ticket-' . $ticket->qr_code . '.pdf';

        // Output PDF as download
        return response($mpdf->Output($filename, 'S'), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');

    } catch (Exception $e) {
        Log::error('Download Ticket PDF Error: ' . $e->getMessage());

        return response()->json([
            'success' => false,
            'message' => 'Gagal mendownload tiket',
            'error' => $e->getMessage(),
        ], 500);
    }
}

/**
 * Generate HTML template for ticket PDF (mPDF version)
 */
private function generateTicketHtml($ticket, $event, $jenisTiket, $buyer, $qrCodeUrl)
{
    $statusLabels = [
        'aktif' => 'Aktif',
        'digunakan' => 'Sudah Digunakan',
        'dibatalkan' => 'Dibatalkan'
    ];

    $kehadiranLabels = [
        'belum_hadir' => 'Belum Check-in',
        'hadir' => 'Sudah Check-in'
    ];

    $statusLabel = $statusLabels[$ticket->status] ?? $ticket->status;
    $kehadiranLabel = $kehadiranLabels[$ticket->kehadiran] ?? $ticket->kehadiran;

    $startTime = Carbon::parse($event->start_time);
    $formattedDate = $startTime->locale('id')->isoFormat('dddd, D MMMM YYYY');
    $formattedTime = $startTime->format('H:i');

    $statusColor = [
        'aktif' => '#10b981',
        'digunakan' => '#64748b',
        'dibatalkan' => '#ef4444'
    ];

    $statusBgColor = [
        'aktif' => '#d1fae5',
        'digunakan' => '#e2e8f0',
        'dibatalkan' => '#fee2e2'
    ];

    $kehadiranColor = [
        'belum_hadir' => '#f59e0b',
        'hadir' => '#10b981'
    ];

    $kehadiranBgColor = [
        'belum_hadir' => '#fef3c7',
        'hadir' => '#d1fae5'
    ];

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Tiket - {$ticket->qr_code}</title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            padding: 20px;
            background: #ffffff;
        }
        .ticket-container {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 15px;
            padding: 30px;
            max-width: 700px;
            margin: 0 auto;
        }
        .header {
            text-align: center;
            border-bottom: 3px solid #8d0c0c;
            padding-bottom: 20px;
            margin-bottom: 25px;
        }
        .header h1 {
            color: #8d0c0c;
            font-size: 28px;
            margin: 0 0 8px 0;
        }
        .header .subtitle {
            color: #64748b;
            font-size: 14px;
            margin: 0;
        }
        .status-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 15px;
            font-weight: bold;
            font-size: 12px;
            margin-top: 12px;
            background: {$statusBgColor[$ticket->status]};
            color: {$statusColor[$ticket->status]};
            border: 2px solid {$statusColor[$ticket->status]};
        }
        .section {
            margin-bottom: 25px;
        }
        .section-title {
            font-size: 16px;
            color: #1e293b;
            font-weight: bold;
            margin-bottom: 12px;
            padding-bottom: 6px;
            border-bottom: 2px solid #e2e8f0;
        }
        .info-table {
            width: 100%;
            border-collapse: collapse;
        }
        .info-table td {
            padding: 10px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .info-label {
            width: 180px;
            color: #64748b;
            font-weight: 600;
            font-size: 13px;
        }
        .info-value {
            color: #1e293b;
            font-weight: 500;
            font-size: 13px;
        }
        .qr-section {
            text-align: center;
            background: #f8fafc;
            padding: 25px;
            border-radius: 12px;
            margin-top: 25px;
            border: 2px solid #e2e8f0;
        }
        .qr-code {
            width: 200px;
            height: 200px;
            margin: 15px auto;
            display: block;
            border: 3px solid #8d0c0c;
            border-radius: 10px;
        }
        .qr-instruction {
            color: #64748b;
            font-size: 12px;
            margin-top: 12px;
            font-style: italic;
        }
        .kehadiran-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 12px;
            font-weight: bold;
            font-size: 11px;
            background: {$kehadiranBgColor[$ticket->kehadiran]};
            color: {$kehadiranColor[$ticket->kehadiran]};
            border: 2px solid {$kehadiranColor[$ticket->kehadiran]};
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 15px;
            border-top: 2px solid #e2e8f0;
            color: #64748b;
            font-size: 10px;
        }
        .code-box {
            background: #f8fafc;
            padding: 8px 12px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 11px;
            color: #8d0c0c;
            font-weight: bold;
            border: 1px solid #e2e8f0;
            display: inline-block;
        }
        .highlight-box {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 12px;
            margin: 15px 0;
            border-radius: 6px;
        }
        .highlight-box p {
            margin: 0;
            color: #92400e;
            font-size: 12px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="ticket-container">
        <div class="header">
            <h1>🎫 E-TICKET</h1>
            <p class="subtitle">Tiket Digital Event</p>
            <div class="status-badge">{$statusLabel}</div>
        </div>

        <div class="section">
            <div class="section-title">📌 Informasi Event</div>
            <table class="info-table">
                <tr>
                    <td class="info-label">Nama Event</td>
                    <td class="info-value">{$event->nama_event}</td>
                </tr>
                <tr>
                    <td class="info-label">Lokasi</td>
                    <td class="info-value">{$event->lokasi}</td>
                </tr>
                <tr>
                    <td class="info-label">Tanggal</td>
                    <td class="info-value">{$formattedDate}</td>
                </tr>
                <tr>
                    <td class="info-label">Waktu</td>
                    <td class="info-value">{$formattedTime} WIB</td>
                </tr>
            </table>
        </div>

        <div class="section">
            <div class="section-title">🎫 Informasi Tiket</div>
            <table class="info-table">
                <tr>
                    <td class="info-label">Jenis Tiket</td>
                    <td class="info-value">{$jenisTiket->nama_tiket}</td>
                </tr>
                <tr>
                    <td class="info-label">Kode Tiket</td>
                    <td class="info-value">
                        <div class="code-box">{$ticket->qr_code}</div>
                    </td>
                </tr>
                <tr>
                    <td class="info-label">Status Kehadiran</td>
                    <td class="info-value">
                        <span class="kehadiran-badge">{$kehadiranLabel}</span>
                    </td>
                </tr>
            </table>
        </div>

        <div class="section">
            <div class="section-title">👤 Informasi Pemilik</div>
            <table class="info-table">
                <tr>
                    <td class="info-label">Nama</td>
                    <td class="info-value">{$buyer->nama}</td>
                </tr>
                <tr>
                    <td class="info-label">Email</td>
                    <td class="info-value">{$buyer->email}</td>
                </tr>
            </table>
        </div>

        <div class="highlight-box">
            <p>⚠️ PENTING: Simpan tiket ini dengan baik. Tunjukkan QR code saat check-in.</p>
        </div>

        <div class="qr-section">
            <div class="section-title">📱 QR Code Check-in</div>
            <img src="{$qrCodeUrl}" alt="QR Code" class="qr-code">
            <p class="qr-instruction">
                Scan QR code ini saat tiba di lokasi event
            </p>
        </div>

        <div class="footer">
            <p>Tiket digenerate pada: {$this->formatIndonesianDateTime(Carbon::now())}</p>
            <p style="margin-top: 5px;">Tiket yang hilang tidak dapat diganti. Harap simpan dengan baik.</p>
            <p style="margin-top: 5px;">Untuk bantuan, hubungi penyelenggara event.</p>
        </div>
    </div>
</body>
</html>
HTML;
}

/**
 * Format datetime to Indonesian
 */
private function formatIndonesianDateTime($datetime)
{
    return $datetime->locale('id')->isoFormat('dddd, D MMMM YYYY [pukul] HH:mm [WIB]');
}
}
