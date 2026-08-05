<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('investment_hierarchies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_id')->constrained('investments')->onDelete('cascade');
            $table->foreignId('ancestor_id')->constrained('users')->onDelete('cascade');
            $table->integer('depth');
            $table->timestamps();

            $table->unique(['investment_id', 'ancestor_id']);
            $table->index('ancestor_id');
            $table->index('investment_id');
        });

        // Retroactively populate existing investments based on current hierarchy
        DB::table('investments')->orderBy('id')->chunk(100, function ($investments) {
            $records = [];
            foreach ($investments as $investment) {
                $unitHeadId = $investment->unit_head_id;
                $unitHead = DB::table('users')->where('id', $unitHeadId)->first();
                
                if ($unitHead) {
                    $parentId = $unitHead->parent_user_id;
                    $depth = 1;
                    $visited = [$unitHeadId];

                    while ($parentId && !in_array($parentId, $visited)) {
                        $parent = DB::table('users')->where('id', $parentId)->first();
                        if (!$parent) {
                            break;
                        }

                        $records[] = [
                            'investment_id' => $investment->id,
                            'ancestor_id' => $parent->id,
                            'depth' => $depth,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];

                        $visited[] = $parentId;
                        $parentId = $parent->parent_user_id;
                        $depth++;
                    }
                }
            }

            if (!empty($records)) {
                DB::table('investment_hierarchies')->insert($records);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('investment_hierarchies');
    }
};
