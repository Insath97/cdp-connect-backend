<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

class UserControllerSearchTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // Seed permissions
        $this->seed(\Database\Seeders\PermissionsSeeder::class);
        $this->seed(\Database\Seeders\LevelSeeder::class);

        $adminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'api']);
        $adminRole->syncPermissions(Permission::all());

        $this->adminUser = User::create([
            'name' => 'Super Admin User',
            'username' => 'super_admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'admin',
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

        // Create Branch
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
    }

    /** @test */
    public function test_user_index_can_search_by_name_and_id_number()
    {
        $token = auth('api')->login($this->adminUser);

        // Create two users
        User::create([
            'name' => 'Alice Smith',
            'username' => 'alice_s',
            'email' => 'alice@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'hierarchy',
            'employee_code' => 'EMP001',
            'id_type' => 'nic',
            'id_number' => '222222222V',
            'is_active' => true,
            'branch_id' => $this->branch->id,
        ]);

        User::create([
            'name' => 'Bob Builder',
            'username' => 'bob_b',
            'email' => 'bob@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'hierarchy',
            'employee_code' => 'EMP002',
            'id_type' => 'nic',
            'id_number' => '333333333V',
            'is_active' => true,
            'branch_id' => $this->branch->id,
        ]);

        User::create([
            'name' => 'Charlie Chaplin',
            'username' => 'charlie_c',
            'email' => 'charlie@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'hierarchy',
            'employee_code' => 'EMP003',
            'id_type' => 'nic',
            'id_number' => '444444 444 V',
            'is_active' => true,
            'branch_id' => $this->branch->id,
        ]);

        // Search by name "Alice"
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/users?search=Alice');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('Alice Smith', $response->json('data.data.0.name'));

        // Search by id_number "333333333V"
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/users?search=333333333V');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('Bob Builder', $response->json('data.data.0.name'));

        // Search by space-removed id_number "444444444V" matching "444444 444 V"
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/users?search=444444444V');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('Charlie Chaplin', $response->json('data.data.0.name'));

        // Search by space-containing id_number "22222 2222V" matching "222222222V"
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/users?search=22222%202222V');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('Alice Smith', $response->json('data.data.0.name'));

        // Search by inverted multi-word name "Smith Alice" matching "Alice Smith"
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/users?search=Smith%20Alice');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('Alice Smith', $response->json('data.data.0.name'));

        // Search by multi-word name "Bob Builder" matching "Bob Builder"
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/users?search=Bob%20Builder');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('Bob Builder', $response->json('data.data.0.name'));
    }

    /** @test */
    public function test_get_available_users_can_search_by_name_and_id_number()
    {
        $token = auth('api')->login($this->adminUser);

        // Create two users
        User::create([
            'name' => 'Alice Smith',
            'username' => 'alice_s',
            'email' => 'alice@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'hierarchy',
            'employee_code' => 'EMP001',
            'id_type' => 'nic',
            'id_number' => '222222222V',
            'is_active' => true,
            'branch_id' => $this->branch->id,
        ]);

        User::create([
            'name' => 'Bob Builder',
            'username' => 'bob_b',
            'email' => 'bob@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'hierarchy',
            'employee_code' => 'EMP002',
            'id_type' => 'nic',
            'id_number' => '333333333V',
            'is_active' => true,
            'branch_id' => $this->branch->id,
        ]);

        // Search by name "Alice" on available users list
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/users/list?search=Alice');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('Alice Smith', $response->json('data.0.name'));

        // Search by id_number "333333333V" on available users list
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/users/list?search=333333333V');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('Bob Builder', $response->json('data.0.name'));
    }

    /** @test */
    public function test_get_hierarchy_users_by_branch_can_search_by_name_and_id_number()
    {
        $token = auth('api')->login($this->adminUser);

        // Create two hierarchy users (level_id must be in [15, 16, 17])
        User::create([
            'name' => 'Alice Smith',
            'username' => 'alice_s',
            'email' => 'alice@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'hierarchy',
            'level_id' => 15,
            'employee_code' => 'EMP001',
            'id_type' => 'nic',
            'id_number' => '222222222V',
            'is_active' => true,
            'branch_id' => $this->branch->id,
        ]);

        User::create([
            'name' => 'Bob Builder',
            'username' => 'bob_b',
            'email' => 'bob@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'hierarchy',
            'level_id' => 16,
            'employee_code' => 'EMP002',
            'id_type' => 'nic',
            'id_number' => '333333333V',
            'is_active' => true,
            'branch_id' => $this->branch->id,
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/users/hierarchy-list?search=Alice');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('Alice Smith', $response->json('data.0.name'));

        // Search by id_number "333333333V" on hierarchy list
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/users/hierarchy-list?search=333333333V');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('Bob Builder', $response->json('data.0.name'));
    }
}
