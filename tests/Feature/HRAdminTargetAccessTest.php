<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Target;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class HRAdminTargetAccessTest extends TestCase
{
    use RefreshDatabase;

    protected $hrAdmin;
    protected $targetUser;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Seed Permissions & Levels
        $this->seed(\Database\Seeders\PermissionsSeeder::class);
        $this->seed(\Database\Seeders\LevelSeeder::class);

        $hrAdminRole = Role::firstOrCreate(['name' => 'HR Admin', 'guard_name' => 'api']);
        $hrAdminRole->syncPermissions(Permission::all());

        $this->hrAdmin = User::create([
            'name' => 'HR Admin User',
            'username' => 'hr_admin',
            'email' => 'hr_admin@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'admin',
            'is_active' => true,
        ]);
        $this->hrAdmin->assignRole('HR Admin');

        $this->targetUser = User::create([
            'name' => 'Sales Consultant',
            'username' => 'sales_consultant',
            'email' => 'consultant@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'hierarchy',
            'level_id' => 14,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function test_hr_admin_can_create_target_for_any_user_without_parent_target()
    {
        $token = auth('api')->login($this->hrAdmin);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/targets', [
                'user_id' => $this->targetUser->id,
                'period_type' => 'month',
                'period_key' => '2026-08',
                'target_amount' => 500000,
                'status' => 'active',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'message' => 'Target created successfully',
            ]);

        $this->assertDatabaseHas('targets', [
            'user_id' => $this->targetUser->id,
            'assigned_by' => $this->hrAdmin->id,
            'target_amount' => 500000,
            'period_key' => '2026-08',
        ]);
    }

    /** @test */
    public function test_hr_admin_can_view_targets_index_and_filter_by_user()
    {
        Target::create([
            'user_id' => $this->targetUser->id,
            'assigned_by' => $this->hrAdmin->id,
            'period_type' => 'month',
            'period_key' => '2026-08',
            'target_amount' => 300000,
            'status' => 'active',
        ]);

        $token = auth('api')->login($this->hrAdmin);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/targets?user_id=' . $this->targetUser->id . '&period_key=2026-08');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);
    }

    /** @test */
    public function test_hr_admin_can_update_target()
    {
        $target = Target::create([
            'user_id' => $this->targetUser->id,
            'assigned_by' => $this->hrAdmin->id,
            'period_type' => 'month',
            'period_key' => '2026-08',
            'target_amount' => 300000,
            'status' => 'active',
        ]);

        $token = auth('api')->login($this->hrAdmin);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->putJson('/api/v1/targets/' . $target->id, [
                'target_amount' => 600000,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        $this->assertDatabaseHas('targets', [
            'id' => $target->id,
            'target_amount' => 600000,
        ]);
    }

    /** @test */
    public function test_hr_admin_can_delete_target()
    {
        $target = Target::create([
            'user_id' => $this->targetUser->id,
            'assigned_by' => $this->hrAdmin->id,
            'period_type' => 'month',
            'period_key' => '2026-08',
            'target_amount' => 300000,
            'status' => 'active',
        ]);

        $token = auth('api')->login($this->hrAdmin);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->deleteJson('/api/v1/targets/' . $target->id);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        $this->assertDatabaseMissing('targets', [
            'id' => $target->id,
        ]);
    }

    /** @test */
    public function test_hr_admin_can_view_target_progress_for_any_user()
    {
        Target::create([
            'user_id' => $this->targetUser->id,
            'assigned_by' => $this->hrAdmin->id,
            'period_type' => 'month',
            'period_key' => '2026-08',
            'target_amount' => 300000,
            'status' => 'active',
        ]);

        $token = auth('api')->login($this->hrAdmin);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/target-progress?user_id=' . $this->targetUser->id . '&period_key=2026-08');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);
    }
}
