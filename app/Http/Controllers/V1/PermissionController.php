<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePermissionRequest;
use App\Http\Requests\UpdatePermissionRequest;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);

            $query = Permission::query();

            // Search
            if ($request->has('search') && $request->search != '') {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('guard_name', 'LIKE', "%{$search}%")
                        ->orWhere('group_name', 'LIKE', "%{$search}%");
                });
            }

            // Filter by guard name
            if ($request->has('guard_name')) {
                $query->where('guard_name', $request->guard_name);
            }

            // Filter by group name
            if ($request->has('group_name')) {
                $query->where('group_name', $request->group_name);
            }

            $query->orderBy('group_name', 'asc')->orderBy('name', 'asc');

            $permissions = $query->paginate($perPage);

            if ($permissions->isEmpty()) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'No permissions found',
                    'data' => []
                ], 200);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Permissions retrieved successfully',
                'data' => $permissions
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve permissions',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function create() {}

    public function store(CreatePermissionRequest $request)
    {
        try {
            $data = $request->validated();

            $data['guard_name'] = 'api';

            $permission = Permission::create($data);

            return response()->json([
                'status' => 'success',
                'message' => 'Permission created successfully',
                'data' => $permission
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create permission',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $permission = Permission::find($id);

            if (!$permission) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Permission not found',
                    'data' => []
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Permission retrieved successfully',
                'data' => $permission
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve permission',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function edit(string $id) {}

    public function update(UpdatePermissionRequest $request, string $id)
    {
        try {
            $data = $request->validated();

            $permission = Permission::find($id);

            if (!$permission) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Permission not found',
                    'data' => []
                ], 404);
            }

            if (isset($data['guard_name'])) {
                unset($data['guard_name']);
            }

            $permission->update($data);

            return response()->json([
                'status' => 'success',
                'message' => 'Permission updated successfully',
                'data' => $permission
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update permission',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $permission = Permission::find($id);

            if (!$permission) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Permission not found',
                    'data' => []
                ], 404);
            }

            // Check if permission is assigned to any role
            if ($permission->roles()->count() > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot delete permission. It is assigned to one or more roles.',
                    'data' => [
                        'assigned_roles_count' => $permission->roles()->count()
                    ]
                ], 422);
            }

            $permission->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Permission deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete permission',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
