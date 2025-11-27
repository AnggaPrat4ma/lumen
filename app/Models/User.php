<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Lumen\Auth\Authorizable;
use Spatie\Permission\Traits\HasRoles;


class User extends Model implements AuthenticatableContract, AuthorizableContract
{
    use Authenticatable, Authorizable, HasFactory, HasRoles;

    // public function getGuardName()
    // {
    //     return $this->guard_name ?? 'api';
    // }

    protected $guard_name = 'api';

    protected $table = 'user';
    protected $primaryKey = 'id_user';
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'nama',
        'email',
        'password',
        'phone',
        'firebase_uid',
        'photo',
        'status'
    ];

    /**
     * The attributes excluded from the model's JSON form.
     */
    protected $hidden = [
        'firebase_uid',
    ];

    protected $casts = [
        'id_user' => 'integer',
        'status' => 'string',
    ];

    // ✅ TAMBAHKAN METHOD INI untuk Sanctum
    /**
     * Get the name of the unique identifier for the user.
     *
     * @return string
     */
    public function getAuthIdentifierName()
    {
        return 'id_user';
    }

    /**
     * Get the unique identifier for the user.
     *
     * @return mixed
     */
    public function getAuthIdentifier()
    {
        return $this->id_user;
    }

    public function events()
    {
        return $this->belongsToMany(Event::class, 'user_has_event', 'id_user', 'id_event')->withPivot('is_owner');
    }

    public function owns($resource)
    {
        return $this->id_user === $resource->id_user;
    }

    /**
     * Get events where user is owner
     */
    public function ownedEvents()
    {
        return $this->events()->wherePivot('is_owner', 1);
    }

    /**
     * Check if user can manage event
     */
    public function canManageEvent($event)
    {
        // Admin can manage all
        if ($this->hasRole('Admin')) {
            return true;
        }

        // EO can manage if assigned as owner
        if ($this->hasRole('EO')) {
            return $event->isOwnedBy($this);
        }

        return false;
    }

    /**
     * Check if user can manage resource (owns it or is admin)
     *
     * @param Model $resource
     * @return bool
     */
    public function canManage($resource)
    {
        return $this->hasRole('Admin') || $this->owns($resource);
    }

    /**
     * Relasi ke Transaksi
     * User memiliki banyak transaksi
     */
    public function transaksis()
    {
        return $this->hasMany(Transaksi::class, 'id_user', 'id_user');
    }


    /**
     * Alias singular untuk transaksis
     */
    public function transaksi()
    {
        return $this->transaksis();
    }

    /**
     * Relasi ke Tiket melalui Transaksi
     * User memiliki banyak tiket
     */
    public function tikets()
    {
        return $this->hasManyThrough(
            Tiket::class,
            Transaksi::class,
            'id_user',        // FK di transaksi
            'id_transaksi',   // FK di tiket
            'id_user',        // Local key di user
            'id_transaksi'    // Local key di transaksi
        );
    }

    /**
     * Get active tickets only
     */
    public function activeTikets()
    {
        return $this->tikets()
            ->where('status', 'aktif')
            ->whereHas('transaksi', function ($q) {
                $q->where('status', 'paid');
            });
    }

    /**
     * Get used tickets only
     */
    public function usedTikets()
    {
        return $this->tikets()
            ->where('status', 'digunakan')
            ->whereHas('transaksi', function ($q) {
                $q->where('status', 'paid');
            });
    }

    /**
     * Get paid transactions only
     */
    public function paidTransaksis()
    {
        return $this->transaksis()->where('status', 'paid');
    }

    /**
     * Get pending transactions only
     */
    public function pendingTransaksis()
    {
        return $this->transaksis()->where('status', 'pending');
    }

    /**
     * Scope untuk user aktif
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope untuk user inactive
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    /**
     * Check if user is active
     */
    public function isActive()
    {
        return $this->status === 'active';
    }

    /**
     * Check if user is inactive
     */
    public function isInactive()
    {
        return $this->status === 'inactive';
    }

    /**
     * Get total transactions count
     */
    public function getTotalTransactions()
    {
        return $this->transaksis()->count();
    }

    /**
     * Get total paid transactions count
     */
    public function getTotalPaidTransactions()
    {
        return $this->paidTransaksis()->count();
    }

    /**
     * Get total tickets owned
     */
    public function getTotalTickets()
    {
        return $this->tikets()->whereHas('transaksi', function ($q) {
            $q->where('status', 'paid');
        })->count();
    }

    /**
     * Get total active tickets
     */
    public function getTotalActiveTickets()
    {
        return $this->activeTikets()->count();
    }

    /**
     * Get total spending
     */
    public function getTotalSpending()
    {
        return $this->paidTransaksis()->sum('total_harga');
    }

    /**
     * Get formatted total spending
     */
    public function getFormattedTotalSpending()
    {
        return 'Rp ' . number_format($this->getTotalSpending(), 0, ',', '.');
    }

    /**
     * Get user profile data
     */
    public function getProfileData()
    {
        return [
            'id_user' => $this->id_user,
            'nama' => $this->nama,
            'email' => $this->email,
            'phone' => $this->phone,
            'photo' => $this->photo,
            'status' => $this->status,
            'is_active' => $this->isActive(),
            'total_transactions' => $this->getTotalTransactions(),
            'total_paid_transactions' => $this->getTotalPaidTransactions(),
            'total_tickets' => $this->getTotalTickets(),
            'total_active_tickets' => $this->getTotalActiveTickets(),
            'total_spending' => $this->getTotalSpending(),
            'formatted_total_spending' => $this->getFormattedTotalSpending(),
        ];
    }

    /**
     * Get upcoming events user has tickets for
     */
    public function getUpcomingEvents()
    {
        return Event::whereHas('jenisTikets.transaksis', function ($q) {
            $q->where('id_user', $this->id_user)
                ->where('status', 'paid');
        })->where('start_time', '>', Carbon::now())
            ->orderBy('start_time', 'asc')
            ->get();
    }

    /**
     * Check if user has ticket for specific event
     */
    public function hasTicketForEvent($eventId)
    {
        return $this->tikets()
            ->whereHas('transaksi', function ($q) use ($eventId) {
                $q->where('status', 'paid')
                    ->whereHas('jenisTiket', function ($q2) use ($eventId) {
                        $q2->where('id_event', $eventId);
                    });
            })->exists();
    }

    public function getGuardName()
    {
        // jika properti di-set, pakai itu; jika tidak, fallback ke config 'auth.defaults.guard'
        if (!empty($this->guard_name)) {
            return $this->guard_name;
        }

        // gunakan config kalau tersedia, default 'api'
        if (function_exists('config')) {
            return config('auth.defaults.guard', 'api');
        }

        return 'api';
    }
}
