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
            $table->string('witness_01_address', 500)->nullable()->change();
            $table->string('witness_02_address', 500)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('legals', function (Blueprint $table) {
            $table->string('witness_01_address', 255)->nullable()->change();
            $table->string('witness_02_address', 255)->nullable()->change();
        });
    }
};
