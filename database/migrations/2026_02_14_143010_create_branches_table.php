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
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Colombo Main Branch
            $table->string('code')->unique(); // BR001
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('city');
            $table->string('postal_code')->nullable();

            // Geographical hierarchy
            $table->foreignId('zone_id')->constrained('zones');
            $table->foreignId('region_id')->constrained('regions'); // Denormalized
            $table->foreignId('province_id')->constrained('provinces'); // Denormalized

            // Contact
            $table->string('phone_primary');
            $table->string('phone_secondary')->nullable();
            $table->string('email')->nullable();
            $table->string('fax')->nullable();

            // Operational
            $table->date('opening_date');
            $table->enum('branch_type', ['main', 'city', 'satellite', 'mobile'])->default('city');
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_head_office')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
