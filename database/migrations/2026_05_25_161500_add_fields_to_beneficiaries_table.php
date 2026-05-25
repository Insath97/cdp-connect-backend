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
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->enum('type', ['adult', 'child'])->default('adult')->after('customer_id');
            $table->string('id_image')->nullable()->after('share_percentage');
            $table->string('child_file')->nullable()->after('id_image');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropColumn(['type', 'id_image', 'child_file']);
        });
    }
};
