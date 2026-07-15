<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Investment;
use App\Models\InvestmentProduct;
use App\Models\User;
use App\Models\Level;
use App\Models\Commission;
use App\Models\Country;
use App\Models\Province;
use App\Models\Zone;
use App\Models\Region;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CommissionCalculationTest extends TestCase
{
    use RefreshDatabase;

    protected function createTestBranch()
    {
        $country = Country::create([
            'name' => 'Sri Lanka',
            'code' => 'SL',
            'is_active' => true,
        ]);

        $province = Province::create([
            'name' => 'Western Province',
            'code' => 'WP',
            'country_id' => $country->id,
            'is_active' => true,
        ]);

        $zone = Zone::create([
            'name' => 'Colombo Zone',
            'code' => 'ZONE-COL',
            'province_id' => $province->id,
            'is_active' => true,
        ]);

        $region = Region::create([
            'name' => 'Colombo Region',
            'code' => 'REG-COL',
            'zone_id' => $zone->id,
            'is_active' => true,
        ]);

        return Branch::create([
            'name' => 'Colombo Branch',
            'code' => 'COL',
            'address_line1' => 'No. 123, Galle Road',
            'city' => 'Colombo',
            'zone_id' => $zone->id,
            'region_id' => $region->id,
            'province_id' => $province->id,
            'phone_primary' => '0112345678',
            'opening_date' => '2026-01-01',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function test_it_calculates_parent_commission_based_on_unit_head_commission()
    {
        // 1. Setup Data
        $amount = 100000;
        $unitHeadPct = 10;
        $parentPct = 20;

        // Ensure levels exist with correct IDs (bypass mass-assignment protection)
        $sglLevel = Level::find(14);
        if (!$sglLevel) {
            $sglLevel = new Level([
                'level_name' => 'Senior Group Leader',
                'slug' => 'senior-group-leader',
                'code' => 'SGL',
                'tire_level' => 14,
                'category' => 'executive',
                'isActive' => true
            ]);
            $sglLevel->id = 14;
            $sglLevel->save();
        }
        
        $glLevel = Level::find(15);
        if (!$glLevel) {
            $glLevel = new Level([
                'level_name' => 'Group Leader',
                'slug' => 'group-leader',
                'code' => 'GL',
                'tire_level' => 15,
                'category' => 'executive',
                'isActive' => true
            ]);
            $glLevel->id = 15;
            $glLevel->save();
        }

        $parentUser = User::create([
            'name' => 'Parent SGL',
            'username' => 'parent_sgl',
            'email' => 'parent@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'hierarchy',
            'level_id' => $sglLevel->id,
            'is_active' => true,
        ]);

        $unitHeadUser = User::create([
            'name' => 'Unit Head GL',
            'username' => 'uh_gl',
            'email' => 'uh@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'hierarchy',
            'level_id' => $glLevel->id,
            'parent_user_id' => $parentUser->id,
            'is_active' => true,
        ]);

        $branch = $this->createTestBranch();

        $customer = Customer::create([
            'full_name' => 'Jane Doe',
            'name_with_initials' => 'J. Doe',
            'customer_code' => 'CUST001',
            'id_type' => 'nic',
            'id_number' => '123456789V',
            'email' => 'jane@example.com',
            'date_of_birth' => '1990-01-01',
            'phone_primary' => '0771234567',
        ]);

        $product = InvestmentProduct::create([
            'name' => 'Savings Plan',
            'code' => 'SP001',
            'roi_percentage' => 10.00,
            'duration_months' => 12,
            'is_active' => true,
            'unit_head_commission_pct' => $unitHeadPct,
            'parent_commission_pct' => $parentPct,
        ]);

        $investment = Investment::create([
            'policy_number' => 'CDP-COL-99999999',
            'application_number' => 'APP-COL-99999999',
            'sales_code' => 'COL-9999',
            'reservation_date' => now()->format('Y-m-d'),
            'target_period_key' => now()->format('Y-m'),
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'investment_product_id' => $product->id,
            'investment_amount' => $amount,
            'business_type' => 'bank_deposit',
            'payment_proof' => 'test.jpg',
            'created_by' => $unitHeadUser->id,
            'unit_head_id' => $unitHeadUser->id,
            'investment_type' => 'hierarchy',
        ]);

        // 2. Invoke Logic
        Commission::generateForInvestment($investment);

        // 3. Verify
        $expectedUhAmount = ($amount * $unitHeadPct) / 100; // 10,000
        $expectedParentAmount = ($expectedUhAmount * $parentPct) / 100; // 2,000

        $uhCommission = Commission::where('investment_id', $investment->id)
            ->where('tier', 'unit_head')
            ->first();

        $parentCommission = Commission::where('investment_id', $investment->id)
            ->where('tier', 'parent')
            ->first();

        $this->assertNotNull($uhCommission);
        $this->assertEquals($expectedUhAmount, $uhCommission->commission_amount);
        $this->assertEquals($unitHeadUser->id, $uhCommission->user_id);

        $this->assertNotNull($parentCommission);
        $this->assertEquals($expectedParentAmount, $parentCommission->commission_amount);
        $this->assertEquals($parentUser->id, $parentCommission->user_id);
    }
}
