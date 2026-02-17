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
        Schema::create('levels', function (Blueprint $table) {
            $table->id();
            $table->string('level_name');
            $table->string('slug')->unique();
            $table->string('code')->unique();
            $table->integer('tire_level');
            $table->enum('category', ['executive', 'management', 'branch', 'agency']);
            $table->boolean('isActive')->default(true);
            $table->boolean('is_single_user')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('levels');
    }
};
