<?php

namespace App\Http\Controllers;

use App\Models\ScanHistory;
use App\Models\Tiket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\QueryException;

class ScanHistoryController extends Controller
{
    /**
     * Scan tiket (create new scan record)
     */
    public function scanTiket(Request $request)
    {
        // Validasi input
        $validator = Validator::make($request->all(), [
            'id_tiket' => 'required|integer|exists:pemkab_badung_tiket,id',
            'id_user' => 'required|integer|exists:pemkab_badung_user,id_user',
            'scan_time' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Cek apakah tiket sudah pernah di-scan
            $existingScan = ScanHistory::where('id_tiket', $request->id_tiket)->first();

            if ($existingScan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tiket sudah pernah di-scan sebelumnya!',
                    'data' => [
                        'scan_history' => $existingScan,
                        'scanned_by' => $existingScan->user->name ?? 'Unknown',
                        'scanned_at' => $existingScan->scan_time,
                    ]
                ], 409); // 409 Conflict
            }

            // Cek apakah tiket valid dan belum digunakan
            $tiket = Tiket::find($request->id_tiket);

            if (!$tiket) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tiket tidak ditemukan'
                ], 404);
            }

            // Validasi status tiket (opsional, sesuaikan dengan kebutuhan)
            if ($tiket->status !== 'aktif' && $tiket->status !== 'digunakan') {
                return response()->json([
                    'success' => false,
                    'message' => 'Status tiket tidak valid untuk di-scan'
                ], 400);
            }

            // Buat scan history baru
            $scanHistory = ScanHistory::create([
                'id_tiket' => $request->id_tiket,
                'id_user' => $request->id_user,
                'scan_time' => $request->scan_time,
            ]);

            // Update status tiket menjadi 'digunakan' (opsional)
            $tiket->update(['status' => 'digunakan']);

            // Load relasi
            $scanHistory->load(['tiket', 'user']);

            return response()->json([
                'success' => true,
                'message' => 'Tiket berhasil di-scan',
                'data' => $scanHistory
            ], 201);
        } catch (QueryException $e) {
            // Handle duplicate entry error
            if ($e->getCode() == 23000) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tiket sudah pernah di-scan sebelumnya!'
                ], 409);
            }

            return response()->json([
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get scan history by event ID
     */
    public function getScanHistoryByEvent($eventId)
    {
        try {
            $scanHistory = ScanHistory::with([
                'tiket.transaksi.user',
                'tiket.transaksi.jenisTiket',
                'user'
            ])
                ->whereHas('tiket.transaksi.jenisTiket', function ($q) use ($eventId) {
                    $q->where('id_event', $eventId);
                })
                ->orderBy('scan_time', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'total' => $scanHistory->count(),
                'data' => $scanHistory
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cek apakah tiket sudah pernah di-scan
     */
    public function checkTiketScan($idTiket)
    {
        try {
            $scanHistory = ScanHistory::with(['user', 'tiket'])
                ->where('id_tiket', $idTiket)
                ->first();

            if ($scanHistory) {
                return response()->json([
                    'success' => true,
                    'sudah_scan' => true,
                    'message' => 'Tiket sudah pernah di-scan',
                    'data' => $scanHistory
                ], 200);
            }

            return response()->json([
                'success' => true,
                'sudah_scan' => false,
                'message' => 'Tiket belum pernah di-scan',
                'data' => null
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get scan history by tiket ID
     */
    public function getScanHistoryByTiket($idTiket)
    {
        try {
            $scanHistory = ScanHistory::with(['user', 'tiket'])
                ->where('id_tiket', $idTiket)
                ->first();

            if (!$scanHistory) {
                return response()->json([
                    'success' => false,
                    'message' => 'Scan history tidak ditemukan'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $scanHistory
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all scan history by user (petugas)
     */
    public function getScanHistoryByUser($idUser)
    {
        try {
            $scanHistory = ScanHistory::with(['tiket', 'user'])
                ->where('id_user', $idUser)
                ->orderBy('scan_time', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'total' => $scanHistory->count(),
                'data' => $scanHistory
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all scan history dengan pagination
     */
    public function getAllScanHistory(Request $request)
    {
        try {
            $page = $request->query('page', 1);
            $limit = $request->query('limit', 20);

            $scanHistory = ScanHistory::with(['tiket', 'user'])
                ->orderBy('scan_time', 'desc')
                ->paginate($limit);

            return response()->json([
                'success' => true,
                'data' => $scanHistory->items(),
                'total' => $scanHistory->total(),
                'current_page' => $scanHistory->currentPage(),
                'per_page' => $scanHistory->perPage(),
                'last_page' => $scanHistory->lastPage(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get scan statistics
     */
    public function getStatistics(Request $request)
    {
        try {
            $today = Carbon::now()->startOfDay();
            $thisMonth = carbon::now()->startOfMonth();

            $stats = [
                'total_scan' => ScanHistory::count(),
                'scan_today' => ScanHistory::whereDate('scan_time', $today)->count(),
                'scan_this_month' => ScanHistory::where('scan_time', '>=', $thisMonth)->count(),
            ];

            // Top petugas scanner
            $topScanners = ScanHistory::selectRaw('id_user, COUNT(*) as total_scan')
                ->with('user:id_user,name')
                ->groupBy('id_user')
                ->orderByDesc('total_scan')
                ->limit(5)
                ->get();

            $stats['top_scanners'] = $topScanners;

            return response()->json([
                'success' => true,
                'data' => $stats
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete scan history (opsional, untuk admin)
     */
    public function deleteScanHistory($idScan)
    {
        try {
            $scanHistory = ScanHistory::find($idScan);

            if (!$scanHistory) {
                return response()->json([
                    'success' => false,
                    'message' => 'Scan history tidak ditemukan'
                ], 404);
            }

            // Update status tiket kembali (opsional)
            $tiket = Tiket::find($scanHistory->id_tiket);
            if ($tiket) {
                $tiket->update(['status' => 'aktif']);
            }

            $scanHistory->delete();

            return response()->json([
                'success' => true,
                'message' => 'Scan history berhasil dihapus'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }
}
