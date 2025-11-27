<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Midtrans\Config;
use Midtrans\Notification;
use App\Models\Transaksi;
use App\Models\Tiket;
use App\Models\JenisTiket;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class MidtransCallbackController extends Controller
{
    public function __construct()
    {
        // Konfigurasi Midtrans
        Config::$serverKey = env('MIDTRANS_SERVER_KEY');
        Config::$isProduction = env('MIDTRANS_IS_PRODUCTION', false);
        Config::$isSanitized = true;
        Config::$is3ds = true;
    }

    /**
     * Handle Midtrans webhook notification
     */
    public function handleNotification(Request $request)
    {
        try {
            // ✅ Terima notifikasi dari Midtrans
            $notification = new Notification();

            $orderId = $notification->order_id;
            $transactionStatus = $notification->transaction_status;
            $fraudStatus = $notification->fraud_status;
            $paymentType = $notification->payment_type;
            $transactionTime = $notification->transaction_time;

            // ✅ Log untuk debugging
            Log::info('Midtrans Notification Received', [
                'order_id' => $orderId,
                'transaction_status' => $transactionStatus,
                'fraud_status' => $fraudStatus,
                'payment_type' => $paymentType,
            ]);

            // ✅ Cari transaksi di database
            $transaksi = Transaksi::where('order_id', $orderId)->first();

            if (!$transaksi) {
                Log::error('Transaction not found: ' . $orderId);
                return response()->json([
                    'success' => false,
                    'message' => 'Transaksi tidak ditemukan',
                ], 404);
            }

            // ✅ Proses berdasarkan status pembayaran
            DB::beginTransaction();

            try {
                if ($transactionStatus == 'capture') {
                    // Untuk credit card
                    if ($fraudStatus == 'accept') {
                        $this->handleSuccessPayment($transaksi, $paymentType, $transactionTime);
                    }
                } elseif ($transactionStatus == 'settlement') {
                    // Pembayaran berhasil
                    $this->handleSuccessPayment($transaksi, $paymentType, $transactionTime);
                } elseif ($transactionStatus == 'pending') {
                    // Pembayaran pending
                    $transaksi->update([
                        'status' => 'pending',
                        'payment_type' => $paymentType,
                        'transaction_time' => $transactionTime,
                    ]);
                } elseif ($transactionStatus == 'deny' || $transactionStatus == 'expire' || $transactionStatus == 'cancel') {
                    // Pembayaran ditolak/expired/dibatalkan
                    $transaksi->update([
                        'status' => 'failed',
                        'payment_type' => $paymentType,
                        'transaction_time' => $transactionTime,
                    ]);
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Notifikasi berhasil diproses',
                ]);

            } catch (Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (Exception $e) {
            Log::error('Midtrans Callback Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memproses notifikasi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle successful payment
     */
    private function handleSuccessPayment($transaksi, $paymentType, $transactionTime)
    {
        // ✅ Update status transaksi menjadi paid
        $transaksi->update([
            'status' => 'paid',
            'payment_type' => $paymentType,
            'transaction_time' => $transactionTime,
        ]);

        // ✅ Generate tiket otomatis
        $this->generateTickets($transaksi);

        // ✅ Kurangi kuota tiket
        $this->updateTicketQuota($transaksi);

        Log::info('Payment Success & Tickets Generated', [
            'order_id' => $transaksi->order_id,
            'jumlah_tiket' => $transaksi->jumlah_tiket,
        ]);
    }

    /**
     * Generate tickets automatically
     */
    private function generateTickets($transaksi)
    {
        $tickets = [];

        for ($i = 0; $i < $transaksi->jumlah_tiket; $i++) {
            // ✅ Generate QR Code unik untuk setiap tiket
            $qrCode = 'TKT-' . $transaksi->order_id . '-' . ($i + 1) . '-' . strtoupper(substr(md5(uniqid()), 0, 8));

            $tickets[] = [
                'id_transaksi' => $transaksi->id_transaksi,
                'qr_code' => $qrCode,
                'status' => 'aktif',
                'kehadiran' => 'belum_hadir',
                'created_at' => Carbon::now(),
            ];
        }

        // ✅ Insert semua tiket sekaligus (batch insert)
        Tiket::insert($tickets);
    }

    /**
     * Update ticket quota
     */
    private function updateTicketQuota($transaksi)
    {
        $jenisTiket = JenisTiket::find($transaksi->id_jenis_tiket);
        
        if ($jenisTiket) {
            $jenisTiket->decrement('kuota', $transaksi->jumlah_tiket);
        }
    }

    /**
     * Manual check payment status (for testing)
     */
    public function checkPaymentStatus($orderId)
    {
        try {
            $transaksi = Transaksi::where('order_id', $orderId)->first();

            if (!$transaksi) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaksi tidak ditemukan',
                ], 404);
            }

            // ✅ Cek status dari Midtrans
            /** @var object $status */
            $status = \Midtrans\Transaction::status($orderId);

            return response()->json([
                'success' => true,
                'data' => [
                    'order_id' => $orderId,
                    'transaction_status' => $status->transaction_status ?? null,
                    'payment_type' => $status->payment_type ?? null,
                    'gross_amount' => $status->gross_amount ?? null,
                    'transaction_time' => $status->transaction_time ?? null,
                    'local_status' => $transaksi->status,
                ],
            ]);

        } catch (Exception $e) {
            Log::error('Check Payment Status Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal memeriksa status pembayaran',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}