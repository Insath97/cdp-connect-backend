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
            $table->string('bank_name')->nullable();
            $table->string('branch_name')->nullable();
            $table->string('account_number')->nullable();

            $table->string('beneficiary_full_name')->nullable();
            $table->enum('beneficiary_id_type', ['nic', 'passport', 'driving_license', 'other'])->default('nic');
            $table->string('beneficiary_id_number')->nullable();
            $table->string('beneficiary_phone_primary')->nullable();
            $table->string('beneficiary_relationship')->nullable();
            $table->decimal('beneficiary_share_percentage', 5, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('legals', function (Blueprint $table) {
            $table->dropColumn([
                'bank_name',
                'branch_name',
                'account_number',
                'beneficiary_full_name',
                'beneficiary_id_type',
                'beneficiary_id_number',
                'beneficiary_phone_primary',
                'beneficiary_relationship',
                'beneficiary_share_percentage',
            ]);
        });
    }
};
