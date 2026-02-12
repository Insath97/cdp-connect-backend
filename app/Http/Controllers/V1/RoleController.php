<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);

            $query = Role::with('permissions');

            // Search
            if ($request->has('search') && $request->search != '') {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('guard_name', 'LIKE', "%{$search}%");
                });
            }

            $query->orderBy('name', 'asc');

            $roles = $query->paginate($perPage);

            if ($roles->isEmpty()) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'No roles found',
                    'data' => []
                ], 200);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Roles retrieved successfully',
                'data' => $roles
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve roles',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function create() {}

    public function store(CreateRoleRequest $request)
    {
        try {
            $data = $request->validated();

            $role = Role::create([
                'name' => $data['name'],
                'guard_name' => 'api',
                'is_protected' => $data['is_protected'] ?? false,
            ]);

            if (isset($data['permissions']) && count($data['permissions']) > 0) {
                $permissions = Permission::whereIn('id', $data['permissions'])->get();
                $role->syncPermissions($permissions);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Role created successfully',
                'data' => $role->load('permissions')
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create role',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $role = Role::with('permissions')->find($id);

            if (!$role) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Role not found',
                    'data' => []
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Role retrieved successfully',
                'data' => $role
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve role',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function edit(string $id) {}

    public function update(UpdateRoleRequest $request, string $id)
    {
        try {
            $data = $request->validated();

            $role = Role::find($id);

            if (!$role) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Role not found',
                    'data' => []
                ], 404);
            }

            if (isset($data['name'])) {
                $role->update(['name' => $data['name']]);
            }

            if (isset($data['is_protected'])) {
                $role->update(['is_protected' => $data['is_protected']]);
            }

            if (isset($data['permissions'])) {
                $permissions = Permission::whereIn('id', $data['permissions'])->get();
                $role->syncPermissions($permissions);
            }

            $role->load('permissions');

            return response()->json([
                'status' => 'success',
                'message' => 'Role updated successfully',
                'data' => $role
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update role',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $role = Role::find($id);

            if (!$role) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Role not found',
                    'data' => []
                ], 404);
            }

            if ($role->is_protected) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot delete protected system role: ' . $role->name,
                    'data' => [
                        'role_name' => $role->name,
                        'protected' => true
                    ]
                ], 422);
            }

            if ($role->users()->count() > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot delete role. It is assigned to one or more users.',
                    'data' => [
                        'assigned_users_count' => $role->users()->count()
                    ]
                ], 422);
            }

            $role->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Role deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete role',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function getAvailableRoles()
    {
        try {
            $user = auth('api')->user();

            $query = Role::query();

            if (!$user->isSuperAdmin() && $user->hasRole('Super Admin')) {
                $query->where('name', '!=', 'Super Admin');
            }

            $query->where('guard_name', 'api');

            $roles = $query->select('id', 'name', 'guard_name')->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Roles retrieved successfully',
                'data' => $roles
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve roles',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
