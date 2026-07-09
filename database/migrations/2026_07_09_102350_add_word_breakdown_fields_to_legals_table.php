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
            $table->string('month_6_breakdown_in_words')->nullable()->after('yearly_breakdown');
            $table->string('year_1_breakdown_in_words')->nullable()->after('month_6_breakdown_in_words');
            $table->string('year_2_breakdown_in_words')->nullable()->after('year_1_breakdown_in_words');
            $table->string('year_3_breakdown_in_words')->nullable()->after('year_2_breakdown_in_words');
            $table->string('year_4_breakdown_in_words')->nullable()->after('year_3_breakdown_in_words');
            $table->string('year_5_breakdown_in_words')->nullable()->after('year_4_breakdown_in_words');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('legals', function (Blueprint $table) {
            $table->dropColumn([
                'month_6_breakdown_in_words',
                'year_1_breakdown_in_words',
                'year_2_breakdown_in_words',
                'year_3_breakdown_in_words',
                'year_4_breakdown_in_words',
                'year_5_breakdown_in_words',
            ]);
        });
    }
};
