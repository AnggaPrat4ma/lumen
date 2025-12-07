<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Event extends Model
{
    protected $table = 'event';
    protected $primaryKey = 'id_event';
    public $timestamps = false;

    protected $fillable = [
        'nama_event',
        'slug',
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
     * Boot method untuk auto-generate slug
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-generate slug saat create
        static::creating(function ($event) {
            if (empty($event->slug)) {
                $event->slug = static::generateUniqueSlug($event->nama_event);
            }
        });

        // Update slug saat nama_event berubah
        static::updating(function ($event) {
            if ($event->isDirty('nama_event')) {
                $event->slug = static::generateUniqueSlug($event->nama_event, $event->id_event);
            }
        });
    }

    /**
     * Generate unique slug dari nama event
     */
    public static function generateUniqueSlug($nama_event, $ignoreId = null)
    {
        $slug = Str::slug($nama_event);
        $originalSlug = $slug;
        $count = 1;

        // Check uniqueness
        while (static::slugExists($slug, $ignoreId)) {
            $slug = $originalSlug . '-' . $count;
            $count++;
        }

        return $slug;
    }

    /**
     * Check if slug exists
     */
    protected static function slugExists($slug, $ignoreId = null)
    {
        $query = static::where('slug', $slug);
        
        if ($ignoreId) {
            $query->where('id_event', '!=', $ignoreId);
        }
        
        return $query->exists();
    }

    /**
     * Get route key name (untuk route model binding)
     */
    public function getRouteKeyName()
    {
        return 'slug';
    }

    /**
     * Relasi ke JenisTiket
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'id_user', 'id_user');
    }

    public function jenisTiket()
    {
        return $this->hasMany(JenisTiket::class, 'id_event', 'id_event');
    }

    public function jenisTikets()
    {
        return $this->jenisTiket();
    }

    public function transaksis()
    {
        return $this->hasManyThrough(
            Transaksi::class,
            JenisTiket::class,
            'id_event',
            'id_jenis_tiket',
            'id_event',
            'id_jenis_tiket'
        );
    }

    public function users()
    {
        return $this->belongsToMany(
            User::class, 
            'user_has_event', 
            'id_event', 
            'id_user'
        )->withPivot('is_owner');
    }

    public function isOwnedBy($user)
    {
        return $this->users()
            ->where('user.id_user', $user->id_user)
            ->wherePivot('is_owner', 1)
            ->exists();
    }

    public function owners()
    {
        return $this->users()->wherePivot('is_owner', 1);
    }

    public function availableJenisTiket()
    {
        return $this->jenisTiket()->where('kuota', '>', 0);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('start_time', '>', Carbon::now());
    }

    public function scopeOngoing($query)
    {
        return $query->where('start_time', '<=', Carbon::now())
            ->where('end_time', '>=', Carbon::now());
    }

    public function scopePast($query)
    {
        return $query->where('end_time', '<', Carbon::now());
    }

    public function scopeBerbayar($query)
    {
        return $query->where('berbayar', true);
    }

    public function scopeGratis($query)
    {
        return $query->where('berbayar', false);
    }

    public function isUpcoming()
    {
        return $this->start_time > Carbon::now();
    }

    public function isOngoing()
    {
        return $this->start_time <= Carbon::now() && $this->end_time >= Carbon::now();
    }

    public function isFinished()
    {
        return $this->end_time < Carbon::now();
    }

    public function isBerbayar()
    {
        return $this->berbayar == true;
    }

    public function isGratis()
    {
        return $this->berbayar == false;
    }

    public function getTotalTicketsSold()
    {
        return $this->transaksis()->where('status', 'paid')->sum('jumlah_tiket');
    }

    public function getTotalRevenue()
    {
        return $this->transaksis()->where('status', 'paid')->sum('total_harga');
    }

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