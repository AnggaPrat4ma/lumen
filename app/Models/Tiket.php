<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Tiket extends Model
{
    protected $table = 'tiket';
    protected $primaryKey = 'id_tiket';
    public $timestamps = false;

    protected $fillable = [
        'id_transaksi',
        'qr_code',
        'status',
        'kehadiran',
        'created_at',
    ];

    protected $casts = [
        'id_tiket' => 'integer',
        'id_transaksi' => 'integer',
        'status' => 'string',
        'kehadiran' => 'string',
        'created_at' => 'datetime',
    ];

    /**
     * Default values for attributes
     */
    protected $attributes = [
        'status' => 'aktif',
        'kehadiran' => 'belum_hadir',
    ];

    /**
     * Boot model - auto generate QR code
     */
    protected static function booted()
    {
        static::creating(function ($tiket) {
            // Set created_at if not set
            if (empty($tiket->created_at)) {
                $tiket->created_at = Carbon::now();
            }

            // Generate QR code if not set
            if (empty($tiket->qr_code)) {
                // Format: TKT-ORDERID-NUMBER-HASH
                $transaksi = Transaksi::find($tiket->id_transaksi);
                if ($transaksi) {
                    $count = Tiket::where('id_transaksi', $tiket->id_transaksi)->count() + 1;
                    $tiket->qr_code = 'TKT-' . $transaksi->order_id . '-' . $count . '-' . strtoupper(substr(md5(uniqid()), 0, 8));
                } else {
                    $tiket->qr_code = 'TKT-' . (string) Str::uuid();
                }
            }
        });
    }

    /**
     * Relasi ke Transaksi
     * Tiket belongs to Transaksi
     */
    public function transaksi()
    {
        return $this->belongsTo(Transaksi::class, 'id_transaksi', 'id_transaksi');
    }

    /**
     * Relasi ke Scan History
     * Tiket has one ScanHistory
     */
    public function scanHistory()
    {
        return $this->hasOne(\App\Models\ScanHistory::class, 'id_tiket', 'id_tiket');
    }

    /**
     * Get user through transaksi
     */
    public function user()
    {
        return $this->hasOneThrough(
            User::class,
            Transaksi::class,
            'id_transaksi',   // FK di transaksi
            'id_user',        // FK di user
            'id_transaksi',   // Local key di tiket
            'id_user'         // Local key di transaksi
        );
    }

    /**
     * Get jenis tiket through transaksi
     */
    public function jenisTiket()
    {
        return $this->hasOneThrough(
            JenisTiket::class,
            Transaksi::class,
            'id_transaksi',       // FK di transaksi
            'id_jenis_tiket',     // FK di jenis_tiket
            'id_transaksi',       // Local key di tiket
            'id_jenis_tiket'      // Local key di transaksi
        );
    }

    /**
     * Get event - helper method
     */
    public function getEvent()
    {
        if ($this->transaksi && $this->transaksi->jenisTiket) {
            return $this->transaksi->jenisTiket->event;
        }
        return null;
    }

    /**
     * Scope untuk tiket aktif
     */
    public function scopeAktif($query)
    {
        return $query->where('status', 'aktif');
    }

    /**
     * Scope untuk tiket yang sudah digunakan
     */
    public function scopeDigunakan($query)
    {
        return $query->where('status', 'digunakan');
    }

    /**
     * Scope untuk tiket yang dibatalkan
     */
    public function scopeDibatalkan($query)
    {
        return $query->where('status', 'dibatalkan');
    }

    /**
     * Scope untuk tiket yang sudah hadir
     */
    public function scopeHadir($query)
    {
        return $query->where('kehadiran', 'hadir');
    }

    /**
     * Scope untuk tiket yang belum hadir
     */
    public function scopeBelumHadir($query)
    {
        return $query->where('kehadiran', 'belum_hadir');
    }

    /**
     * Check if ticket is active
     */
    public function isAktif()
    {
        return $this->status === 'aktif';
    }

    /**
     * Check if ticket is used
     */
    public function isDigunakan()
    {
        return $this->status === 'digunakan';
    }

    /**
     * Check if ticket is cancelled
     */
    public function isDibatalkan()
    {
        return $this->status === 'dibatalkan';
    }

    /**
     * Check if user already attended
     */
    public function isHadir()
    {
        return $this->kehadiran === 'hadir';
    }

    /**
     * Check if ticket can be used for check-in
     */
    public function canBeUsed()
    {
        return $this->status === 'aktif' && $this->kehadiran === 'belum_hadir';
    }

    /**
     * Mark ticket as used and attended
     */
    public function markAsUsed()
    {
        $this->update([
            'status' => 'digunakan',
            'kehadiran' => 'hadir',
        ]);

        return $this;
    }

    /**
     * Check in ticket (alias for markAsUsed)
     */
    public function checkIn()
    {
        if (!$this->canBeUsed()) {
            return [
                'success' => false,
                'message' => $this->getCheckInErrorMessage()
            ];
        }

        $this->markAsUsed();

        return [
            'success' => true,
            'message' => 'Check-in berhasil'
        ];
    }

    /**
     * Get check-in error message
     */
    private function getCheckInErrorMessage()
    {
        if ($this->status === 'digunakan') {
            return 'Tiket sudah digunakan';
        } elseif ($this->status === 'dibatalkan') {
            return 'Tiket sudah dibatalkan';
        } elseif ($this->kehadiran === 'hadir') {
            return 'Sudah melakukan check-in';
        }
        return 'Tiket tidak valid';
    }

    /**
     * Cancel ticket
     */
    public function cancel()
    {
        if ($this->status === 'digunakan') {
            return [
                'success' => false,
                'message' => 'Tidak dapat membatalkan tiket yang sudah digunakan'
            ];
        }

        $this->update([
            'status' => 'dibatalkan',
        ]);

        return [
            'success' => true,
            'message' => 'Tiket berhasil dibatalkan'
        ];
    }

    /**
     * Get status label in Indonesian
     */
    public function getStatusLabel()
    {
        return [
            'aktif' => 'Aktif',
            'digunakan' => 'Sudah Digunakan',
            'dibatalkan' => 'Dibatalkan',
        ][$this->status] ?? $this->status;
    }

    /**
     * Get kehadiran label in Indonesian
     */
    public function getKehadiranLabel()
    {
        return [
            'belum_hadir' => 'Belum Hadir',
            'hadir' => 'Sudah Hadir',
        ][$this->kehadiran] ?? $this->kehadiran;
    }

    /**
     * Get status badge color
     */
    public function getStatusColor()
    {
        return [
            'aktif' => 'success',
            'digunakan' => 'secondary',
            'dibatalkan' => 'danger',
        ][$this->status] ?? 'secondary';
    }

    /**
     * Get kehadiran badge color
     */
    public function getKehadiranColor()
    {
        return [
            'belum_hadir' => 'warning',
            'hadir' => 'success',
        ][$this->kehadiran] ?? 'secondary';
    }

    /**
     * Generate QR code URL for display
     */
    public function getQrCodeUrl()
    {
        // Menggunakan QR code generator API
        return 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($this->qr_code);
    }

    /**
     * Get QR code as base64 image (alternative)
     */
    public function getQrCodeBase64()
    {
        $url = $this->getQrCodeUrl();
        $imageData = @file_get_contents($url);

        if ($imageData) {
            return 'data:image/png;base64,' . base64_encode($imageData);
        }

        return null;
    }

    /**
     * Get ticket detail for display
     */
    public function getTicketDetail()
    {
        return [
            'id_tiket' => $this->id_tiket,
            'qr_code' => $this->qr_code,
            'qr_code_url' => $this->getQrCodeUrl(),
            'status' => $this->status,
            'status_label' => $this->getStatusLabel(),
            'status_color' => $this->getStatusColor(),
            'kehadiran' => $this->kehadiran,
            'kehadiran_label' => $this->getKehadiranLabel(),
            'kehadiran_color' => $this->getKehadiranColor(),
            'can_be_used' => $this->canBeUsed(),
            'created_at' => $this->created_at ? $this->created_at->format('d M Y H:i') : null,
        ];
    }
}
