<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateBranchRequest;
use App\Http\Requests\UpdateBranchRequest;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class BranchController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = Branch::with(['zone', 'region', 'province']);

            if ($request->has('search')) {
                $query->search($request->search);
            }

            if ($request->has('is_active')) {
                $request->boolean('is_active') ? $query->active() : $query->where('is_active', false);
            }

            if ($request->has('zone_id')) {
                $query->where('zone_id', $request->zone_id);
            }

            if ($request->has('city')) {
                $query->where('city', 'like', "%{$request->city}%");
            }

            $branches = $query->paginate($perPage);

            Log::info('Branches index accessed', [
                'user_id' => Auth::id(),
                'filters' => $request->only(['search', 'is_active', 'per_page', 'zone_id', 'city']),
                'count' => $branches->count()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Branches retrieved successfully',
                'data' => $branches
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve branches',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function store(CreateBranchRequest $request)
    {
        try {
            $data = $request->validated();
            $branch = Branch::create($data);

            Log::info('Branch created', [
                'user_id' => Auth::id(),
                'branch_id' => $branch->id,
                'branch_name' => $branch->name
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Branch created successfully',
                'data' => $branch
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create branch',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $branch = Branch::with(['zone.region.province.country'])->find($id);

            if (!$branch) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Branch not found'
                ], 404);
            }

            Log::info('Branch viewed', [
                'user_id' => Auth::id(),
                'branch_id' => $branch->id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Branch retrieved successfully',
                'data' => $branch
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve branch',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function update(UpdateBranchRequest $request, string $id)
    {
        try {
            $branch = Branch::find($id);

            if (!$branch) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Branch not found'
                ], 404);
            }

            $data = $request->validated();
            $branch->update($data);

            Log::info('Branch updated', [
                'user_id' => Auth::id(),
                'branch_id' => $branch->id,
                'updated_fields' => array_keys($data)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Branch updated successfully',
                'data' => $branch
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update branch',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $branch = Branch::find($id);

            if (!$branch) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Branch not found'
                ], 404);
            }

            // Check if user is Super Admin
            if (!Auth::user()->hasRole('Super Admin')) {
                Log::warning('Unauthorized branch deletion attempt', [
                    'user_id' => Auth::id(),
                    'branch_id' => $id
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only Super Admin can delete branches'
                ], 403);
            }

            $branch->delete();

            Log::info('Branch deleted', [
                'user_id' => Auth::id(),
                'branch_id' => $id,
                'branch_name' => $branch->name
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Branch deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete branch',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function toggleStatus(string $id)
    {
        try {
            $branch = Branch::find($id);

            if (!$branch) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Branch not found'
                ], 404);
            }

            $branch->is_active = !$branch->is_active;
            $branch->save();

            Log::info('Branch status toggled', [
                'user_id' => Auth::id(),
                'branch_id' => $branch->id,
                'new_status' => $branch->is_active
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Branch status updated successfully',
                'data' => [
                    'id' => $branch->id,
                    'is_active' => $branch->is_active
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle branch status',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
