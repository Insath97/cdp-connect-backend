<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // MySQL specific way to update enum
        DB::statement("ALTER TABLE investments MODIFY COLUMN status ENUM('pending', 'approved', 'cancelled', 'rejected') DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE investments MODIFY COLUMN status ENUM('pending', 'approved', 'cancelled') DEFAULT 'pending'");
    }
};
