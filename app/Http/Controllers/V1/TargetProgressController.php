<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Target;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class TargetProgressController extends Controller
{
    /**
     * Display targets for the logged-in user or their hierarchy.
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::guard('api')->user();
            $query = Target::with(['user', 'assigner']);

            // 1. Identify allowed user IDs for filtering
            $allowedUserIds = [];
            $isAdmin = $user->hasRole('Super Admin');

            if (!$isAdmin) {
                // If not admin, you can only see yourself and your descendants
                $allowedUserIds = array_merge([$user->id], $user->getAllDescendantIds());
                $query->whereIn('user_id', $allowedUserIds);
            }

            // 2. Allow filtering by target user_id within the allowed scope
            if ($request->has('user_id')) {
                $targetUserId = $request->user_id;
                if (!$isAdmin && !in_array($targetUserId, $allowedUserIds)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Unauthorized. You can only view targets within your own hierarchy.'
                    ], 403);
                }
                $query->where('user_id', $targetUserId);
            }

            // 3. Filter by period_type and period_key if provided
            if ($request->has('period_type')) {
                $query->where('period_type', $request->period_type);
            }

            if ($request->has('period_key')) {
                $query->where('period_key', $request->period_key);
            }

            $targets = $query->orderBy('period_key', 'desc')->get();

            // 4. Fetch Commissions for these same users/scope
            $commissionQuery = \App\Models\Commission::with('investment');
            if (!$isAdmin) {
                $commissionQuery->whereIn('user_id', $allowedUserIds);
            }

            if ($request->has('user_id')) {
                $commissionQuery->where('user_id', $request->user_id);
            }
            if ($request->has('period_key')) {
                $commissionQuery->where('period_key', $request->period_key);
            }

            $commissions = $commissionQuery->orderBy('created_at', 'desc')->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Targets and Commissions retrieved successfully',
                'data' => [
                    'targets' => $targets,
                    'commissions' => $commissions,
                    'total_commission' => $commissions->sum('commission_amount')
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve targets',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified target by period_key for the logged-in user.
     */
    public function show(string $period_key, Request $request)
    {
        try {
            $user = Auth::guard('api')->user();
            $targetUserId = $request->get('user_id', $user->id);

            // Access control
            if (!$user->hasRole('Super Admin')) {
                $allowedUserIds = array_merge([$user->id], $user->getAllDescendantIds());
                if (!in_array($targetUserId, $allowedUserIds)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Unauthorized. You can only view progress within your own hierarchy.'
                    ], 403);
                }
            }

            // Resolve 'current' period
            if ($period_key === 'current') {
                $period_key = Carbon::now()->format('Y-m');
            }

            $target = Target::where('user_id', $targetUserId)
                ->where('period_key', $period_key)
                ->with(['user', 'assigner'])
                ->first();

            if (!$target) {
                return response()->json([
                    'status' => 'error',
                    'message' => "No target found for user ID {$targetUserId} in period: {$period_key}"
                ], 404);
            }

            // 3. Fetch Commissions for this user and period
            $commissions = \App\Models\Commission::where('user_id', $targetUserId)
                ->where('period_key', $period_key)
                ->with('investment')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Target progress and commissions retrieved successfully',
                'data' => [
                    'target' => $target,
                    'commissions' => $commissions,
                    'total_commission' => $commissions->sum('commission_amount')
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve target progress',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
