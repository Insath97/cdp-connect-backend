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
        Schema::create('legals', function (Blueprint $table) {
            $table->id();

            $table->enum('language', ['english', 'tamil'])->default('english');
            $table->string('legal_number')->unique();
            $table->date('date_of_agreement')->nullable();

            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            $table->string('full_name');
            $table->string('name_with_initials');
            $table->enum('id_type', ['nic', 'passport', 'driving_license', 'other'])->default('nic');
            $table->string('id_number');
            $table->string('email')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('landmark')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->default('Sri Lanka');
            $table->string('postal_code')->nullable();

            $table->foreignId('investment_product_id')->constrained('investment_products')->cascadeOnDelete();
            $table->foreignId('investment_id')->constrained('investments')->cascadeOnDelete();

            $table->unique(['investment_id', 'language']);

            $table->string('year_in_words')->nullable();
            $table->string('year')->nullable();

            $table->string('witness_01_name')->nullable();
            $table->string('witness_01_nic')->nullable();
            $table->string('witness_01_address')->nullable();

            $table->string('witness_02_name')->nullable();
            $table->string('witness_02_nic')->nullable();
            $table->string('witness_02_address')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legals');
    }
};
