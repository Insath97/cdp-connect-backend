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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('users')->cascadeOnDelete();

            $table->string('full_name');
            $table->string('name_with_initials');
            $table->string('customer_code')->unique();

            // Identity Documents
            $table->enum('id_type', ['nic', 'passport', 'driving_license', 'other'])->default('nic');
            $table->string('id_number')->unique();

            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('landmark')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->default('Sri Lanka');
            $table->string('postal_code')->nullable();

            $table->date('date_of_birth');

            $table->string('phone_primary');
            $table->string('phone_secondary')->nullable();
            $table->boolean('have_whatsapp')->default(false);
            $table->string('whatsapp_number')->nullable();

            $table->enum('preferred_language', ['english', 'sinhala', 'tamil'])->default('english');

            // Employment / Occupation Details
            $table->enum('employment_status', ['employed', 'self_employed', 'business', 'unemployed', 'retired', 'student'])->default('employed');
            $table->string('occupation')->nullable();
            $table->string('employer_name')->nullable();
            $table->string('employer_address_line1')->nullable();
            $table->string('employer_address_line2')->nullable();
            $table->string('employer_city')->nullable();
            $table->string('employer_state')->nullable();
            $table->string('employer_country')->nullable();
            $table->string('employer_postal_code')->nullable();
            $table->string('employer_phone')->nullable();
            $table->string('employer_email')->nullable();

            // Business Details (if self-employed or business owner)
            $table->string('business_name')->nullable();
            $table->string('business_registration_number')->nullable();
            $table->string('business_nature')->nullable();
            $table->string('business_address_line1')->nullable();
            $table->string('business_address_line2')->nullable();
            $table->string('business_city')->nullable();
            $table->string('business_state')->nullable();
            $table->string('business_country')->nullable();
            $table->string('business_postal_code')->nullable();
            $table->string('business_phone')->nullable();
            $table->string('business_email')->nullable();

            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
