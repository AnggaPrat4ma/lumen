<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Midtrans\Config;
use Midtrans\Snap;
use App\Models\Transaksi;
use App\Models\JenisTiket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class MidtransController extends Controller
{
    public function __construct()
    {
        // ✅ Konfigurasi Midtrans di constructor
        Config::$serverKey = env('MIDTRANS_SERVER_KEY');
        Config::$isProduction = env('MIDTRANS_IS_PRODUCTION', false);
        Config::$isSanitized = true;
        Config::$is3ds = true;
    }

    public function createTransaction(Request $request)
    {
        DB::beginTransaction();
        
        try {
            // ✅ Validasi input
            $validator = app('validator')->make($request->all(), [
                'id_user' => 'required|integer|exists:user,id_user',
                'id_jenis_tiket' => 'required|integer|exists:jenis_tiket,id_jenis_tiket',
                'jumlah_tiket' => 'required|integer|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // ✅ Ambil data user dan jenis tiket dari database
            $user = User::findOrFail($request->id_user);
            $jenisTiket = JenisTiket::with('event')->findOrFail($request->id_jenis_tiket);

            // ✅ Cek ketersediaan kuota tiket
            if ($jenisTiket->kuota < $request->jumlah_tiket) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kuota tiket tidak mencukupi. Tersisa: ' . $jenisTiket->kuota,
                ], 400);
            }

            // ✅ Hitung total harga
            $totalHarga = $jenisTiket->harga * $request->jumlah_tiket;

            // ✅ Buat order_id unik
            $orderId = 'ORD-' . time() . '-' . Str::random(8);

            // ✅ Simpan transaksi ke database
            $transaksi = Transaksi::create([
                'id_user' => $request->id_user,
                'id_jenis_tiket' => $request->id_jenis_tiket,
                'jumlah_tiket' => $request->jumlah_tiket,
                'total_harga' => $totalHarga,
                'status' => 'pending',
                'payment_type' => null,
                'transaction_time' => null,
                'order_id' => $orderId,
                'waktu_transaksi' => Carbon::now(),
            ]);

            // ✅ Siapkan parameter untuk Midtrans
            $params = [
                'transaction_details' => [
                    'order_id' => $orderId,
                    'gross_amount' => (int) $totalHarga,
                ],
                'customer_details' => [
                    'first_name' => $user->nama,
                    'email' => $user->email,
                    'phone' => $user->phone ?? '',
                ],
                'item_details' => [[
                    'id' => $jenisTiket->id_jenis_tiket,
                    'price' => (int) $jenisTiket->harga,
                    'quantity' => $request->jumlah_tiket,
                    'name' => $jenisTiket->nama_tiket . ' - ' . ($jenisTiket->event->nama_event ?? 'Event'),
                ]],
                'callbacks' => [
                    'finish' => env('MIDTRANS_FINISH_URL', 'http://localhost:5173/profile'),
                ],
            ];

            // ✅ Buat transaksi Snap Midtrans
            $snapTransaction = Snap::createTransaction($params);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Transaksi berhasil dibuat',
                'data' => [
                    'id_transaksi' => $transaksi->id_transaksi,
                    'order_id' => $orderId,
                    'snap_token' => $snapTransaction->token,
                    'redirect_url' => $snapTransaction->redirect_url,
                    'total_harga' => $totalHarga,
                    'jumlah_tiket' => $request->jumlah_tiket,
                ],
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Midtrans Transaction Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat membuat transaksi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get transaction status from Midtrans
     */
    public function getTransactionStatus($orderId)
    {
        try {
            /** @var object $status */
            $status = \Midtrans\Transaction::status($orderId);
            
            return response()->json([
                'success' => true,
                'data' => $status,
            ]);
        } catch (Exception $e) {
            Log::error('Get Transaction Status Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil status transaksi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}