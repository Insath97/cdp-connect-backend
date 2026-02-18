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
