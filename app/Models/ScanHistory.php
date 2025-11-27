<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScanHistory extends Model
{
    protected $table = 'scan_history';
    protected $primaryKey = 'id_scan';
    
    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;

    protected $fillable = [
        'id_tiket',
        'id_user',
        'scan_time',
    ];

    protected $casts = [
        'scan_time' => 'datetime',
        'created_at' => 'datetime',
    ];

    // 🔧 FIX: Relasi ke Tiket (gunakan id_tiket, bukan id)
    public function tiket()
    {
        return $this->belongsTo(Tiket::class, 'id_tiket', 'id_tiket');
    }

    // Relasi ke User
    public function user()
    {
        return $this->belongsTo(User::class, 'id_user', 'id_user');
    }
}