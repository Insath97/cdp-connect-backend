<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Mail\UserCreateMail;
use App\Models\User;
use App\Traits\FileUploadTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Spatie\Permission\Models\Role;

class UserController extends Controller implements HasMiddleware
{
    use FileUploadTrait;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:User Index', only: ['index', 'show']),
            new Middleware('permission:User Create', only: ['store']),
            new Middleware('permission:User Update', only: ['update']),
            new Middleware('permission:User Delete', only: ['destroy']),
            new Middleware('permission:User Toggle Status', only: ['toggleStatus']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = User::with(['level', 'branch', 'parent', 'roles']);

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function (Builder $builder) use ($search) {
                    $builder->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%");
                });
            }

            if ($request->has('user_type')) {
                $query->where('user_type', $request->user_type);
            }

            if ($request->has('role')) {
                $roleName = $request->role;
                $query->whereHas('roles', function ($q) use ($roleName) {
                    $q->where('name', $roleName);
                });
            }

            if ($request->has('level_id')) {
                $query->where('level_id', $request->level_id);
            }

            if ($request->has('branch_id')) {
                $query->where('branch_id', $request->branch_id);
            }

            if ($request->has('is_active')) {
                $request->boolean('is_active') ? $query->where('is_active', true) : $query->where('is_active', false);
            }

            $users = $query->paginate($perPage);

            // Transform data to hide pivot
            $users->getCollection()->transform(function ($user) {
                $userData = $user->toArray();
                if (isset($userData['roles'])) {
                    foreach ($userData['roles'] as &$role) {
                        unset($role['pivot']);
                    }
                }
                return $userData;
            });

            Log::info('Users index accessed', [
                'user_id' => Auth::id(),
                'filters' => $request->only(['search', 'user_type', 'level_id', 'branch_id', 'is_active', 'role']),
                'count' => $users->count()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Users retrieved successfully',
                'data' => $users
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve users',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function store(CreateUserRequest $request)
    {
        try {
            $currentUser = auth("api")->user();
            $data = $request->validated();

            // Hash password
            $rawPassword = $data['password'];
            $data['password'] = Hash::make($rawPassword);

            // For admin users, ensure hierarchy fields are null/ignored if sent
            if ($data['user_type'] === 'admin') {
                $data['level_id'] = null;
                $data['parent_user_id'] = null;
                $data['branch_id'] = null;
                $data['zone_id'] = null;
                $data['region_id'] = null;
                $data['province_id'] = null;
            }

            // Handle Profile Image
            $imagePath = $this->handleFileUpload($request, 'profile_image', null, 'users/profile', $data['email']);
            if ($imagePath) {
                $data['profile_image'] = $imagePath;
            }

            $user = User::create($data);

            // Assign Role
            if (isset($data['role'])) {
                $user->assignRole($data['role']);
            }

            // Send Email
            try {
                // Load relationships for enriched email data
                $user->load(['parent', 'level', 'branch', 'zone', 'region', 'province']);

                // Prepare email data - ONLY include existing data, no N/A
                $emailData = [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'username' => $user->username,
                        'email' => $user->email,
                        'user_type' => $user->user_type,
                        'email_verified_at' => $user->email_verified_at,
                    ],
                    'password' => $rawPassword,
                    'role' => $data['role'] ?? null,
                    'created_by' => $currentUser ? $currentUser->name : 'System',
                    'login_url' => config('app.frontend_url') ?? config('app.url'),
                ];

                // Only add relationships if they exist
                if ($user->parent) {
                    $emailData['parent_name'] = $user->parent->name;
                }

                if ($user->level) {
                    $emailData['level_name'] = $user->level->name;
                }

                if ($user->branch) {
                    $emailData['branch_name'] = $user->branch->name;
                }

                if ($user->zone) {
                    $emailData['zone_name'] = $user->zone->name;
                }

                if ($user->region) {
                    $emailData['region_name'] = $user->region->name;
                }

                if ($user->province) {
                    $emailData['province_name'] = $user->province->name;
                }

                Mail::to($user->email)->send(new UserCreateMail($emailData));

                Log::info('User creation email sent', [
                    'user_id' => $user->id,
                    'user_type' => $user->user_type
                ]);
            } catch (\Throwable $th) {
                Log::error('Failed to send user creation email: ' . $th->getMessage());
            }

            $user->load([
                'roles' => function ($q) {
                    $q->select('id', 'name');
                }
            ]);

            Log::info('User created', [
                'admin_id' => Auth::id(),
                'created_user_id' => $user->id,
                'user_type' => $user->user_type
            ]);

            $userData = $user->toArray();
            if (isset($userData['roles'])) {
                foreach ($userData['roles'] as &$role) {
                    unset($role['pivot']);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'User created successfully',
                'data' => $userData
            ], 201);
        } catch (\Throwable $th) {
            if (isset($imagePath)) {
                $this->deleteFile($imagePath);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create user',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $user = User::with(['level', 'branch', 'zone', 'region', 'province', 'parent', 'children', 'roles'])->find($id);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            Log::info('User viewed', [
                'viewer_id' => Auth::id(),
                'viewed_user_id' => $user->id
            ]);

            $userData = $user->toArray();
            if (isset($userData['roles'])) {
                foreach ($userData['roles'] as &$role) {
                    unset($role['pivot']);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'User retrieved successfully',
                'data' => $userData
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve user',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function update(UpdateUserRequest $request, string $id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            $data = $request->validated();

            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            } else {
                unset($data['password']);
            }

            // Logic to clear hierarchy fields if switching to admin
            if (isset($data['user_type']) && $data['user_type'] === 'admin') {
                $data['level_id'] = null;
                $data['parent_user_id'] = null;
                $data['branch_id'] = null;
                $data['zone_id'] = null;
                $data['region_id'] = null;
                $data['province_id'] = null;
            }

            // Handle Image Upload
            $imagePath = $this->handleFileUpload($request, 'profile_image', $user->profile_image, 'users/profile', $user->email);
            if ($imagePath) {
                $data['profile_image'] = $imagePath;
            }

            $user->update($data);

            if (isset($data['role'])) {
                $user->syncRoles([$data['role']]);
            }

            $user->refresh();
            $user->load([
                'roles' => function ($q) {
                    $q->select('id', 'name');
                }
            ]);

            Log::info('User updated', [
                'admin_id' => Auth::id(),
                'updated_user_id' => $user->id,
                'updated_fields' => array_keys($data)
            ]);

            $userData = $user->toArray();
            if (isset($userData['roles'])) {
                foreach ($userData['roles'] as &$role) {
                    unset($role['pivot']);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'User updated successfully',
                'data' => $userData
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update user',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Check if user is Super Admin
            if (!Auth::user()->hasRole('Super Admin')) {
                Log::warning('Unauthorized user deletion attempt', [
                    'user_id' => Auth::id(),
                    'target_user_id' => $id
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only Super Admin can delete users'
                ], 403);
            }

            // Prevent self-deletion
            if ($user->id === Auth::id()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You cannot delete your own account'
                ], 422);
            }

            $this->deleteFile($user->profile_image);
            $user->delete();

            Log::info('User deleted', [
                'admin_id' => Auth::id(),
                'deleted_user_id' => $id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'User deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete user',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function toggleStatus(string $id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Prevent self-deactivation
            if ($user->id === Auth::id()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You cannot deactivate your own account'
                ], 422);
            }

            $user->is_active = !$user->is_active;
            $user->save();

            Log::info('User status toggled', [
                'admin_id' => Auth::id(),
                'target_user_id' => $user->id,
                'new_status' => $user->is_active
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'User status updated successfully',
                'data' => [
                    'id' => $user->id,
                    'is_active' => $user->is_active
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle user status',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
