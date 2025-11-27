<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $table = 'event';
    protected $primaryKey = 'id_event';
    public $timestamps = false;

    protected $fillable = [
        'nama_event',
        'deskripsi',
        'banner',
        'lokasi',
        'start_time',
        'end_time',
        'berbayar',
    ];

    protected $casts = [
        'id_event' => 'integer',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'berbayar' => 'boolean',
    ];

    /**
     * Relasi ke JenisTiket
     * Event memiliki banyak jenis tiket
     */

    public function user()
    {
        return $this->belongsTo(User::class, 'id_user', 'id_user');
    }

    public function jenisTiket()
    {
        return $this->hasMany(JenisTiket::class, 'id_event', 'id_event');
    }

    /**
     * Alias untuk jenisTiket (plural)
     */
    public function jenisTikets()
    {
        return $this->jenisTiket();
    }

    /**
     * Relasi ke Transaksi melalui JenisTiket
     */
    public function transaksis()
    {
        return $this->hasManyThrough(
            Transaksi::class,
            JenisTiket::class,
            'id_event',         // FK di jenis_tiket
            'id_jenis_tiket',   // FK di transaksi
            'id_event',         // Local key di event
            'id_jenis_tiket'    // Local key di jenis_tiket
        );
    }

    /**
     * Many-to-Many: Event has many Users (owners)
     */
    public function users()
    {
        return $this->belongsToMany(
            User::class, 
            'user_has_event', 
            'id_event', 
            'id_user'
        )->withPivot('is_owner'); // ✅ Include pivot column
    }

    /**
     * Check if user is owner of this event
     */
    public function isOwnedBy($user)
    {
        return $this->users()
            ->where('user.id_user', $user->id_user)
            ->wherePivot('is_owner', 1)
            ->exists();
    }

    /**
     * Get primary owner(s)
     */
    public function owners()
    {
        return $this->users()->wherePivot('is_owner', 1);
    }

    /**
     * Get available ticket types (kuota > 0)
     */
    public function availableJenisTiket()
    {
        return $this->jenisTiket()->where('kuota', '>', 0);
    }

    /**
     * Scope untuk upcoming events
     */
    public function scopeUpcoming($query)
    {
        return $query->where('start_time', '>', Carbon::now());
    }

    /**
     * Scope untuk ongoing events
     */
    public function scopeOngoing($query)
    {
        return $query->where('start_time', '<=', Carbon::now())
            ->where('end_time', '>=', Carbon::now());
    }

    /**
     * Scope untuk past events
     */
    public function scopePast($query)
    {
        return $query->where('end_time', '<', Carbon::now());
    }

    /**
     * Scope untuk paid events only
     */
    public function scopeBerbayar($query)
    {
        return $query->where('berbayar', true);
    }

    /**
     * Scope untuk free events only
     */
    public function scopeGratis($query)
    {
        return $query->where('berbayar', false);
    }

    /**
     * Check if event is upcoming
     */
    public function isUpcoming()
    {
        return $this->start_time > Carbon::now();
    }

    /**
     * Check if event is ongoing
     */
    public function isOngoing()
    {
        return $this->start_time <= Carbon::now() && $this->end_time >= Carbon::now();
    }

    /**
     * Check if event is finished
     */
    public function isFinished()
    {
        return $this->end_time < Carbon::now();
    }

    /**
     * Check if event is paid
     */
    public function isBerbayar()
    {
        return $this->berbayar == true;
    }

    /**
     * Check if event is free
     */
    public function isGratis()
    {
        return $this->berbayar == false;
    }

    /**
     * Get total tickets sold for this event
     */
    public function getTotalTicketsSold()
    {
        return $this->transaksis()->where('status', 'paid')->sum('jumlah_tiket');
    }

    /**
     * Get total revenue for this event
     */
    public function getTotalRevenue()
    {
        return $this->transaksis()->where('status', 'paid')->sum('total_harga');
    }

    /**
     * Get event status label
     */
    public function getStatusLabel()
    {
        if ($this->isUpcoming()) {
            return 'Akan Datang';
        } elseif ($this->isOngoing()) {
            return 'Sedang Berlangsung';
        } else {
            return 'Selesai';
        }
    }

    /**
     * Get formatted date range
     */
    public function getDateRange()
    {
        $start = $this->start_time->format('d M Y H:i');
        $end = $this->end_time->format('d M Y H:i');

        if ($this->start_time->isSameDay($this->end_time)) {
            return $this->start_time->format('d M Y') . ' (' .
                $this->start_time->format('H:i') . ' - ' .
                $this->end_time->format('H:i') . ')';
        }

        return $start . ' - ' . $end;
    }
}
