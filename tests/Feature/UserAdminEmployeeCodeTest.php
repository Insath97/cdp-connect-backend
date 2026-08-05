<?php

namespace Tests\Feature;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

class UserAdminEmployeeCodeTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->seed(\Database\Seeders\PermissionsSeeder::class);

        $adminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'api']);
        $adminRole->syncPermissions(Permission::all());

        // Create the Branch Coordinator role since the request validation requires it to exist
        Role::firstOrCreate(['name' => 'Branch Coordinator', 'guard_name' => 'api']);

        $this->adminUser = User::create([
            'name' => 'Super Admin User',
            'username' => 'super_admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'admin',
            'is_active' => true,
        ]);
        $this->adminUser->assignRole('Super Admin');
    }

    /** @test */
    public function test_creating_admin_without_employee_code_fails_validation()
    {
        $token = auth('api')->login($this->adminUser);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/users', [
                'name' => 'Test Admin',
                'username' => 'test_admin',
                'email' => 'testadmin@example.com',
                'password' => 'password123',
                'user_type' => 'admin',
                'role' => 'Branch Coordinator',
                'id_type' => 'nic',
                'id_number' => '123456789V',
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'field' => 'employee_code'
        ]);
    }

    /** @test */
    public function test_creating_admin_with_employee_code_succeeds_and_saves_code()
    {
        $token = auth('api')->login($this->adminUser);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/users', [
                'name' => 'Test Admin',
                'username' => 'test_admin',
                'email' => 'testadmin@example.com',
                'password' => 'password123',
                'user_type' => 'admin',
                'role' => 'Branch Coordinator',
                'employee_code' => 'ADM001',
                'id_type' => 'nic',
                'id_number' => '123456789V',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'username' => 'test_admin',
            'user_type' => 'admin',
            'employee_code' => 'ADM001',
        ]);
    }

    /** @test */
    public function test_updating_admin_employee_code_succeeds()
    {
        $adminToUpdate = User::create([
            'name' => 'Target Admin',
            'username' => 'target_admin',
            'email' => 'target_admin@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'admin',
            'employee_code' => 'ADM002',
            'id_type' => 'nic',
            'id_number' => '987654321V',
            'is_active' => true,
        ]);

        $token = auth('api')->login($this->adminUser);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->putJson('/api/v1/users/' . $adminToUpdate->id, [
                'employee_code' => 'ADM002_UPDATED',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id' => $adminToUpdate->id,
            'employee_code' => 'ADM002_UPDATED',
        ]);
    }
}
