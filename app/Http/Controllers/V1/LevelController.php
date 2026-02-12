<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateLevelRequest;
use App\Http\Requests\UpdateLevelRequest;
use App\Models\Level;
use Illuminate\Http\Request;

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
            $level = Level::create($data);

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
            $level->update($data);

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

            $level->delete();

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

