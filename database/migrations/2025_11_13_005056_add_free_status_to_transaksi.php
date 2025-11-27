<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddFreeStatusToTransaksi extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // ✅ Update enum status untuk menambahkan 'free'
        DB::statement("ALTER TABLE transaksi MODIFY COLUMN status ENUM('pending', 'paid', 'failed', 'expired', 'free') NOT NULL DEFAULT 'pending'");
        
        // ✅ Optional: Tambahkan index untuk performa query status free
        Schema::table('transaksi', function (Blueprint $table) {
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Kembalikan ke enum lama (tanpa 'free')
        DB::statement("ALTER TABLE transaksi MODIFY COLUMN status ENUM('pending', 'paid', 'failed', 'expired') NOT NULL DEFAULT 'pending'");
        
        Schema::table('transaksi', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });
    }
}