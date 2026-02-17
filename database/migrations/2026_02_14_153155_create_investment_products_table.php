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
        Schema::create('investment_products', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // 6 Months Plan, 1 Year Plan
            $table->string('code')->unique(); // INV-06M, INV-01Y
            $table->integer('duration_months'); // 6,12,24,36,48,60
            $table->decimal('roi_percentage', 5, 2); // 18,36,38,40,45,48
            $table->decimal('minimum_amount', 15, 2)->default(10000);
            $table->decimal('maximum_amount', 15, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('investment_products');
    }
};
