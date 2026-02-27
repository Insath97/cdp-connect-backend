<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateInvestmentProductRequest;
use App\Http\Requests\UpdateInvestmentProductRequest;
use App\Models\InvestmentProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class InvestmentProductController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Investment Product Index', only: ['index', 'show']),
            new Middleware('permission:Investment Product Create', only: ['store']),
            new Middleware('permission:Investment Product Update', only: ['update']),
            new Middleware('permission:Investment Product Delete', only: ['destroy']),
            new Middleware('permission:Investment Product Toggle Status', only: ['toggleStatus']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = InvestmentProduct::query();

            if ($request->has('search')) {
                $query->search($request->search);
            }

            if ($request->has('is_active')) {
                $request->boolean('is_active') ? $query->active() : $query->where('is_active', false);
            }

            if ($request->has('duration_months')) {
                $query->where('duration_months', $request->duration_months);
            }

            $investmentProducts = $query->paginate($perPage);

            Log::info('Investment products index accessed', [
                'user_id' => Auth::id(),
                'filters' => $request->only(['search', 'is_active', 'per_page', 'duration_months']),
                'count' => $investmentProducts->count()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Investment products retrieved successfully',
                'data' => $investmentProducts
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve investment products',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function store(CreateInvestmentProductRequest $request)
    {
        try {
            $data = $request->validated();
            $investmentProduct = InvestmentProduct::create($data);

            Log::info('Investment product created', [
                'user_id' => Auth::id(),
                'product_id' => $investmentProduct->id,
                'product_name' => $investmentProduct->name
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Investment product created successfully',
                'data' => $investmentProduct
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create investment product',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $investmentProduct = InvestmentProduct::find($id);

            if (!$investmentProduct) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Investment product not found'
                ], 404);
            }

            Log::info('Investment product viewed', [
                'user_id' => Auth::id(),
                'product_id' => $investmentProduct->id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Investment product retrieved successfully',
                'data' => $investmentProduct
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve investment product',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function update(UpdateInvestmentProductRequest $request, string $id)
    {
        try {
            $investmentProduct = InvestmentProduct::find($id);

            if (!$investmentProduct) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Investment product not found'
                ], 404);
            }

            $data = $request->validated();
            $investmentProduct->update($data);

            Log::info('Investment product updated', [
                'user_id' => Auth::id(),
                'product_id' => $investmentProduct->id,
                'updated_fields' => array_keys($data)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Investment product updated successfully',
                'data' => $investmentProduct
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update investment product',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $investmentProduct = InvestmentProduct::find($id);

            if (!$investmentProduct) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Investment product not found'
                ], 404);
            }

            // Check if user is Super Admin
            if (!Auth::user()->hasRole('Super Admin')) {
                Log::warning('Unauthorized investment product deletion attempt', [
                    'user_id' => Auth::id(),
                    'product_id' => $id
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only Super Admin can delete investment products'
                ], 403);
            }

            $investmentProduct->delete();

            Log::info('Investment product deleted', [
                'user_id' => Auth::id(),
                'product_id' => $id,
                'product_name' => $investmentProduct->name
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Investment product deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete investment product',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function toggleStatus(string $id)
    {
        try {
            $investmentProduct = InvestmentProduct::find($id);

            if (!$investmentProduct) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Investment product not found'
                ], 404);
            }

            $investmentProduct->is_active = !$investmentProduct->is_active;
            $investmentProduct->save();

            Log::info('Investment product status toggled', [
                'user_id' => Auth::id(),
                'product_id' => $investmentProduct->id,
                'new_status' => $investmentProduct->is_active
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Investment product status updated successfully',
                'data' => [
                    'id' => $investmentProduct->id,
                    'is_active' => $investmentProduct->is_active
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle investment product status',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
