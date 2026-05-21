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
        Schema::table('legals', function (Blueprint $table) {
            $table->decimal('monthly_return', 15, 2)->default(0);
            $table->decimal('annual_return', 15, 2)->default(0);
            $table->decimal('maturity_amount', 15, 2)->default(0);

            $table->decimal('month_6_breakdown', 15, 2)->nullable();
            $table->decimal('year_1_breakdown', 15, 2)->nullable();
            $table->decimal('year_2_breakdown', 15, 2)->nullable();
            $table->decimal('year_3_breakdown', 15, 2)->nullable();
            $table->decimal('year_4_breakdown', 15, 2)->nullable();
            $table->decimal('year_5_breakdown', 15, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('legals', function (Blueprint $table) {
            $table->dropColumn([
                'monthly_return',
                'annual_return',
                'maturity_amount',
                'month_6_breakdown',
                'year_1_breakdown',
                'year_2_breakdown',
                'year_3_breakdown',
                'year_4_breakdown',
                'year_5_breakdown',
            ]);
        });
    }
};
