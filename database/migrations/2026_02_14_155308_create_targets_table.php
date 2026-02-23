<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('assigned_by')->constrained('users');
            $table->enum('period_type', ['month', 'quarter', 'year']);
            $table->string('period_key');// 2026-02, 2026-Q1, 2026
            $table->decimal('target_amount', 15, 2);
            $table->decimal('current_amount', 15, 2)->default(0);
            $table->decimal('achieved_amount', 15, 2)->default(0);
            $table->decimal('achievement_percentage', 5, 2)->default(0);
            $table->enum('status', ['active', 'achieved', 'expired'])->default('active');
            $table->timestamp('achieved_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('targets');
    }
};
