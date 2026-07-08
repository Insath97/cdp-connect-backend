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
            $table->string('business_entered_date')->nullable()->after('execution_location');
            $table->string('completed_date')->nullable()->after('business_entered_date');
            $table->string('execution_year')->nullable()->after('completed_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('legals', function (Blueprint $table) {
            $table->dropColumn(['business_entered_date', 'completed_date', 'execution_year']);
        });
    }
};
