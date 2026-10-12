<?php

namespace App\Http\Controllers\V1\Investor;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    /**
     * Get active branches for mobile dropdown / select box.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getAvailableBranches(Request $request): JsonResponse
    {
        try {
            $query = Branch::active()
                ->select('id', 'name', 'code', 'city', 'is_head_office');

            if ($request->filled('search')) {
                $search = trim((string) $request->search);
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%")
                      ->orWhere('city', 'like', "%{$search}%");
                });
            }

            // Head Office first, then alphabetical by name
            $branches = $query->orderBy('is_head_office', 'desc')
                ->orderBy('name', 'asc')
                ->get();

            return response()->json([
                'status'  => 'success',
                'message' => 'Branches retrieved successfully',
                'data'    => $branches,
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to retrieve branches',
                'error'   => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }
}
