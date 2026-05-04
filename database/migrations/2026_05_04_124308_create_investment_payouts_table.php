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
        Schema::create('investment_payouts', function (Blueprint $结构) {
            $结构->id();
            $结构->foreignId('investment_id')->constrained()->onDelete('cascade');
            $结构->date('scheduled_date');
            $结构->decimal('amount', 15, 2);
            $结构->enum('status', ['unpaid', 'paid', 'hold'])->default('unpaid');
            $结构->timestamp('paid_at')->nullable();
            $结构->string('reference_number')->nullable();
            $结构->text('remarks')->nullable();
            $结构->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('investment_payouts');
    }
};
