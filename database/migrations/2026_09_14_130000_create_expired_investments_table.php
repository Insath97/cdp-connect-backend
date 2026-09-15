<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('expired_investments')) {
            Schema::create('expired_investments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('investment_id')->constrained('investments')->cascadeOnDelete()->unique();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->decimal('investment_amount', 15, 2);
                $table->enum('status', ['unpaid', 'paid'])->default('unpaid');
                $table->string('payment_method')->nullable();
                $table->string('transaction_number')->nullable();
                $table->text('remarks')->nullable();
                $table->string('image')->nullable();
                $table->dateTime('paid_at')->nullable();
                $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        // Create Permissions
        $permissions = [
            ['name' => 'Expired Business Index', 'group_name' => 'Expired Business Management Permissions', 'guard_name' => 'api'],
            ['name' => 'Expired Business Update', 'group_name' => 'Expired Business Management Permissions', 'guard_name' => 'api'],
            ['name' => 'Expired Investment Index', 'group_name' => 'Expired Investment Management Permissions', 'guard_name' => 'api'],
            ['name' => 'Expired Investment Update', 'group_name' => 'Expired Investment Management Permissions', 'guard_name' => 'api'],
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission['name'], 'guard_name' => $permission['guard_name']],
                ['group_name' => $permission['group_name']]
            );
        }

        $superAdmin = Role::where('name', 'Super Admin')->first();
        if ($superAdmin) {
            $superAdmin->givePermissionTo([
                'Expired Business Index',
                'Expired Business Update',
                'Expired Investment Index',
                'Expired Investment Update',
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $superAdmin = Role::where('name', 'Super Admin')->first();
        if ($superAdmin) {
            $superAdmin->revokePermissionTo([
                'Expired Business Index',
                'Expired Business Update',
                'Expired Investment Index',
                'Expired Investment Update',
            ]);
        }

        Permission::whereIn('name', [
            'Expired Business Index',
            'Expired Business Update',
            'Expired Investment Index',
            'Expired Investment Update',
        ])->delete();

        Schema::dropIfExists('expired_investments');
    }
};
