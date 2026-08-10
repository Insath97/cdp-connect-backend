<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Investment;
use App\Models\InvestmentProduct;
use App\Models\Commission;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class PlanWiseAdminReportTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $branch;
    protected $product;
    protected $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // Seed all permissions
        $this->seed(\Database\Seeders\PermissionsSeeder::class);

        // Setup Super Admin role permissions
        $adminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'api']);
        $adminRole->syncPermissions(Permission::all());

        // Create main Admin user
        $this->adminUser = User::create([
            'name' => 'Super Admin User',
            'username' => 'super_admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'admin',
            'is_head_office_user' => true,
            'employee_code' => 'ADM001',
            'id_type' => 'nic',
            'id_number' => '111111111V',
            'is_active' => true,
        ]);
        $this->adminUser->assignRole('Super Admin');

        // Create geolocation models
        $country = \App\Models\Country::create([
            'name' => 'Sri Lanka',
            'code' => 'LK',
            'is_active' => true,
        ]);

        $province = \App\Models\Province::create([
            'name' => 'Western Province',
            'code' => 'WP',
            'country_id' => $country->id,
            'is_active' => true,
        ]);

        $zone = \App\Models\Zone::create([
            'name' => 'Colombo Zone',
            'code' => 'ZONE-COL',
            'province_id' => $province->id,
            'is_active' => true,
        ]);

        $region = \App\Models\Region::create([
            'name' => 'Colombo Region',
            'code' => 'REG-COL',
            'zone_id' => $zone->id,
            'is_active' => true,
        ]);

        // Create testing Branch
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

        // Create investment product
        $this->product = InvestmentProduct::create([
            'name' => 'Fixed Plan A',
            'code' => 'FPA001',
            'duration_months' => 12,
            'roi_percentage' => 12.5,
            'unit_head_commission_pct' => 5.0,
            'parent_commission_pct' => 2.0,
            'is_active' => true,
            'plan_type' => 'normal',
        ]);

        // Create customer
        $this->customer = Customer::create([
            'full_name' => 'John Doe',
            'name_with_initials' => 'J. Doe',
            'customer_code' => 'CUST001',
            'email' => 'john@example.com',
            'phone_primary' => '0771234567',
            'date_of_birth' => '1995-01-01',
            'id_type' => 'nic',
            'id_number' => '999999999V',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function test_unauthorized_user_is_blocked_from_plan_wise_admin_report()
    {
        // Create user without permission
        $regularUser = User::create([
            'name' => 'Regular User',
            'username' => 'regular_u',
            'email' => 'regular@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'customer',
            'is_active' => true,
        ]);

        $token = auth('api')->login($regularUser);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/reports/plan-wise-admin');

        $response->assertStatus(403);
    }

    /** @test */
    public function test_authorized_user_can_access_plan_wise_admin_report_with_manager_hierarchy()
    {
        $token = auth('api')->login($this->adminUser);

        // Create another admin user reporting to the super admin
        $childAdmin = User::create([
            'name' => 'Child Admin',
            'username' => 'child_admin',
            'email' => 'child@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'admin',
            'is_head_office_user' => true,
            'parent_user_id' => $this->adminUser->id,
            'employee_code' => 'ADM002',
            'id_type' => 'nic',
            'id_number' => '222222222V',
            'is_active' => true,
            'branch_id' => $this->branch->id,
        ]);

        // Create a non-head office admin user
        $nonHeadOfficeAdmin = User::create([
            'name' => 'Non Head Office Admin',
            'username' => 'non_ho_admin',
            'email' => 'nonho@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'admin',
            'is_head_office_user' => false,
            'parent_user_id' => $this->adminUser->id,
            'employee_code' => 'ADM003',
            'id_type' => 'nic',
            'id_number' => '333333333V',
            'is_active' => true,
            'branch_id' => $this->branch->id,
        ]);

        // Create an investment with childAdmin as the Unit Head
        $investment = Investment::create([
            'policy_number' => 'POL0001',
            'application_number' => 'APP0001',
            'sales_code' => 'SC0001',
            'customer_id' => $this->customer->id,
            'investment_product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'unit_head_id' => $childAdmin->id,
            'investment_amount' => 100000.00,
            'payment_proof' => 'proof.jpg',
            'reservation_date' => Carbon::now(),
            'target_period_key' => Carbon::now()->format('Y-m'),
            'status' => 'approved',
            'created_by' => $childAdmin->id,
        ]);

        // Create an investment with nonHeadOfficeAdmin as the Unit Head
        $investment2 = Investment::create([
            'policy_number' => 'POL0002',
            'application_number' => 'APP0002',
            'sales_code' => 'SC0002',
            'customer_id' => $this->customer->id,
            'investment_product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'unit_head_id' => $nonHeadOfficeAdmin->id,
            'investment_amount' => 50000.00,
            'payment_proof' => 'proof2.jpg',
            'reservation_date' => Carbon::now(),
            'target_period_key' => Carbon::now()->format('Y-m'),
            'status' => 'approved',
            'created_by' => $nonHeadOfficeAdmin->id,
        ]);

        // Generate commission
        Commission::generateForInvestment($investment);
        Commission::generateForInvestment($investment2);

        // Fetch report
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/reports/plan-wise-admin');

        $response->assertStatus(200);

        // Assert response structure and content
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'hierarchy_tree',
                'overall_summary' => [
                    'total_business',
                    'total_business_count',
                    'total_cancelled_business',
                    'total_cancelled_count',
                    'total_recovery_amount',
                    'period' => ['from', 'to']
                ]
            ]
        ]);

        // Assert that ONLY the childAdmin's business is captured in overall summary
        // (total_business is 100000.0, NOT 150000.0)
        $response->assertJsonFragment([
            'total_business' => 100000.0,
            'total_business_count' => 1,
        ]);

        // Assert that the tree structures contains super_admin as root and ONLY child_admin as subordinate
        $data = $response->json('data');
        $this->assertCount(1, $data['hierarchy_tree']);
        
        $root = $data['hierarchy_tree'][0];
        $this->assertEquals($this->adminUser->id, $root['id']);
        $this->assertCount(1, $root['subordinates']); // Only childAdmin, nonHeadOfficeAdmin is excluded

        $subordinate = $root['subordinates'][0];
        $this->assertEquals($childAdmin->id, $subordinate['id']);
        
        // Assert childAdmin got direct commission (5% of 100,000 = 5,000)
        $this->assertEquals(5000.0, $subordinate['metrics']['personal_commission']);
    }
}
