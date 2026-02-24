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
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('quotation_number')->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->onDelete('cascade');

            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('full_name');
            $table->string('name_with_initials')->nullable();
            $table->enum('id_type', ['nic', 'passport', 'driving_license', 'other'])->default('nic');
            $table->string('id_number');
            $table->string('phone_primary');
            $table->string('email')->nullable();
            $table->string('address')->nullable();

            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('investment_product_id')->constrained('investment_products')->onDelete('cascade');

            $table->decimal('investment_amount', 15, 2);
            $table->decimal('monthly_return', 15, 2)->default(0);
            $table->decimal('annual_return', 15, 2)->default(0);
            $table->decimal('maturity_amount', 15, 2)->default(0);

            $table->decimal('month_6_breakdown', 15, 2)->nullable();
            $table->decimal('year_1_breakdown', 15, 2)->nullable();
            $table->decimal('year_2_breakdown', 15, 2)->nullable();
            $table->decimal('year_3_breakdown', 15, 2)->nullable();
            $table->decimal('year_4_breakdown', 15, 2)->nullable();
            $table->decimal('year_5_breakdown', 15, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->enum('status', ['draft', 'sent', 'accepted', 'rejected'])->default('draft');

            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
