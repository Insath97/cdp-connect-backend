<?php

namespace Database\Seeders;

use App\Models\CommissionSetting;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CommissionSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        CommissionSetting::updateOrCreate(
            ['key' => 'unit_head_commission_pct'],
            ['value' => 10.00, 'description' => 'Commission percentage for the Unit Head who placed the investment']
        );

        CommissionSetting::updateOrCreate(
            ['key' => 'parent_commission_pct'],
            ['value' => 1.00, 'description' => 'Commission percentage for the parent user of the Unit Head']
        );
    }
}
