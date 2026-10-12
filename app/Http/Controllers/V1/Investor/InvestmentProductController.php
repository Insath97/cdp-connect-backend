<?php

namespace App\Http\Controllers\V1\Investor;

use App\Http\Controllers\Controller;
use App\Models\InvestmentProduct;

class InvestmentProductController extends Controller
{
    public function getAvailableInvestmentProducts()
    {
        try {
            $products = InvestmentProduct::active()
                ->visibleTo()
                ->select('id', 'name', 'code', 'duration_months')
                ->orderBy('id', 'asc')
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Investment products retrieved successfully',
                'data' => $products,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve investment products',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}
