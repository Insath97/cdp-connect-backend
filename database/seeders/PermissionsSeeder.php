<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            /* Access Management */
            ['name' => 'Permission Index', 'group_name' => 'Access Management Permissions'],
            ['name' => 'Permission Create', 'group_name' => 'Access Management Permissions'],
            ['name' => 'Permission Update', 'group_name' => 'Access Management Permissions'],
            ['name' => 'Permission Delete', 'group_name' => 'Access Management Permissions'],

            ['name' => 'Role Index', 'group_name' => 'Access Management Permissions'],
            ['name' => 'Role Create', 'group_name' => 'Access Management Permissions'],
            ['name' => 'Role Update', 'group_name' => 'Access Management Permissions'],
            ['name' => 'Role Delete', 'group_name' => 'Access Management Permissions'],

            /* User Management */
            ['name' => 'User Index', 'group_name' => 'User Management Permissions'],
            ['name' => 'User Create', 'group_name' => 'User Management Permissions'],
            ['name' => 'User Update', 'group_name' => 'User Management Permissions'],
            ['name' => 'User Delete', 'group_name' => 'User Management Permissions'],
            ['name' => 'User Toggle Status', 'group_name' => 'User Management Permissions'],

            /* Level Management */
            ['name' => 'Level Index', 'group_name' => 'Level Management Permissions'],
            ['name' => 'Level Create', 'group_name' => 'Level Management Permissions'],
            ['name' => 'Level Update', 'group_name' => 'Level Management Permissions'],
            ['name' => 'Level Delete', 'group_name' => 'Level Management Permissions'],

            /* Country Management */
            ['name' => 'Country Index', 'group_name' => 'Country Management Permissions'],
            ['name' => 'Country Create', 'group_name' => 'Country Management Permissions'],
            ['name' => 'Country Update', 'group_name' => 'Country Management Permissions'],
            ['name' => 'Country Delete', 'group_name' => 'Country Management Permissions'],
            ['name' => 'Country Toggle Status', 'group_name' => 'Country Management Permissions'],

            /* Province Management */
            ['name' => 'Province Index', 'group_name' => 'Province Management Permissions'],
            ['name' => 'Province Create', 'group_name' => 'Province Management Permissions'],
            ['name' => 'Province Update', 'group_name' => 'Province Management Permissions'],
            ['name' => 'Province Delete', 'group_name' => 'Province Management Permissions'],
            ['name' => 'Province Toggle Status', 'group_name' => 'Province Management Permissions'],

            /* Region Management */
            ['name' => 'Region Index', 'group_name' => 'Region Management Permissions'],
            ['name' => 'Region Create', 'group_name' => 'Region Management Permissions'],
            ['name' => 'Region Update', 'group_name' => 'Region Management Permissions'],
            ['name' => 'Region Delete', 'group_name' => 'Region Management Permissions'],
            ['name' => 'Region Toggle Status', 'group_name' => 'Region Management Permissions'],

            /* Zone Management */
            ['name' => 'Zone Index', 'group_name' => 'Zone Management Permissions'],
            ['name' => 'Zone Create', 'group_name' => 'Zone Management Permissions'],
            ['name' => 'Zone Update', 'group_name' => 'Zone Management Permissions'],
            ['name' => 'Zone Delete', 'group_name' => 'Zone Management Permissions'],
            ['name' => 'Zone Toggle Status', 'group_name' => 'Zone Management Permissions'],

            /* Branch Management */
            ['name' => 'Branch Index', 'group_name' => 'Branch Management Permissions'],
            ['name' => 'Branch Create', 'group_name' => 'Branch Management Permissions'],
            ['name' => 'Branch Update', 'group_name' => 'Branch Management Permissions'],
            ['name' => 'Branch Delete', 'group_name' => 'Branch Management Permissions'],
            ['name' => 'Branch Toggle Status', 'group_name' => 'Branch Management Permissions'],

            /* Investment Product Management */
            ['name' => 'Investment Product Index', 'group_name' => 'Investment Product Permissions'],
            ['name' => 'Investment Product Create', 'group_name' => 'Investment Product Permissions'],
            ['name' => 'Investment Product Update', 'group_name' => 'Investment Product Permissions'],
            ['name' => 'Investment Product Delete', 'group_name' => 'Investment Product Permissions'],
            ['name' => 'Investment Product Toggle Status', 'group_name' => 'Investment Product Permissions'],

            /* Target Management */
            ['name' => 'Target Index', 'group_name' => 'Target Management Permissions'],
            ['name' => 'Target Create', 'group_name' => 'Target Management Permissions'],
            ['name' => 'Target Update', 'group_name' => 'Target Management Permissions'],
            ['name' => 'Target Delete', 'group_name' => 'Target Management Permissions'],
            ['name' => 'My Targets', 'group_name' => 'Target Management Permissions'],

            /* Customer Management */
            ['name' => 'Customer Index', 'group_name' => 'Customer Management Permissions'],
            ['name' => 'Customer Create', 'group_name' => 'Customer Management Permissions'],
            ['name' => 'Customer Update', 'group_name' => 'Customer Management Permissions'],
            ['name' => 'Customer Delete', 'group_name' => 'Customer Management Permissions'],
            ['name' => 'Customer Restore', 'group_name' => 'Customer Management Permissions'],
            ['name' => 'Customer Force Delete', 'group_name' => 'Customer Management Permissions'],
            ['name' => 'Customer Toggle Status', 'group_name' => 'Customer Management Permissions'],

            /* Quotation Management */
            ['name' => 'Quotation Index', 'group_name' => 'Quotation Management Permissions'],
            ['name' => 'Quotation Create', 'group_name' => 'Quotation Management Permissions'],
            ['name' => 'Quotation Update', 'group_name' => 'Quotation Management Permissions'],
            ['name' => 'Quotation Delete', 'group_name' => 'Quotation Management Permissions'],
            ['name' => 'Quotation Restore', 'group_name' => 'Quotation Management Permissions'],
            ['name' => 'Quotation Force Delete', 'group_name' => 'Quotation Management Permissions'],
            ['name' => 'Quotation Toggle Status', 'group_name' => 'Quotation Management Permissions'],

            /* Investment Management */
            ['name' => 'Investment Index', 'group_name' => 'Investment Management Permissions'],
            ['name' => 'Investment Create', 'group_name' => 'Investment Management Permissions'],
            ['name' => 'Investment Update', 'group_name' => 'Investment Management Permissions'],
            ['name' => 'Investment Delete', 'group_name' => 'Investment Management Permissions'],
            ['name' => 'Investment Approve', 'group_name' => 'Investment Management Permissions'],
            ['name' => 'Investment Certificate', 'group_name' => 'Investment Management Permissions'],

            /* Target Progress */
            ['name' => 'Target Progress Index', 'group_name' => 'Target Progress Permissions'],

            /* Receipt Management */
            ['name' => 'Receipt Index', 'group_name' => 'Receipt Management Permissions'],
            ['name' => 'Receipt Create', 'group_name' => 'Receipt Management Permissions'],

            /* Dashboard Management */
            ['name' => 'Dashboard View', 'group_name' => 'Dashboard Management Permissions'],
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission['name'],
                'group_name' => $permission['group_name'],
                'guard_name' => 'api',
            ]);
        }
    }
}
