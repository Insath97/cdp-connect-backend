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
use App\Models\Target;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class HierarchyChangeTest extends TestCase
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
    public function test_hierarchy_change_splits_targets_and_commissions_correctly()
    {
        $periodKey = now()->format('Y-m');

        // 1. Setup levels
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

        // 2. Setup Managers and Agent
        $parentY = User::create([
            'name' => 'Manager Y',
            'username' => 'manager_y',
            'email' => 'y@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'hierarchy',
            'level_id' => $sglLevel->id,
            'is_active' => true,
        ]);

        $parentZ = User::create([
            'name' => 'Manager Z',
            'username' => 'manager_z',
            'email' => 'z@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'hierarchy',
            'level_id' => $sglLevel->id,
            'is_active' => true,
        ]);

        $agentX = User::create([
            'name' => 'Agent X',
            'username' => 'agent_x',
            'email' => 'x@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'hierarchy',
            'level_id' => $glLevel->id,
            'parent_user_id' => $parentY->id,
            'is_active' => true,
        ]);

        // 3. Setup targets
        $targetY = Target::create([
            'user_id' => $parentY->id,
            'assigned_by' => $parentY->id,
            'period_type' => 'month',
            'period_key' => $periodKey,
            'target_amount' => 1000000,
            'current_amount' => 1000000,
            'achieved_amount' => 0,
            'achievement_percentage' => 0,
            'status' => 'active',
        ]);

        $targetZ = Target::create([
            'user_id' => $parentZ->id,
            'assigned_by' => $parentZ->id,
            'period_type' => 'month',
            'period_key' => $periodKey,
            'target_amount' => 1000000,
            'current_amount' => 1000000,
            'achieved_amount' => 0,
            'achievement_percentage' => 0,
            'status' => 'active',
        ]);

        $targetX = Target::create([
            'user_id' => $agentX->id,
            'assigned_by' => $parentY->id,
            'period_type' => 'month',
            'period_key' => $periodKey,
            'target_amount' => 1000000,
            'current_amount' => 1000000,
            'achieved_amount' => 0,
            'achievement_percentage' => 0,
            'status' => 'active',
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
            'unit_head_commission_pct' => 10,
            'parent_commission_pct' => 20,
        ]);

        // 4. Create Investment 1 (Agent X reports to Manager Y)
        $investment1 = Investment::create([
            'policy_number' => 'CDP-COL-11111111',
            'application_number' => 'APP-COL-11111111',
            'sales_code' => 'COL-1111',
            'reservation_date' => now()->format('Y-m-d'),
            'target_period_key' => $periodKey,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'investment_product_id' => $product->id,
            'investment_amount' => 100000.00,
            'business_type' => 'bank_deposit',
            'payment_proof' => 'test.jpg',
            'created_by' => $agentX->id,
            'unit_head_id' => $agentX->id,
            'investment_type' => 'hierarchy',
            'status' => 'pending',
        ]);
        $investment1->saveHierarchySnapshot();

        // Approve Investment 1
        $investment1->update(['status' => 'approved']);
        Target::syncInvestmentAchievement($investment1);
        Commission::generateForInvestment($investment1);

        // Assert Y got the credit for Investment 1
        $targetY->refresh();
        $this->assertEquals(100000.00, $targetY->achieved_amount);

        // Assert Y's override commission was created
        $commY = Commission::where('investment_id', $investment1->id)->where('tier', 'parent')->first();
        $this->assertNotNull($commY);
        $this->assertEquals($parentY->id, $commY->user_id);

        // 5. Change hierarchy: Move Agent X to Manager Z
        $agentX->update(['parent_user_id' => $parentZ->id]);

        // 6. Create Investment 2 (Agent X reports to Manager Z)
        $investment2 = Investment::create([
            'policy_number' => 'CDP-COL-22222222',
            'application_number' => 'APP-COL-22222222',
            'sales_code' => 'COL-2222',
            'reservation_date' => now()->format('Y-m-d'),
            'target_period_key' => $periodKey,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'investment_product_id' => $product->id,
            'investment_amount' => 200000.00,
            'business_type' => 'bank_deposit',
            'payment_proof' => 'test.jpg',
            'created_by' => $agentX->id,
            'unit_head_id' => $agentX->id,
            'investment_type' => 'hierarchy',
            'status' => 'pending',
        ]);
        $investment2->saveHierarchySnapshot();

        // Approve Investment 2
        $investment2->update(['status' => 'approved']);
        Target::syncInvestmentAchievement($investment2);
        Commission::generateForInvestment($investment2);

        // Assert Z got the credit for Investment 2
        $targetZ->refresh();
        $this->assertEquals(200000.00, $targetZ->achieved_amount);

        // Assert Z's override commission was created
        $commZ = Commission::where('investment_id', $investment2->id)->where('tier', 'parent')->first();
        $this->assertNotNull($commZ);
        $this->assertEquals($parentZ->id, $commZ->user_id);

        // 7. Perform target recalculation
        Target::recalculateForUser($parentY->id, $periodKey);
        Target::recalculateForUser($parentZ->id, $periodKey);
        Target::recalculateForUser($agentX->id, $periodKey);

        $targetY->refresh();
        $targetZ->refresh();
        $targetX->refresh();

        // Asserts after target recalculation (should still be historically isolated)
        $this->assertEquals(100000.00, $targetY->achieved_amount);
        $this->assertEquals(200000.00, $targetZ->achieved_amount);
        $this->assertEquals(300000.00, $targetX->achieved_amount);

        // 8. Regenerate Period Commissions
        Commission::where('period_key', $periodKey)->delete();
        Commission::generateForInvestment($investment1);
        Commission::generateForInvestment($investment2);

        $commYRecalculated = Commission::where('investment_id', $investment1->id)->where('tier', 'parent')->first();
        $commZRecalculated = Commission::where('investment_id', $investment2->id)->where('tier', 'parent')->first();

        $this->assertNotNull($commYRecalculated);
        $this->assertEquals($parentY->id, $commYRecalculated->user_id);

        $this->assertNotNull($commZRecalculated);
        $this->assertEquals($parentZ->id, $commZRecalculated->user_id);
    }
}
