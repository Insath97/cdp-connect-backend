<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE investments MODIFY COLUMN status ENUM('pending', 'approved', 'cancelled', 'rejected', 'terminated', 'expired') DEFAULT 'pending'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE investments MODIFY COLUMN status ENUM('pending', 'approved', 'cancelled', 'rejected', 'terminated') DEFAULT 'pending'");
        }
    }
};
