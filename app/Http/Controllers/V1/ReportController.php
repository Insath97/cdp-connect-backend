<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Target;
use App\Models\Commission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ReportController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Report Index', only: ['index', 'show']),
        ];
    }

    /**
     * Get hierarchy report for all accessible users.
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::guard('api')->user();
            $periodKey = $request->get('period_key', Carbon::now()->format('Y-m'));
            $perPage = $request->get('per_page', 15);

            // 1. Determine accessible user IDs
            $isAdmin = $user->hasRole('Super Admin') || ($user->user_type === 'admin');

            $query = User::with(['level', 'branch'])
                ->select('users.id', 'users.name', 'users.username', 'users.level_id', 'users.branch_id');

            if (!$isAdmin) {
                $descendantIds = $user->getAllDescendantIds();
                $accessibleIds = array_merge([$user->id], $descendantIds);
                $query->whereIn('users.id', $accessibleIds);
            }

            // 2. Search
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('users.name', 'like', "%{$search}%")
                        ->orWhere('users.username', 'like', "%{$search}%");
                });
            }

            // 3. Join Targets and Commissions for the given period
            // Subquery for commission to avoid double counting if multiple targets existed (though unlikely)
            $commissionsSub = Commission::select('user_id', DB::raw('SUM(commission_amount) as total_commission'))
                ->where('period_key', $periodKey)
                ->groupBy('user_id');

            $reports = $query->leftJoinSub(
                Target::where('period_key', $periodKey),
                't',
                'users.id',
                '=',
                't.user_id'
            )
                ->leftJoinSub(
                    $commissionsSub,
                    'c',
                    'users.id',
                    '=',
                    'c.user_id'
                )
                ->addSelect([
                    't.target_amount',
                    't.achieved_amount',
                    't.achievement_percentage',
                    DB::raw('COALESCE(c.total_commission, 0) as total_commission')
                ])
                ->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'message' => 'Hierarchy report retrieved successfully',
                'data' => $reports,
                'meta' => [
                    'period_key' => $periodKey
                ]
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Report generation failed', [
                'error' => $th->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve report',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Get report for a specific user.
     */
    public function show(Request $request, $id)
    {
        try {
            $currentUser = Auth::guard('api')->user();
            $periodKey = $request->get('period_key', Carbon::now()->format('Y-m'));

            // 1. Accessibility Check
            $isAdmin = $currentUser->hasRole('Super Admin') || ($currentUser->user_type === 'admin');
            if (!$isAdmin) {
                $descendantIds = $currentUser->getAllDescendantIds();
                $accessibleIds = array_merge([$currentUser->id], $descendantIds);
                if (!in_array($id, $accessibleIds)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Unauthorized. This user is not in your hierarchy.'
                    ], 403);
                }
            }

            // 2. Fetch specific user data
            $userReport = User::with(['level', 'branch'])
                ->select('users.id', 'users.name', 'users.username', 'users.level_id', 'users.branch_id')
                ->where('users.id', $id)
                ->leftJoinSub(
                    Target::where('period_key', $periodKey),
                    't',
                    'users.id',
                    '=',
                    't.user_id'
                )
                ->leftJoinSub(
                    Commission::select('user_id', DB::raw('SUM(commission_amount) as total_commission'))
                        ->where('period_key', $periodKey)
                        ->groupBy('user_id'),
                    'c',
                    'users.id',
                    '=',
                    'c.user_id'
                )
                ->addSelect([
                    't.target_amount',
                    't.achieved_amount',
                    't.achievement_percentage',
                    DB::raw('COALESCE(c.total_commission, 0) as total_commission')
                ])
                ->first();

            if (!$userReport) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User report not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'User report retrieved successfully',
                'data' => $userReport,
                'meta' => [
                    'period_key' => $periodKey
                ]
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Detailed report failed', [
                'error' => $th->getMessage(),
                'user_id' => Auth::id(),
                'target_user_id' => $id
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve user report',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
