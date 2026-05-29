<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Alter investments table: add business_type and make payment_proof nullable
        Schema::table('investments', function (Blueprint $table) {
            $table->string('business_type')->default('bank_deposit')->after('payment_type');
            $table->string('payment_proof')->nullable()->change();
        });

        // 2. Create billings table
        Schema::create('billings', function (Blueprint $table) {
            $table->id();
            $table->string('billing_number')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('investment_id')->constrained('investments')->cascadeOnDelete();
            $table->foreignId('investment_product_id')->constrained('investment_products')->cascadeOnDelete();
            $table->decimal('investment_amount', 15, 2);
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->enum('status', ['pending', 'received'])->default('pending');
            $table->timestamps();
        });

        // 3. Insert Permissions
        $permissions = [
            ['name' => 'Billing Index', 'group_name' => 'Billing Management Permissions', 'guard_name' => 'api'],
            ['name' => 'Billing Update Status', 'group_name' => 'Billing Management Permissions', 'guard_name' => 'api'],
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate($permission);
        }

        $superAdmin = Role::where('name', 'Super Admin')->first();
        if ($superAdmin) {
            $superAdmin->givePermissionTo(['Billing Index', 'Billing Update Status']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. Remove permissions from Super Admin and delete them
        $superAdmin = Role::where('name', 'Super Admin')->first();
        if ($superAdmin) {
            $superAdmin->revokePermissionTo(['Billing Index', 'Billing Update Status']);
        }
        Permission::whereIn('name', ['Billing Index', 'Billing Update Status'])->delete();

        // 2. Drop billings table
        Schema::dropIfExists('billings');

        // 3. Revert investments table changes
        Schema::table('investments', function (Blueprint $table) {
            $table->dropColumn('business_type');
            $table->string('payment_proof')->nullable(false)->change();
        });
    }
};
