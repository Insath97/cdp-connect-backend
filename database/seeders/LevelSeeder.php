<?php

namespace Database\Seeders;

use App\Models\Level;
use Illuminate\Database\Seeder;

class LevelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $levels = [
            [
                "id" => 1,
                "level_name" => "GM",
                "slug" => "gm",
                "code" => "gm-001",
                "tire_level" => 1,
                "category" => "executive",
                "isActive" => 1,
                "is_single_user" => 1,
                "created_at" => "2026-02-19 14:02:35",
                "updated_at" => "2026-02-19 14:02:35",
            ],
            [
                "id" => 2,
                "level_name" => "AGM",
                "slug" => "agm",
                "code" => "AGM001",
                "tire_level" => 2,
                "category" => "executive",
                "isActive" => 1,
                "is_single_user" => 0,
                "created_at" => "2026-02-19 14:03:19",
                "updated_at" => "2026-02-19 14:03:19",
            ],
            [
                "id" => 3,
                "level_name" => "Provincial Manager",
                "slug" => "provincial-manager",
                "code" => "PM",
                "tire_level" => 3,
                "category" => "executive",
                "isActive" => 1,
                "is_single_user" => 0,
                "created_at" => "2026-02-19 15:27:52",
                "updated_at" => "2026-02-19 15:27:52",
            ],
            [
                "id" => 4,
                "level_name" => "Regional Manager",
                "slug" => "regional-manager",
                "code" => "RM",
                "tire_level" => 4,
                "category" => "executive",
                "isActive" => 1,
                "is_single_user" => 0,
                "created_at" => "2026-02-19 15:28:24",
                "updated_at" => "2026-02-19 15:28:24",
            ],
            [
                "id" => 5,
                "level_name" => "Zonal Manager",
                "slug" => "zonal-manager",
                "code" => "ZM",
                "tire_level" => 5,
                "category" => "executive",
                "isActive" => 1,
                "is_single_user" => 0,
                "created_at" => "2026-02-19 15:28:55",
                "updated_at" => "2026-02-19 15:28:55",
            ],
            [
                "id" => 6,
                "level_name" => "Branch Manager",
                "slug" => "branch-manager",
                "code" => "BM",
                "tire_level" => 6,
                "category" => "executive",
                "isActive" => 1,
                "is_single_user" => 0,
                "created_at" => "2026-02-19 15:29:36",
                "updated_at" => "2026-02-19 15:29:36",
            ],
            [
                "id" => 7,
                "level_name" => "BDM",
                "slug" => "bdm",
                "code" => "BDM",
                "tire_level" => 7,
                "category" => "executive",
                "isActive" => 1,
                "is_single_user" => 0,
                "created_at" => "2026-02-19 15:30:19",
                "updated_at" => "2026-02-19 15:30:19",
            ],
            [
                "id" => 8,
                "level_name" => "Senior Group Leader",
                "slug" => "senior-group-leader",
                "code" => "SGL",
                "tire_level" => 8,
                "category" => "executive",
                "isActive" => 1,
                "is_single_user" => 0,
                "created_at" => "2026-02-19 15:34:55",
                "updated_at" => "2026-02-19 15:35:06",
            ],
            [
                "id" => 9,
                "level_name" => "Group Leader",
                "slug" => "group-leader",
                "code" => "GL",
                "tire_level" => 9,
                "category" => "executive",
                "isActive" => 1,
                "is_single_user" => 0,
                "created_at" => "2026-02-19 15:35:49",
                "updated_at" => "2026-02-19 15:35:49",
            ],
            [
                "id" => 10,
                "level_name" => "Senior Consultant",
                "slug" => "senior-consultant",
                "code" => "SC",
                "tire_level" => 10,
                "category" => "executive",
                "isActive" => 1,
                "is_single_user" => 0,
                "created_at" => "2026-02-19 15:36:46",
                "updated_at" => "2026-02-19 15:37:02",
            ],
            [
                "id" => 11,
                "level_name" => "Consultant",
                "slug" => "consultant",
                "code" => "C",
                "tire_level" => 11,
                "category" => "executive",
                "isActive" => 1,
                "is_single_user" => 0,
                "created_at" => "2026-02-19 15:37:19",
                "updated_at" => "2026-02-19 15:37:19",
            ],
        ];

        foreach ($levels as $level) {
            Level::updateOrCreate(
                ['id' => $level['id']],
                $level
            );
        }
    }
}
