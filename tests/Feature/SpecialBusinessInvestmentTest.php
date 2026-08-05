<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\InvestmentProduct;
use App\Models\Investment;
use App\Models\Target;
use App\Models\Country;
use App\Models\Province;
use App\Models\Zone;
use App\Models\Region;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class SpecialBusinessInvestmentTest extends TestCase
{
    use RefreshDatabase;

    protected $authorizedUser;
    protected $unauthorizedUser;
    protected $branch;
    protected $customer;
    protected $specialProduct;
    protected $normalProduct;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        // Seed permissions
        $this->seed(\Database\Seeders\PermissionsSeeder::class);
        $this->seed(\Database\Seeders\LevelSeeder::class);

        // Create roles
        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'api']);
        $superAdminRole->syncPermissions(Permission::all());

        $consultantRole = Role::firstOrCreate(['name' => 'Senior Consultant', 'guard_name' => 'api']);
        // Sync permissions for consultant, but EXCLUDE Special Business Create
        $consultantPermissions = Permission::where('name', '!=', 'Special Business Create')->get();
        $consultantRole->syncPermissions($consultantPermissions);

        // Create users
        $this->authorizedUser = User::create([
            'name' => 'Authorized Admin',
            'username' => 'auth_admin',
            'email' => 'auth_admin@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'admin',
            'is_active' => true,
        ]);
        $this->authorizedUser->assignRole($superAdminRole);

        $this->unauthorizedUser = User::create([
            'name' => 'Unauthorized Consultant',
            'username' => 'unauth_consultant',
            'email' => 'consultant@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'hierarchy',
            'level_id' => 14,
            'is_active' => true,
        ]);
        $this->unauthorizedUser->assignRole($consultantRole);

        // Setup Geographical structures & Branch
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

        // Create a customer
        $this->customer = Customer::create([
            'full_name' => 'Jane Doe',
            'name_with_initials' => 'J. Doe',
            'customer_code' => 'CUST001',
            'id_type' => 'nic',
            'id_number' => '123456789V',
            'email' => 'jane@example.com',
            'date_of_birth' => '1995-01-01',
            'phone_primary' => '0771234567',
        ]);

        // Create special investment product
        $this->specialProduct = InvestmentProduct::create([
            'name' => 'Special Growth Plan',
            'code' => 'SGP001',
            'roi_percentage' => 12.00,
            'duration_months' => 24,
            'plan_type' => 'special',
            'is_active' => true,
        ]);

        // Create normal investment product
        $this->normalProduct = InvestmentProduct::create([
            'name' => 'Savings Plan',
            'code' => 'SP001',
            'roi_percentage' => 10.00,
            'duration_months' => 12,
            'is_active' => true,
        ]);

        // Create targets so targets verification doesn't fail
        Target::create([
            'user_id' => $this->authorizedUser->id,
            'assigned_by' => $this->authorizedUser->id,
            'period_type' => 'month',
            'period_key' => now()->format('Y-m'),
            'target_amount' => 1000000.00,
            'is_active' => true,
        ]);

        Target::create([
            'user_id' => $this->unauthorizedUser->id,
            'assigned_by' => $this->authorizedUser->id,
            'period_type' => 'month',
            'period_key' => now()->format('Y-m'),
            'target_amount' => 1000000.00,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function test_creating_special_business_without_permission_fails()
    {
        $token = auth('api')->login($this->unauthorizedUser);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/investments', [
                'reservation_date' => now()->format('Y-m-d'),
                'customer_id' => $this->customer->id,
                'branch_id' => $this->branch->id,
                'investment_product_id' => $this->normalProduct->id,
                'investment_amount' => 50000.00,
                'business_type' => 'special',
                'special_business_description' => 'Test special business description',
                'bank' => 'HNB',
                'payment_type' => 'full_payment',
                'initial_payment' => 50000.00,
                'unit_head_id' => $this->unauthorizedUser->id,
            ]);

        $response->assertStatus(403)
            ->assertJsonFragment([
                'status' => 'error',
                'message' => 'You cannot create this special business investment because you do not have the required permission.'
            ]);
    }

    /** @test */
    public function test_creating_special_business_without_description_succeeds()
    {
        $token = auth('api')->login($this->authorizedUser);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/investments', [
                'reservation_date' => now()->format('Y-m-d'),
                'customer_id' => $this->customer->id,
                'branch_id' => $this->branch->id,
                'investment_product_id' => $this->normalProduct->id,
                'investment_amount' => 50000.00,
                'business_type' => 'special',
                'bank' => 'HNB',
                'payment_type' => 'full_payment',
                'initial_payment' => 50000.00,
                'unit_head_id' => $this->authorizedUser->id,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('investments', [
            'investment_product_id' => $this->normalProduct->id,
            'business_type' => 'special',
            'special_business_description' => null,
        ]);
    }

    /** @test */
    public function test_creating_special_business_with_special_plan_succeeds_without_signature()
    {
        $token = auth('api')->login($this->authorizedUser);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/investments', [
                'reservation_date' => now()->format('Y-m-d'),
                'customer_id' => $this->customer->id,
                'branch_id' => $this->branch->id,
                'investment_product_id' => $this->specialProduct->id,
                'investment_amount' => 50000.00,
                'business_type' => 'special',
                'special_business_description' => 'A special description for this investment',
                'bank' => 'HNB',
                'payment_type' => 'full_payment',
                'initial_payment' => 50000.00,
                'unit_head_id' => $this->authorizedUser->id,
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('investments', [
            'investment_product_id' => $this->specialProduct->id,
            'business_type' => 'special',
            'special_business_description' => 'A special description for this investment',
            'signature_document' => null
        ]);
    }
}
