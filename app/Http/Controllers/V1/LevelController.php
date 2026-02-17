<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateLevelRequest;
use App\Http\Requests\UpdateLevelRequest;
use App\Models\Level;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class LevelController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = Level::query();

            if ($request->has('search')) {
                $query->search($request->search);
            }

            if ($request->has('is_active')) {
                $request->boolean('is_active') ? $query->active() : $query->where('isActive', false);
            }

            $levels = $query->paginate($perPage);

            Log::info('Levels index accessed', [
                'user_id' => Auth::id(),
                'filters' => $request->only(['search', 'is_active', 'per_page']),
                'count' => $levels->count()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Levels retrieved successfully',
                'data' => $levels
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve levels',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function store(CreateLevelRequest $request)
    {
        try {
            $data = $request->validated();

            if (empty($data['slug'])) {
                $data['slug'] = Str::slug($data['level_name']);
            }

            $level = Level::create($data);

            Log::info('Level created', [
                'user_id' => Auth::id(),
                'level_id' => $level->id,
                'level_name' => $level->level_name
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Level created successfully',
                'data' => $level
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create level',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $level = Level::find($id);

            if (!$level) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Level not found'
                ], 404);
            }

            Log::info('Level viewed', [
                'user_id' => Auth::id(),
                'level_id' => $level->id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Level retrieved successfully',
                'data' => $level
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve level',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function update(UpdateLevelRequest $request, string $id)
    {
        try {
            $level = Level::find($id);

            if (!$level) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Level not found'
                ], 404);
            }

            $data = $request->validated();

            if (isset($data['level_name']) && $data['level_name'] !== $level->level_name) {
                $data['slug'] = Str::slug($data['level_name']);
            }

            $level->update($data);

            Log::info('Level updated', [
                'user_id' => Auth::id(),
                'level_id' => $level->id,
                'updated_fields' => array_keys($data)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Level updated successfully',
                'data' => $level
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update level',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $level = Level::find($id);

            if (!$level) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Level not found'
                ], 404);
            }

            // Check if user is Super Admin
            if (!Auth::user()->hasRole('Super Admin')) {
                Log::warning('Unauthorized level deletion attempt', [
                    'user_id' => Auth::id(),
                    'level_id' => $id
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only Super Admin can delete levels'
                ], 403);
            }

            $level->delete();

            Log::info('Level deleted', [
                'user_id' => Auth::id(),
                'level_id' => $id,
                'level_name' => $level->level_name
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Level deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete level',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
