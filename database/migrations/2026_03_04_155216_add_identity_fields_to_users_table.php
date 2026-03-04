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
        Schema::table('users', function (Blueprint $table) {
            $table->enum('id_type', ['nic', 'passport', 'driving_license', 'other'])->default('nic')->after('password')->nullable();
            $table->string('id_number')->unique()->after('id_type')->nullable();
        });
    }

    /**
     * Reverse the migrations
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['id_type', 'id_number']);
        });
    }
};
