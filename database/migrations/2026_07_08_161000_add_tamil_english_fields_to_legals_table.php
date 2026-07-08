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
            $table->string('amount_in_words')->nullable()->after('execution_year');
            $table->string('plan')->nullable()->after('amount_in_words');
            $table->string('monthly_profit')->nullable()->after('plan');
            $table->string('monthly_profit_day')->nullable()->after('monthly_profit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('legals', function (Blueprint $table) {
            $table->dropColumn(['amount_in_words', 'plan', 'monthly_profit', 'monthly_profit_day']);
        });
    }
};
