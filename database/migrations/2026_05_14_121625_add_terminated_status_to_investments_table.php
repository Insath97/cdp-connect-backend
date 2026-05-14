<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE investments MODIFY COLUMN status ENUM('pending', 'approved', 'cancelled', 'rejected', 'terminated') DEFAULT 'pending'");

        Schema::table('investments', function (Blueprint $table) {
            $table->timestamp('terminated_at')->nullable()->after('rejection_reason');
            $table->string('termination_reason', 1000)->nullable()->after('terminated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('investments', function (Blueprint $table) {
            $table->dropColumn(['terminated_at', 'termination_reason']);
        });

        DB::statement("ALTER TABLE investments MODIFY COLUMN status ENUM('pending', 'approved', 'cancelled', 'rejected') DEFAULT 'pending'");
    }
};
