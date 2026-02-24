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
        Schema::create('investments', function (Blueprint $table) {
            $table->id();
            $table->string('policy_number')->nullable()->unique();
            $table->string('application_number')->unique();
            $table->string('sales_code')->unique();
            $table->date('reservation_date');
            $table->string('target_period_key')->index(); // Linked to Target module period_key

            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('investment_product_id')->constrained('investment_products')->onDelete('cascade');

            $table->foreignId('beneficiary_id')->nullable()->constrained('beneficiaries')->onDelete('set null');
            $table->foreignId('customer_bank_detail_id')->nullable()->constrained('customer_bank_details')->onDelete('set null');

            $table->decimal('investment_amount', 15, 2);

            $table->enum('bank', ['HNB', 'Sampath', 'Commercial Bank', 'Peoples Bank', 'NSB', 'Other'])->default('HNB');
            $table->enum('payment_type', ['full_payment', 'monthly'])->default('full_payment');
            $table->text('payment_description')->nullable();
            $table->decimal('initial_payment', 15, 2)->default(0);
            $table->date('initial_payment_date')->nullable();
            $table->decimal('monthly_payment_amount', 15, 2)->nullable();
            $table->date('monthly_payment_date')->nullable();
            $table->string('payment_proof');

            $table->enum('status', ['pending', 'approved', 'cancelled'])->default('pending');

            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('unit_head_id')->constrained('users')->onDelete('cascade');

            $table->foreignId('checked_by')->nullable()->constrained('users')->onDelete('set null');
            $table->date('checked_at')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->date('approved_at')->nullable();

            $table->text('notes')->nullable();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('investments');
    }
};
