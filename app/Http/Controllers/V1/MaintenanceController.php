<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Target;
use App\Models\Commission;
use App\Models\Investment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class MaintenanceController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Maintenance Access', only: ['recalculateTargets']),
        ];
    }

    /**
     * Recalculate targets and optionally commissions for a given period.
     */
    public function recalculateTargets(Request $request)
    {
        $request->validate([
            'period' => 'nullable|string|regex:/^\d{4}-\d{2}$/',
            'user_id' => 'nullable|exists:users,id',
            'include_commissions' => 'nullable|boolean'
        ]);

        $period = $request->get('period', date('Y-m'));
        $userId = $request->get('user_id');
        $includeCommissions = $request->boolean('include_commissions');

        try {
            DB::beginTransaction();

            $results = [
                'period' => $period,
                'commissions_updated' => 0,
                'targets_updated' => 0,
            ];

            // 1. Regenerate Commissions if requested
            if ($includeCommissions) {
                // Delete existing commissions for the period
                Commission::where('period_key', $period)->delete();

                // Fetch all approved investments for that period
                $investments = Investment::where('target_period_key', $period)
                    ->where('status', 'approved')
                    ->get();

                foreach ($investments as $investment) {
                    Commission::generateForInvestment($investment);
                    $results['commissions_updated']++;
                }
            }

            // 2. Recalculate Targets
            if ($userId) {
                if (Target::recalculateForUser($userId, $period)) {
                    $results['targets_updated'] = 1;
                }
            } else {
                $targets = Target::where('period_key', $period)->get();
                foreach ($targets as $target) {
                    Target::recalculateForUser($target->user_id, $period);
                    $results['targets_updated']++;
                }
            }

            DB::commit();

            Log::info('Manual target recalculation triggered', [
                'admin_id' => Auth::id(),
                'params' => $request->all(),
                'results' => $results
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Recalculation completed successfully.',
                'data' => $results
            ], 200);

        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Maintenance recalculation failed', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to complete recalculation.',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
