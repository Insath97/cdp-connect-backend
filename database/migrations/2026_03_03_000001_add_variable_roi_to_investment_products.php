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
        Schema::table('investment_products', function (Blueprint $table) {
            $table->boolean('is_variable_roi')->default(false)->after('roi_percentage');
        });

        Schema::create('investment_product_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_product_id')->constrained()->onDelete('cascade');
            $table->integer('year');
            $table->decimal('roi_percentage', 5, 2);
            $table->timestamps();

            $table->unique(['investment_product_id', 'year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('investment_product_rates');
        Schema::table('investment_products', function (Blueprint $table) {
            $table->dropColumn('is_variable_roi');
        });
    }
};
