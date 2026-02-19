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
     * Display all targets for the logged-in user.
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::guard('api')->user();
            $targets = Target::where('user_id', $user->id)
                ->orderBy('period_key', 'desc')
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Your targets retrieved successfully',
                'data' => $targets
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
    public function show(string $period_key)
    {
        try {
            $user = Auth::guard('api')->user();

            // If the key is 'current', resolve it to the current month key
            if ($period_key === 'current') {
                $period_key = Carbon::now()->format('Y-m');
            }

            $target = Target::where('user_id', $user->id)
                ->where('period_key', $period_key)
                ->first();

            if (!$target) {
                return response()->json([
                    'status' => 'error',
                    'message' => "No target found for period: {$period_key}"
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Target progress retrieved successfully',
                'data' => $target
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
