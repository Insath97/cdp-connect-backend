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
        Schema::table('quotations', function (Blueprint $table) {
            $table->decimal('monthly_return', 15, 2)->after('investment_amount')->default(0);
            $table->decimal('annual_return', 15, 2)->after('monthly_return')->default(0);
            $table->decimal('maturity_amount', 15, 2)->after('annual_return')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn(['monthly_return', 'annual_return', 'maturity_amount']);
        });
    }
};
