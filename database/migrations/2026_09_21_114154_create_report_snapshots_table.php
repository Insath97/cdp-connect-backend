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
        Schema::create('report_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('report_type', 64)->index();
            $table->string('period_key', 10)->index();
            $table->string('scope_key', 64)->nullable()->default('global')->index();
            $table->string('filters_hash', 64)->nullable()->default('default');
            $table->json('filters')->nullable();
            $table->longText('snapshot_data');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['report_type', 'period_key', 'scope_key', 'filters_hash'], 'uq_report_snapshots');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_snapshots');
    }
};
