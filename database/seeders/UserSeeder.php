<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $role = Role::firstOrCreate(['guard_name' => 'api', 'name' => 'Super Admin']);

        $allPermissions = Permission::all();
        $role->syncPermissions($allPermissions);

        $user = User::create([
            'name' => 'Development Admin',
            'profile_image' => '/image',
            'email' => 'dev@localhost.com',
            'password' => bcrypt('password'),
            'username' => 'devadmin',
            'user_type' => 'admin',
            'is_active' => true,
            'can_login' => true,
        ]);

        $user->assignRole('Super Admin');

        $this->command->info('Development admin user created!');
        $this->command->info('Email: dev@localhost.com');
        $this->command->info('Password: password');
    }
}
