<?php

namespace App\Http\Controllers\External;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Target;
use App\Models\Investment;
use App\Models\Commission;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ExternalApiController extends Controller
{
    /**
     * Get employee metrics summary for a specific period/date range.
     */
    public function employeesSummary(Request $request): JsonResponse
    {
        try {
            $fromDate = $request->get('from_date');
            $toDate = $request->get('to_date');
            $periodKeyInput = $request->get('period_key');
            $perPage = $request->get('per_page', 50);

            // Determine Date Range & Period Key
            if ($fromDate && $toDate) {
                $from = Carbon::parse($fromDate)->startOfDay();
                $to = Carbon::parse($toDate)->endOfDay();
                $periodKey = $from->format('Y-m');
            } elseif ($periodKeyInput) {
                $periodKey = $periodKeyInput;
                $from = Carbon::parse($periodKey . '-01')->startOfMonth();
                $to = Carbon::parse($periodKey . '-01')->endOfMonth();
            } else {
                $periodKey = Carbon::now()->format('Y-m');
                $from = Carbon::now()->startOfMonth();
                $to = Carbon::now()->endOfMonth();
            }

            $query = User::with(['level', 'branch'])
                ->where('user_type', 'hierarchy')
                ->where('is_active', true);

            if ($request->has('search')) {
                $search = $request->get('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('employee_code', 'like', "%{$search}%")
                      ->orWhere('username', 'like', "%{$search}%");
                });
            }

            // Paginate or get all
            if ($perPage == -1) {
                $users = $query->get();
            } else {
                $users = $query->paginate($perPage);
            }

            $transformCallback = function ($user) use ($from, $to, $periodKey) {
                $descendantIds = $user->getAllDescendantIds();
                $allBranchIds = array_merge([$user->id], $descendantIds);

                // Branch approved investments for achievement amount
                $branchInvestments = Investment::whereIn('unit_head_id', $allBranchIds)
                    ->whereBetween('reservation_date', [$from, $to])
                    ->where('status', 'approved')
                    ->get();
                $branchBusinessTotal = (float)$branchInvestments->sum('investment_amount');

                // Target
                $target = Target::where('user_id', $user->id)
                    ->where('period_key', $periodKey)
                    ->first();
                $targetAmount = $target ? (float)$target->target_amount : 0.0;
                
                // Achievement percentage
                $achievementPercentage = $targetAmount > 0 
                    ? ($branchBusinessTotal / $targetAmount) * 100 
                    : ($branchBusinessTotal > 0 ? 100.0 : 0.0);
                $achievementPercentage = (float)min($achievementPercentage, 999.99);

                // Commissions (approved)
                $userCommissions = Commission::where('user_id', $user->id)
                    ->whereHas('investment', function ($q) use ($from, $to) {
                        $q->whereBetween('reservation_date', [$from, $to])
                          ->where('status', 'approved');
                    })
                    ->get();

                $personalUnitHeadCommission = (float)$userCommissions->where('tier', 'unit_head')->sum('commission_amount');
                $personalOverrideCommission = (float)$userCommissions->where('tier', 'parent')->sum('commission_amount');
                $personalCommission = (float)$userCommissions->sum('commission_amount');

                // Recoveries (cancelled)
                $userRecoveries = Commission::where('user_id', $user->id)
                    ->whereHas('investment', function ($q) use ($from, $to) {
                        $q->whereBetween('reservation_date', [$from, $to])
                          ->where('status', 'cancelled');
                    })
                    ->get();
                $personalRecovery = (float)$userRecoveries->sum('recover_amount');

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'employee_code' => $user->employee_code,
                    'level' => $user->level->level_name ?? 'N/A',
                    'branch' => $user->branch->name ?? 'N/A',
                    'metrics' => [
                        'target_amount' => $targetAmount,
                        'achievement_amount' => $branchBusinessTotal,
                        'achievement_percentage' => $achievementPercentage,
                        'commission' => $personalUnitHeadCommission,
                        'override_commission' => $personalOverrideCommission,
                        'total_commission' => $personalCommission,
                        'recover_amount' => $personalRecovery
                    ]
                ];
            };

            if ($perPage == -1) {
                $data = $users->map($transformCallback);
            } else {
                $users->getCollection()->transform($transformCallback);
                $data = $users;
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Employees summary retrieved successfully',
                'data' => $data
            ], 200);

        } catch (\Throwable $th) {
            Log::error('External employees summary failed', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve employees summary',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
