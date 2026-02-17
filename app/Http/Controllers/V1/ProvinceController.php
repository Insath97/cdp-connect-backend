<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateProvinceRequest;
use App\Http\Requests\UpdateProvinceRequest;
use App\Models\Province;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class ProvinceController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = Province::with('country:id,name,code');

            if ($request->has('search')) {
                $query->search($request->search);
            }

            if ($request->has('is_active')) {
                $request->boolean('is_active') ? $query->active() : $query->where('is_active', false);
            }

            if ($request->has('country_id')) {
                $query->where('country_id', $request->country_id);
            }

            $provinces = $query->paginate($perPage);

            Log::info('Provinces index accessed', [
                'user_id' => Auth::id(),
                'filters' => $request->only(['search', 'is_active', 'per_page', 'country_id']),
                'count' => $provinces->count()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Provinces retrieved successfully',
                'data' => $provinces
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve provinces',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function store(CreateProvinceRequest $request)
    {
        try {
            $data = $request->validated();
            $province = Province::create($data);

            Log::info('Province created', [
                'user_id' => Auth::id(),
                'province_id' => $province->id,
                'province_name' => $province->name
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Province created successfully',
                'data' => $province
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create province',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $province = Province::with('country')->find($id);

            if (!$province) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Province not found'
                ], 404);
            }

            Log::info('Province viewed', [
                'user_id' => Auth::id(),
                'province_id' => $province->id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Province retrieved successfully',
                'data' => $province
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve province',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function update(UpdateProvinceRequest $request, string $id)
    {
        try {
            $province = Province::find($id);

            if (!$province) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Province not found'
                ], 404);
            }

            $data = $request->validated();
            $province->update($data);

            Log::info('Province updated', [
                'user_id' => Auth::id(),
                'province_id' => $province->id,
                'updated_fields' => array_keys($data)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Province updated successfully',
                'data' => $province
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update province',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $province = Province::find($id);

            if (!$province) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Province not found'
                ], 404);
            }

            // Check if user is Super Admin
            if (!Auth::user()->hasRole('Super Admin')) {
                Log::warning('Unauthorized province deletion attempt', [
                    'user_id' => Auth::id(),
                    'province_id' => $id
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only Super Admin can delete provinces'
                ], 403);
            }

            $province->delete();

            Log::info('Province deleted', [
                'user_id' => Auth::id(),
                'province_id' => $id,
                'province_name' => $province->name
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Province deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete province',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function toggleStatus(string $id)
    {
        try {
            $province = Province::find($id);

            if (!$province) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Province not found'
                ], 404);
            }

            $province->is_active = !$province->is_active;
            $province->save();

            Log::info('Province status toggled', [
                'user_id' => Auth::id(),
                'province_id' => $province->id,
                'new_status' => $province->is_active
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Province status updated successfully',
                'data' => [
                    'id' => $province->id,
                    'is_active' => $province->is_active
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle province status',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
