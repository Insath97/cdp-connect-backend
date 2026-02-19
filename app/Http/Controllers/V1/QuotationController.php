<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateQuotationRequest;
use App\Models\Quotation;
use App\Models\Branch;
use App\Models\InvestmentProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class QuotationController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $user = Auth::guard('api')->user();

            $query = Quotation::with(['customer', 'branch', 'investmentProduct', 'creator']);

            // Hierarchy Visibility Logic
            if (!$user->hasRole('Super Admin') && ($user->user_type !== 'admin')) {
                // Hierarchical users (GM, AGM, etc.) see their own and descendants
                $descendantIds = $user->getAllDescendantIds();
                $accessibleUserIds = array_merge([$user->id], $descendantIds);

                $query->whereIn('created_by', $accessibleUserIds);
            }

            // Branch Filter
            if ($request->has('branch_id')) {
                $query->where('branch_id', $request->branch_id);
            }

            // Search by Quotation Number or Customer Name
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('quotation_number', 'like', "%{$search}%")
                        ->orWhereHas('customer', function ($cq) use ($search) {
                            $cq->where('full_name', 'like', "%{$search}%");
                        });
                });
            }

            // Ordering: GM -> AGM -> Branch (Hierarchical Order)
            // Use leftJoin to avoid filtering out records that might not have a level or branch (e.g., Super Admin entries)
            $quotations = $query->leftJoin('users', 'quotations.created_by', '=', 'users.id')
                ->leftJoin('levels', 'users.level_id', '=', 'levels.id')
                ->leftJoin('branches', 'quotations.branch_id', '=', 'branches.id')
                ->select('quotations.*')
                ->orderByRaw('COALESCE(levels.tire_level, 999) ASC')
                ->orderBy('branches.name', 'asc')
                ->orderBy('quotations.created_at', 'desc')
                ->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'message' => 'Quotations retrieved successfully',
                'data' => $quotations
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve quotations',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function store(CreateQuotationRequest $request)
    {
        try {
            $user = Auth::guard('api')->user();
            $data = $request->validated();

            // 1. 14-Day Restriction
            $lastQuotation = Quotation::where('customer_id', $data['customer_id'])
                ->where('created_at', '>=', Carbon::now()->subDays(14))
                ->first();

            if ($lastQuotation) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'A quotation was already created for this customer within the last 14 days.'
                ], 422);
            }

            // 2. Intelligent Branch Selection
            $branchId = $data['branch_id'] ?? $user->branch_id;

            if (!$branchId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Branch is required. Please select a branch.'
                ], 422);
            }

            $branch = Branch::findOrFail($branchId);

            // 3. Investment Calculation (Monthly Interest)
            $product = InvestmentProduct::findOrFail($data['investment_product_id']);
            $roi = $product->roi_percentage;
            $duration = $product->duration_months;
            $amount = $data['investment_amount'];

            // Monthly Interest = (Amount * (ROI/100)) / 12
            $monthlyInterest = ($amount * ($roi / 100)) / 12;

            // 4. Generate Quotation Number
            $yearStr = date('y');
            $prefix = $branch->code . '-' . $yearStr;
            $lastNum = Quotation::where('quotation_number', 'like', $prefix . '%')
                ->orderBy('quotation_number', 'desc')
                ->first();

            $sequence = $lastNum ? (int) substr($lastNum->quotation_number, -4) + 1 : 1;
            $quotationNumber = $prefix . str_pad($sequence, 4, '0', STR_PAD_LEFT);

            // 5. Prepare Quotation Data
            $quotationData = array_merge($data, [
                'branch_id' => $branchId,
                'quotation_number' => $quotationNumber,
                'created_by' => $user->id,
                'status' => 'draft',
                'month_6_breakdown' => ($duration >= 6) ? $monthlyInterest : 0,
                'year_1_breakdown' => ($duration >= 12) ? $monthlyInterest : 0,
                'year_2_breakdown' => ($duration >= 24) ? $monthlyInterest : 0,
                'year_3_breakdown' => ($duration >= 36) ? $monthlyInterest : 0,
                'year_4_breakdown' => ($duration >= 48) ? $monthlyInterest : 0,
                'year_5_breakdown' => ($duration >= 60) ? $monthlyInterest : 0,
            ]);

            $quotation = Quotation::create($quotationData);

            Log::info('Quotation created', [
                'user_id' => $user->id,
                'quotation_id' => $quotation->id,
                'quotation_number' => $quotation->quotation_number
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Quotation created successfully',
                'data' => $quotation
            ], 201);

        } catch (\Throwable $th) {
            Log::error('Quotation creation failed', [
                'error' => $th->getMessage(),
                'user_id' => Auth::guard('api')->id()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create quotation',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $quotation = Quotation::with(['customer', 'branch', 'investmentProduct', 'creator'])->find($id);

            if (!$quotation) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Quotation not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Quotation details retrieved successfully',
                'data' => $quotation
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve quotation details',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {

    }

    public function destroy($id)
    {

    }

    public function restore($id)
    {

    }

    public function forceDelete($id)
    {

    }

    public function toggleStatus($id)
    {

    }
}
