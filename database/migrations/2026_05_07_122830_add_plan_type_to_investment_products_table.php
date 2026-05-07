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
        Schema::table('investment_products', function (Blueprint $table) {
            $table->enum('plan_type', ['normal', 'special'])->default('normal')->after('roi_percentage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('investment_products', function (Blueprint $table) {
            $table->dropColumn('plan_type');
        });
    }
};
