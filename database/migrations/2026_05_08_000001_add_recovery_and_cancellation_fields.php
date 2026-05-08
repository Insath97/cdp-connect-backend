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
        Schema::table('investments', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('status');
            $table->text('cancellation_reason')->nullable()->after('cancelled_at');
        });

        Schema::table('commissions', function (Blueprint $table) {
            $table->decimal('earned_amount', 15, 2)->default(0)->after('commission_percentage');
            $table->decimal('recover_amount', 15, 2)->default(0)->after('earned_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('investments', function (Blueprint $table) {
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });

        Schema::table('commissions', function (Blueprint $table) {
            $table->dropColumn(['earned_amount', 'recover_amount']);
        });
    }
};
