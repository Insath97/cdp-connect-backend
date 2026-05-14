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
            $table->enum('welcome_call_status', ['pending', 'completed', 'not_reachable'])->default('pending')->after('status');
            $table->foreignId('welcome_call_by')->nullable()->constrained('users')->onDelete('set null')->after('welcome_call_status');
            $table->timestamp('welcome_call_at')->nullable()->after('welcome_call_by');
            $table->text('welcome_call_notes')->nullable()->after('welcome_call_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('investments', function (Blueprint $table) {
            $table->dropForeign(['welcome_call_by']);
            $table->dropColumn(['welcome_call_status', 'welcome_call_by', 'welcome_call_at', 'welcome_call_notes']);
        });
    }
};
