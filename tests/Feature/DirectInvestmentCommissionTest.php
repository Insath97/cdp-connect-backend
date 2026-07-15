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

class DirectInvestmentCommissionTest extends TestCase
{
    use RefreshDatabase;

    protected $branch;
    protected $customer;
    protected $product;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Geographical structures & Branch
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

        $this->branch = Branch::create([
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

        // 2. Setup Customer
        $this->customer = Customer::create([
            'full_name' => 'John Doe',
            'name_with_initials' => 'J. Doe',
            'customer_code' => 'CUST-0001',
            'id_type' => 'nic',
            'id_number' => '950000000V',
            'email' => 'john@example.com',
            'date_of_birth' => '1995-01-01',
            'phone_primary' => '0777111222',
        ]);

        // 3. Setup Investment Product
        $this->product = InvestmentProduct::create([
            'name' => 'Vip Direct Plan',
            'code' => 'VDP001',
            'roi_percentage' => 12.00,
            'duration_months' => 24,
            'is_active' => true,
            'unit_head_commission_pct' => 10.00,
            'parent_commission_pct' => 20.00,
        ]);

        // 4. Ensure necessary levels exist
        $this->createLevel(1, 'General Sales Manager', 'gsm', 'GSM001', 1);
        $this->createLevel(2, 'Head of National Sales', 'hns', 'HNS001', 2);
        $this->createLevel(14, 'Senior Group Leader', 'senior-group-leader', 'SGL', 14);
        $this->createLevel(15, 'Group Leader', 'group-leader', 'GL', 15);
    }

    protected function createLevel($id, $name, $slug, $code, $tire)
    {
        $level = Level::find($id);
        if (!$level) {
            $level = new Level([
                'level_name' => $name,
                'slug' => $slug,
                'code' => $code,
                'tire_level' => $tire,
                'category' => 'executive',
                'isActive' => true
            ]);
            $level->id = $id;
            $level->save();
        }
        return $level;
    }

    /** @test */
    public function test_direct_investment_commission_excludes_level_1_override()
    {
        // Setup HNS user (tire_level = 2) and GSM user (tire_level = 1)
        $gsmUser = User::create([
            'name' => 'GSM User',
            'username' => 'gsm_user',
            'email' => 'gsm@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'hierarchy',
            'level_id' => 1,
            'is_active' => true,
        ]);

        $hnsUser = User::create([
            'name' => 'HNS User',
            'username' => 'hns_user',
            'email' => 'hns@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'hierarchy',
            'level_id' => 2,
            'parent_user_id' => $gsmUser->id,
            'is_active' => true,
        ]);

        // Create direct investment with HNS (tire_level = 2) as unit head
        $investment = Investment::create([
            'policy_number' => 'CDP-COL-00000001',
            'application_number' => 'APP-COL-00000001',
            'sales_code' => 'COL-0001',
            'reservation_date' => now()->format('Y-m-d'),
            'target_period_key' => now()->format('Y-m'),
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'investment_product_id' => $this->product->id,
            'investment_amount' => 100000,
            'business_type' => 'bank_deposit',
            'created_by' => $hnsUser->id,
            'unit_head_id' => $hnsUser->id,
            'investment_type' => 'direct',
        ]);

        Commission::generateForInvestment($investment);

        // Verify direct commission is created for HNS
        $uhCommission = Commission::where('investment_id', $investment->id)
            ->where('tier', 'unit_head')
            ->first();
        $this->assertNotNull($uhCommission);
        $this->assertEquals(10000, $uhCommission->commission_amount); // 10% of 100,000
        $this->assertEquals($hnsUser->id, $uhCommission->user_id);

        // Verify override/parent commission is NOT created for GSM (tire_level = 1)
        $parentCommission = Commission::where('investment_id', $investment->id)
            ->where('tier', 'parent')
            ->first();
        $this->assertNull($parentCommission);
    }

    /** @test */
    public function test_direct_investment_commission_calculates_override_normally_for_other_levels()
    {
        // Setup SGL user (tire_level = 14) and GL user (tire_level = 15)
        $sglUser = User::create([
            'name' => 'SGL User',
            'username' => 'sgl_user',
            'email' => 'sgl@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'hierarchy',
            'level_id' => 14,
            'is_active' => true,
        ]);

        $glUser = User::create([
            'name' => 'GL User',
            'username' => 'gl_user',
            'email' => 'gl@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'hierarchy',
            'level_id' => 15,
            'parent_user_id' => $sglUser->id,
            'is_active' => true,
        ]);

        // Create direct investment with GL (tire_level = 15) as unit head
        $investment = Investment::create([
            'policy_number' => 'CDP-COL-00000002',
            'application_number' => 'APP-COL-00000002',
            'sales_code' => 'COL-0002',
            'reservation_date' => now()->format('Y-m-d'),
            'target_period_key' => now()->format('Y-m'),
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'investment_product_id' => $this->product->id,
            'investment_amount' => 100000,
            'business_type' => 'bank_deposit',
            'created_by' => $glUser->id,
            'unit_head_id' => $glUser->id,
            'investment_type' => 'direct',
        ]);

        Commission::generateForInvestment($investment);

        // Verify direct commission is created for GL
        $uhCommission = Commission::where('investment_id', $investment->id)
            ->where('tier', 'unit_head')
            ->first();
        $this->assertNotNull($uhCommission);
        $this->assertEquals(10000, $uhCommission->commission_amount);
        $this->assertEquals($glUser->id, $uhCommission->user_id);

        // Verify override/parent commission IS created for SGL (tire_level = 14)
        $parentCommission = Commission::where('investment_id', $investment->id)
            ->where('tier', 'parent')
            ->first();
        $this->assertNotNull($parentCommission);
        $this->assertEquals(2000, $parentCommission->commission_amount); // 20% of 10,000
        $this->assertEquals($sglUser->id, $parentCommission->user_id);
    }

    /** @test */
    public function test_hierarchy_investment_commission_remains_unchanged()
    {
        // Setup SGL user (tire_level = 14) and GL user (tire_level = 15)
        $sglUser = User::create([
            'name' => 'SGL User 2',
            'username' => 'sgl_user_2',
            'email' => 'sgl2@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'hierarchy',
            'level_id' => 14,
            'is_active' => true,
        ]);

        $glUser = User::create([
            'name' => 'GL User 2',
            'username' => 'gl_user_2',
            'email' => 'gl2@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'hierarchy',
            'level_id' => 15,
            'parent_user_id' => $sglUser->id,
            'is_active' => true,
        ]);

        // Create hierarchy investment with GL (tire_level = 15) as unit head
        $investment = Investment::create([
            'policy_number' => 'CDP-COL-00000003',
            'application_number' => 'APP-COL-00000003',
            'sales_code' => 'COL-0003',
            'reservation_date' => now()->format('Y-m-d'),
            'target_period_key' => now()->format('Y-m'),
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'investment_product_id' => $this->product->id,
            'investment_amount' => 100000,
            'business_type' => 'bank_deposit',
            'created_by' => $glUser->id,
            'unit_head_id' => $glUser->id,
            'investment_type' => 'hierarchy',
        ]);

        Commission::generateForInvestment($investment);

        // Verify direct commission is created for GL
        $uhCommission = Commission::where('investment_id', $investment->id)
            ->where('tier', 'unit_head')
            ->first();
        $this->assertNotNull($uhCommission);
        $this->assertEquals(10000, $uhCommission->commission_amount);

        // Verify override/parent commission IS created for SGL
        $parentCommission = Commission::where('investment_id', $investment->id)
            ->where('tier', 'parent')
            ->first();
        $this->assertNotNull($parentCommission);
        $this->assertEquals(2000, $parentCommission->commission_amount);
    }

    /** @test */
    public function test_direct_investment_excludes_level_1_from_earning_unit_head_commission()
    {
        // Setup GSM user (level_id = 1)
        $gsmUser = User::create([
            'name' => 'GSM User',
            'username' => 'gsm_user_3',
            'email' => 'gsm3@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'hierarchy',
            'level_id' => 1,
            'is_active' => true,
        ]);

        // Create direct investment with GSM (level_id = 1) as unit head
        $investment = Investment::create([
            'policy_number' => 'CDP-COL-00000004',
            'application_number' => 'APP-COL-00000004',
            'sales_code' => 'COL-0004',
            'reservation_date' => now()->format('Y-m-d'),
            'target_period_key' => now()->format('Y-m'),
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'investment_product_id' => $this->product->id,
            'investment_amount' => 100000,
            'business_type' => 'bank_deposit',
            'created_by' => $gsmUser->id,
            'unit_head_id' => $gsmUser->id,
            'investment_type' => 'direct',
        ]);

        Commission::generateForInvestment($investment);

        // Verify NO commission is created for GSM
        $uhCommission = Commission::where('investment_id', $investment->id)
            ->where('tier', 'unit_head')
            ->first();
        $this->assertNull($uhCommission);
    }
}
