<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Traits\ActivityLogTrait;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ActivityLogController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    /**
     * Get middleware for the controller.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:ActivityLog Index', only: ['index']),
            new Middleware('permission:ActivityLog Show', only: ['show']),
        ];
    }

    /**
     * Display a listing of activity logs.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = ActivityLog::with('user:id,name,username,email');

            // Filters
            if ($request->has('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            if ($request->has('module')) {
                $query->where('module', $request->module);
            }

            if ($request->has('action')) {
                $query->where('action', $request->action);
            }

            // Search by description, IP address, or user agent
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('description', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhere('user_agent', 'like', "%{$search}%");
                });
            }

            $logs = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'message' => 'Activity logs retrieved successfully',
                'data' => $logs
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve activity logs',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified activity log.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $log = ActivityLog::with('user:id,name,username,email')->find($id);

            if (!$log) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Activity log not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Activity log details retrieved successfully',
                'data' => $log
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve activity log details',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
