<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Investment;
use App\Models\InvestmentProduct;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class WelcomeCallReportTest extends TestCase
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
    public function test_unauthorized_user_is_blocked_from_welcome_call_report()
    {
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
            ->getJson('/api/v1/reports/welcome-call');

        $response->assertStatus(403);
    }

    /** @test */
    public function test_authorized_user_can_access_welcome_call_report()
    {
        // Create some sample investments with various welcome call statuses
        Investment::create([
            'policy_number' => 'POL-WC-001',
            'application_number' => 'APP-WC-001',
            'sales_code' => 'SC001',
            'customer_id' => $this->customer->id,
            'investment_product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'unit_head_id' => $this->adminUser->id,
            'investment_amount' => 150000.00,
            'payment_proof' => 'proof.jpg',
            'reservation_date' => Carbon::now(),
            'target_period_key' => Carbon::now()->format('Y-m'),
            'status' => 'approved',
            'approved_at' => Carbon::now(),
            'created_by' => $this->adminUser->id,
            'welcome_call_status' => 'completed',
            'welcome_call_notes' => 'Call was successful. Customer confirmed details.',
            'welcome_call_by' => $this->adminUser->id,
            'welcome_call_at' => Carbon::now(),
        ]);

        Investment::create([
            'policy_number' => 'POL-WC-002',
            'application_number' => 'APP-WC-002',
            'sales_code' => 'SC002',
            'customer_id' => $this->customer->id,
            'investment_product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'unit_head_id' => $this->adminUser->id,
            'investment_amount' => 200000.00,
            'payment_proof' => 'proof2.jpg',
            'reservation_date' => Carbon::now(),
            'target_period_key' => Carbon::now()->format('Y-m'),
            'status' => 'approved',
            'approved_at' => Carbon::now(),
            'created_by' => $this->adminUser->id,
            'welcome_call_status' => 'pending',
        ]);

        $token = auth('api')->login($this->adminUser);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/reports/welcome-call');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'current_page',
                    'data' => [
                        '*' => [
                            'id',
                            'policy_number',
                            'application_number',
                            'investment_amount',
                            'status',
                            'approved_at',
                            'customer' => ['id', 'full_name', 'customer_code', 'phone_primary', 'email'],
                            'branch' => ['id', 'name', 'code'],
                            'agent' => ['id', 'name', 'employee_code'],
                            'plan' => ['id', 'name', 'duration_months'],
                            'welcome_call' => ['status', 'notes', 'at', 'by']
                        ]
                    ]
                ],
                'meta' => [
                    'summary' => [
                        'total_investments',
                        'total_amount',
                        'pending_count',
                        'completed_count',
                        'not_reachable_count',
                        'no_answer_count',
                        'others_count'
                    ]
                ]
            ]);

        // Check stats
        $meta = $response->json('meta');
        $this->assertEquals(2, $meta['summary']['total_investments']);
        $this->assertEquals(350000.00, $meta['summary']['total_amount']);
        $this->assertEquals(1, $meta['summary']['completed_count']);
        $this->assertEquals(1, $meta['summary']['pending_count']);
    }

    /** @test */
    public function test_welcome_call_report_filtering()
    {
        // Create 2 investments with different welcome call statuses
        Investment::create([
            'policy_number' => 'POL-FIL-001',
            'application_number' => 'APP-FIL-001',
            'sales_code' => 'SC001',
            'customer_id' => $this->customer->id,
            'investment_product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'unit_head_id' => $this->adminUser->id,
            'investment_amount' => 100000.00,
            'reservation_date' => Carbon::now(),
            'target_period_key' => Carbon::now()->format('Y-m'),
            'status' => 'approved',
            'approved_at' => Carbon::now(),
            'created_by' => $this->adminUser->id,
            'welcome_call_status' => 'completed',
        ]);

        Investment::create([
            'policy_number' => 'POL-FIL-002',
            'application_number' => 'APP-FIL-002',
            'sales_code' => 'SC002',
            'customer_id' => $this->customer->id,
            'investment_product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'unit_head_id' => $this->adminUser->id,
            'investment_amount' => 200000.00,
            'reservation_date' => Carbon::now(),
            'target_period_key' => Carbon::now()->format('Y-m'),
            'status' => 'approved',
            'approved_at' => Carbon::now(),
            'created_by' => $this->adminUser->id,
            'welcome_call_status' => 'pending',
        ]);

        $token = auth('api')->login($this->adminUser);

        // Filter by completed status
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/reports/welcome-call?welcome_call_status=completed');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('POL-FIL-001', $data[0]['policy_number']);
        
        // The summary should still reflect the overall state before filtering by status
        $meta = $response->json('meta');
        $this->assertEquals(2, $meta['summary']['total_investments']);
        $this->assertEquals(1, $meta['summary']['completed_count']);
        $this->assertEquals(1, $meta['summary']['pending_count']);

        // Search filter
        $responseSearch = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/reports/welcome-call?search=POL-FIL-002');
        $responseSearch->assertStatus(200);
        $searchData = $responseSearch->json('data.data');
        $this->assertCount(1, $searchData);
        $this->assertEquals('POL-FIL-002', $searchData[0]['policy_number']);
    }

    /** @test */
    public function test_welcome_call_report_filtering_by_reservation_date()
    {
        // Reservation date 5 days ago
        Investment::create([
            'policy_number' => 'POL-RES-001',
            'application_number' => 'APP-RES-001',
            'sales_code' => 'SC-RES-001',
            'customer_id' => $this->customer->id,
            'investment_product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'unit_head_id' => $this->adminUser->id,
            'investment_amount' => 100000.00,
            'payment_proof' => 'proof.jpg',
            'reservation_date' => Carbon::now()->subDays(5),
            'target_period_key' => Carbon::now()->subDays(5)->format('Y-m'),
            'status' => 'approved',
            'approved_at' => Carbon::now(),
            'created_by' => $this->adminUser->id,
            'welcome_call_status' => 'pending',
        ]);

        // Reservation date 15 days ago
        Investment::create([
            'policy_number' => 'POL-RES-002',
            'application_number' => 'APP-RES-002',
            'sales_code' => 'SC-RES-002',
            'customer_id' => $this->customer->id,
            'investment_product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'unit_head_id' => $this->adminUser->id,
            'investment_amount' => 200000.00,
            'payment_proof' => 'proof.jpg',
            'reservation_date' => Carbon::now()->subDays(15),
            'target_period_key' => Carbon::now()->subDays(15)->format('Y-m'),
            'status' => 'approved',
            'approved_at' => Carbon::now(),
            'created_by' => $this->adminUser->id,
            'welcome_call_status' => 'pending',
        ]);

        $token = auth('api')->login($this->adminUser);

        // Filter by reservation_date type with from_date 10 days ago and to_date now
        $fromDate = Carbon::now()->subDays(10)->format('Y-m-d');
        $toDate = Carbon::now()->format('Y-m-d');
        
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson("/api/v1/reports/welcome-call?date_type=reservation_date&from_date={$fromDate}&to_date={$toDate}");

        $response->assertStatus(200);
        $data = $response->json('data.data');
        
        $policyNumbers = collect($data)->pluck('policy_number')->toArray();
        $this->assertContains('POL-RES-001', $policyNumbers);
        $this->assertNotContains('POL-RES-002', $policyNumbers);
    }
}
