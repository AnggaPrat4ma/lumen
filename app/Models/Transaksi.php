<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Transaksi extends Model
{
    protected $table = 'transaksi';
    protected $primaryKey = 'id_transaksi';
    public $timestamps = false;

    protected $fillable = [
        'id_user',
        'id_jenis_tiket',
        'jumlah_tiket',
        'total_harga',
        'waktu_transaksi',
        'status',
        'order_id',
        'payment_type',
        'transaction_time',
    ];

    protected $casts = [
        'id_transaksi' => 'integer',
        'id_user' => 'integer',
        'id_jenis_tiket' => 'integer',
        'jumlah_tiket' => 'integer',
        'total_harga' => 'decimal:2',
        'waktu_transaksi' => 'datetime',
        'transaction_time' => 'datetime',
    ];

    protected $appends = ['total'];

    /**
     * Relasi ke User
     * Transaksi belongs to User
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'id_user', 'id_user');
    }

    /**
     * Relasi ke JenisTiket
     * Transaksi belongs to JenisTiket
     */
    public function jenisTiket()
    {
        return $this->belongsTo(JenisTiket::class, 'id_jenis_tiket', 'id_jenis_tiket');
    }

    /**
     * Relasi ke Tiket
     * Transaksi memiliki banyak tiket
     */
    public function tiket()
    {
        return $this->hasMany(Tiket::class, 'id_transaksi', 'id_transaksi');
    }

    /**
     * Alias plural untuk tiket
     */
    public function tikets()
    {
        return $this->tiket();
    }

    /**
     * Get event through jenis tiket
     */
    public function event()
    {
        return $this->hasOneThrough(
            Event::class,
            JenisTiket::class,
            'id_jenis_tiket',
            'id_event',
            'id_jenis_tiket',
            'id_event'
        );
    }

    /**
     * Hitung total harga otomatis
     */
    public function getTotalAttribute()
    {
        // Gunakan total_harga dari DB jika ada, jika tidak hitung manual
        return $this->total_harga ?: ($this->jenisTiket->harga ?? 0) * $this->jumlah_tiket;
    }

    /**
     * Scope untuk transaksi pending
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope untuk transaksi paid
     */
    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    /**
     * Scope untuk transaksi failed
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope untuk transaksi expired
     */
    public function scopeExpired($query)
    {
        return $query->where('status', 'expired');
    }

    /**
     * Check if transaction is paid
     */
    public function isPaid()
    {
        return $this->status === 'paid';
    }

    /**
     * Check if transaction is pending
     */
    public function isPending()
    {
        return $this->status === 'pending';
    }

    /**
     * Check if transaction is failed
     */
    public function isFailed()
    {
        return $this->status === 'failed';
    }

    /**
     * Check if transaction is expired
     */
    public function isExpired()
    {
        return $this->status === 'expired';
    }

    /**
     * Check if transaction can be cancelled
     */
    public function canBeCancelled()
    {
        return $this->status === 'pending';
    }

    /**
     * Check if transaction can be retried
     */
    public function canBeRetried()
    {
        return in_array($this->status, ['expired', 'failed']);
    }

    /**
     * Get formatted total price
     */
    public function getFormattedTotalHarga()
    {
        return 'Rp ' . number_format($this->total_harga, 0, ',', '.');
    }

    /**
     * ✅ NEW: Scope untuk transaksi gratis
     */
    public function scopeFree($query)
    {
        return $query->where('status', 'free');
    }

    /**
     * ✅ NEW: Check if transaction is free
     */
    public function isFree()
    {
        return $this->status === 'free';
    }

    /**
     * ✅ UPDATED: Get status label - tambah 'free'
     */
    public function getStatusLabel()
    {
        return [
            'pending' => 'Menunggu Pembayaran',
            'paid' => 'Berhasil',
            'failed' => 'Gagal',
            'expired' => 'Kadaluarsa',
            'free' => 'Gratis - Terdaftar', // ✅ NEW
        ][$this->status] ?? $this->status;
    }

    /**
     * ✅ UPDATED: Get status badge color - tambah 'free'
     */
    public function getStatusColor()
    {
        return [
            'pending' => 'warning',
            'paid' => 'success',
            'failed' => 'danger',
            'expired' => 'secondary',
            'free' => 'info', // ✅ NEW - biru untuk gratis
        ][$this->status] ?? 'secondary';
    }

    /**
     * ✅ NEW: Check if transaction is confirmed (paid or free)
     */
    public function isConfirmed()
    {
        return in_array($this->status, ['paid', 'free']);
    }

    /**
     * ✅ UPDATED: Get payment type label - tambah 'free'
     */
    public function getPaymentTypeLabel()
    {
        if (!$this->payment_type) {
            return '-';
        }

        if ($this->payment_type === 'free') {
            return 'Tiket Gratis';
        }

        return [
            'credit_card' => 'Kartu Kredit',
            'bank_transfer' => 'Transfer Bank',
            'echannel' => 'Mandiri Bill',
            'bca_va' => 'BCA Virtual Account',
            'bni_va' => 'BNI Virtual Account',
            'bri_va' => 'BRI Virtual Account',
            'permata_va' => 'Permata Virtual Account',
            'other_va' => 'Virtual Account',
            'gopay' => 'GoPay',
            'shopeepay' => 'ShopeePay',
            'qris' => 'QRIS',
            'cstore' => 'Convenience Store',
            'akulaku' => 'Akulaku',
        ][$this->payment_type] ?? ucwords(str_replace('_', ' ', $this->payment_type));
    }

    /**
     * Get transaction detail for display
     */
    public function getTransactionDetail()
    {
        return [
            'id_transaksi' => $this->id_transaksi,
            'order_id' => $this->order_id,
            'jumlah_tiket' => $this->jumlah_tiket,
            'total_harga' => $this->total_harga,
            'formatted_total' => $this->getFormattedTotalHarga(),
            'status' => $this->status,
            'status_label' => $this->getStatusLabel(),
            'status_color' => $this->getStatusColor(),
            'payment_type' => $this->payment_type,
            'payment_type_label' => $this->getPaymentTypeLabel(),
            'waktu_transaksi' => $this->waktu_transaksi ? $this->waktu_transaksi->format('d M Y H:i') : null,
            'transaction_time' => $this->transaction_time ? $this->transaction_time->format('d M Y H:i') : null,
            'can_cancel' => $this->canBeCancelled(),
            'can_retry' => $this->canBeRetried(),
        ];
    }

    /**
     * Generate order_id otomatis dan set waktu_transaksi
     */
    protected static function booted()
    {
        static::creating(function ($transaksi) {
            // Generate order_id jika kosong
            if (empty($transaksi->order_id)) {
                $transaksi->order_id = 'ORD-' . time() . '-' . strtoupper(Str::random(8));
            }

            // Set waktu_transaksi jika kosong
            if (empty($transaksi->waktu_transaksi)) {
                $transaksi->waktu_transaksi = Carbon::now();
            }

            // Set default status jika kosong
            if (empty($transaksi->status)) {
                $transaksi->status = 'pending';
            }
        });
    }
}