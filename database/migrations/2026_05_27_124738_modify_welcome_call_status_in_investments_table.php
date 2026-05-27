<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('investments', function (Blueprint $table) {
            $table->enum('welcome_call_status', ['pending', 'completed', 'not_reachable', 'no_answer', 'others'])
                ->default('pending')
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Safety: Update any new statuses to 'pending' before reverting the enum type
        Illuminate\Support\Facades\DB::table('investments')
            ->whereIn('welcome_call_status', ['no_answer', 'others'])
            ->update(['welcome_call_status' => 'pending']);

        Schema::table('investments', function (Blueprint $table) {
            $table->enum('welcome_call_status', ['pending', 'completed', 'not_reachable'])
                ->default('pending')
                ->change();
        });
    }
};
