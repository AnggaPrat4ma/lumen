<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JenisTiket extends Model
{
    protected $table = 'jenis_tiket';
    protected $primaryKey = 'id_jenis_tiket';
    public $timestamps = true;

    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'id_event',
        'nama_tiket',
        'harga',
        'kuota'
    ];

    protected $casts = [
        'id_jenis_tiket' => 'integer',
        'id_event' => 'integer',
        'harga' => 'decimal:2',
        'kuota' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relasi ke Event
     * JenisTiket belongs to Event
     */
    public function event()
    {
        return $this->belongsTo(Event::class, 'id_event', 'id_event');
    }

    /**
     * Relasi ke Transaksi
     * JenisTiket memiliki banyak transaksi
     */
    public function transaksis()
    {
        return $this->hasMany(Transaksi::class, 'id_jenis_tiket', 'id_jenis_tiket');
    }

    /**
     * Alias untuk transaksis (singular)
     */
    public function transaksi()
    {
        return $this->transaksis();
    }

    /**
     * Relasi ke Tiket melalui Transaksi
     */
    public function tikets()
    {
        return $this->hasManyThrough(
            Tiket::class,
            Transaksi::class,
            'id_jenis_tiket',   // FK di transaksi
            'id_transaksi',     // FK di tiket
            'id_jenis_tiket',   // Local key di jenis_tiket
            'id_transaksi'      // Local key di transaksi
        );
    }

    /**
     * Scope untuk tiket yang masih tersedia (kuota > 0)
     */
    public function scopeAvailable($query)
    {
        return $query->where('kuota', '>', 0);
    }

    /**
     * Scope untuk tiket yang sold out
     */
    public function scopeSoldOut($query)
    {
        return $query->where('kuota', '<=', 0);
    }

    /**
     * Scope untuk tiket gratis (harga = 0)
     */
    public function scopeFree($query)
    {
        return $query->where('harga', 0);
    }

    /**
     * Scope untuk tiket berbayar (harga > 0)
     */
    public function scopePaid($query)
    {
        return $query->where('harga', '>', 0);
    }

    /**
     * Check if ticket is available
     */
    public function isAvailable($quantity = 1)
    {
        return $this->kuota >= $quantity;
    }

    /**
     * Check if ticket is sold out
     */
    public function isSoldOut()
    {
        return $this->kuota <= 0;
    }

    /**
     * Check if ticket is free
     */
    public function isFree()
    {
        return $this->harga == 0;
    }

    /**
     * Get sold tickets count (paid transactions only)
     */
    public function getSoldCount()
    {
        return $this->transaksis()->where('status', 'paid')->sum('jumlah_tiket');
    }

    /**
     * Get pending tickets count
     */
    public function getPendingCount()
    {
        return $this->transaksis()->where('status', 'pending')->sum('jumlah_tiket');
    }

    /**
     * Get remaining tickets quota
     */
    public function getRemainingQuota()
    {
        return $this->kuota;
    }

    /**
     * Get availability percentage
     */
    public function getAvailabilityPercentage()
    {
        $sold = $this->getSoldCount();
        $total = $sold + $this->kuota;
        
        if ($total == 0) {
            return 0;
        }
        
        return round(($this->kuota / $total) * 100, 2);
    }

    /**
     * Decrease kuota when ticket sold
     */
    public function decreaseQuota($quantity)
    {
        if ($this->kuota >= $quantity) {
            $this->decrement('kuota', $quantity);
            return true;
        }
        return false;
    }

    /**
     * Increase kuota when transaction cancelled
     */
    public function increaseQuota($quantity)
    {
        $this->increment('kuota', $quantity);
        return true;
    }

    /**
     * Get formatted price in Rupiah
     */
    public function getFormattedPrice()
    {
        if ($this->harga == 0) {
            return 'Gratis';
        }
        return 'Rp ' . number_format($this->harga, 0, ',', '.');
    }

    /**
     * Get price with currency
     */
    public function getPriceAttribute()
    {
        return $this->getFormattedPrice();
    }

    /**
     * Get availability status
     */
    public function getAvailabilityStatus()
    {
        if ($this->isSoldOut()) {
            return 'Sold Out';
        } elseif ($this->kuota < 10) {
            return 'Hampir Habis';
        } else {
            return 'Tersedia';
        }
    }

    /**
     * Get availability badge color
     */
    public function getAvailabilityColor()
    {
        if ($this->isSoldOut()) {
            return 'danger';
        } elseif ($this->kuota < 10) {
            return 'warning';
        } else {
            return 'success';
        }
    }

    /**
     * Validate if can be purchased
     */
    public function canBePurchased($quantity)
    {
        // Check kuota
        if (!$this->isAvailable($quantity)) {
            return [
                'can_purchase' => false,
                'message' => 'Kuota tiket tidak mencukupi. Tersisa: ' . $this->kuota
            ];
        }

        // Check if event is not finished
        if ($this->event && $this->event->isFinished()) {
            return [
                'can_purchase' => false,
                'message' => 'Event sudah selesai'
            ];
        }

        return [
            'can_purchase' => true,
            'message' => 'Tiket dapat dibeli'
        ];
    }
}