<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Requests\ChangePasswordRequest;
use App\Traits\FileUploadTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
    use FileUploadTrait;

    /**
     * Get the authenticated user's profile.
     */
    public function show()
    {
        try {
            $user = auth('api')->user();
            $user->load([
                'roles' => function ($query) {
                    $query->select('id', 'name');
                }
            ]);

            if ($user->relationLoaded('roles')) {
                $user->roles->each->makeHidden(['pivot']);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Profile retrieved successfully',
                'data' => [
                    'user' => $user
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve profile',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Update the authenticated user's profile.
     */
    public function update(UpdateProfileRequest $request)
    {
        try {
            $user = auth('api')->user();
            $data = $request->validated();

            // Handle Profile Image
            $imagePath = $this->handleFileUpload($request, 'profile_image', $user->profile_image, 'users/profile', $user->email);
            if ($imagePath) {
                $data['profile_image'] = $imagePath;
            }

            $user->update($data);

            Log::info('Profile updated', [
                'user_id' => $user->id,
                'updated_fields' => array_keys($data)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Profile updated successfully',
                'data' => [
                    'user' => $user->fresh()
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update profile',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Change the authenticated user's password.
     */
    public function changePassword(ChangePasswordRequest $request)
    {
        try {
            $user = auth('api')->user();
            $data = $request->validated();

            // Verify current password
            if (!Hash::check($data['current_password'], $user->password)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The provided current password does not match our records.',
                    'errors' => [
                        [
                            'field' => 'current_password',
                            'messages' => ['The current password is incorrect.']
                        ]
                    ]
                ], 422);
            }

            $user->update([
                'password' => Hash::make($data['new_password'])
            ]);

            Log::info('Password changed', [
                'user_id' => $user->id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Password changed successfully'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to change password',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
