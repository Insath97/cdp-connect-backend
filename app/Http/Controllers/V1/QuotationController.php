<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateQuotationRequest;
use App\Models\Quotation;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\InvestmentProduct;
use App\Http\Requests\UpdateQuotationRequest;
use App\Traits\InvestmentCalculationTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class QuotationController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Quotation Index', only: ['index', 'show']),
            new Middleware('permission:Quotation Create', only: ['store']),
            new Middleware('permission:Quotation Update', only: ['update']),
            new Middleware('permission:Quotation Delete', only: ['destroy']),
            new Middleware('permission:Quotation Restore', only: ['restore']),
            new Middleware('permission:Quotation Force Delete', only: ['forceDelete']),
            new Middleware('permission:Quotation Toggle Status', only: ['toggleStatus']),
        ];
    }

    use InvestmentCalculationTrait;

    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $user = Auth::guard('api')->user();

            $query = Quotation::with(['customer', 'branch', 'investmentProduct', 'creator']);

            // Hierarchy Visibility Logic
            if ($user->hasRole('Super Admin') && ($user->user_type !== 'admin')) {
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
                'data' => $quotations->load(['customer:id,full_name,name_with_initials,id_type,id_number', 'branch:id,name,code', 'investmentProduct:id,name,code,duration_months,roi_percentage', 'creator:id,name,username,email'])
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

            // 1. 14-Day Restriction (Based on id_number/NIC)
            $lastQuotation = Quotation::where('id_number', $data['id_number'])
                ->where('created_at', '>=', Carbon::now()->subDays(14))
                ->first();

            if ($lastQuotation) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'A quotation was already created for this ID number within the last 14 days.'
                ], 422);
            }

            // 2. Intelligent Customer Selection/Lookup
            $customer = null;
            if (!empty($data['customer_id'])) {
                $customer = Customer::find($data['customer_id']);
            } else {
                // Try to find customer by id_number if customer_id not provided
                $customer = Customer::where('id_number', $data['id_number'])->first();
                if ($customer) {
                    $data['customer_id'] = $customer->id;
                }
            }

            // 3. Snapshotting Customer Info
            if ($customer) {
                $data['full_name'] = $customer->full_name;
                $data['name_with_initials'] = $customer->name_with_initials;
                $data['id_type'] = $customer->id_type;
                $data['id_number'] = $customer->id_number;
                $data['phone_primary'] = $customer->phone_primary;
                $data['email'] = $customer->email;
                $data['address'] = $customer->address;
            }

            // 4. Intelligent Branch Selection
            $branchId = $data['branch_id'] ?? $user->branch_id;

            if (!$branchId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Branch is required. Please select a branch.'
                ], 422);
            }

            $branch = Branch::findOrFail($branchId);

            // 5. Investment Calculation
            $product = InvestmentProduct::with('annualRates')->findOrFail($data['investment_product_id']);
            $calculations = $this->calculateInvestmentROI((float) $data['investment_amount'], $product);

            $monthlyReturn = $calculations['monthly_return'];
            $annualReturn = $calculations['annual_return'];
            $maturityAmount = $calculations['maturity_amount'];

            $breakdowns = [
                'month_6_breakdown' => $calculations['month_6_breakdown'],
                'year_1_breakdown' => $calculations['year_1_breakdown'],
                'year_2_breakdown' => $calculations['year_2_breakdown'],
                'year_3_breakdown' => $calculations['year_3_breakdown'],
                'year_4_breakdown' => $calculations['year_4_breakdown'],
                'year_5_breakdown' => $calculations['year_5_breakdown'],
            ];

            // 6. Generate Quotation Number
            $yearStr = date('y');
            $prefix = $branch->code . '-' . $yearStr;
            $lastNum = Quotation::where('quotation_number', 'like', $prefix . '%')
                ->orderBy('quotation_number', 'desc')
                ->first();

            $sequence = $lastNum ? (int) substr($lastNum->quotation_number, -4) + 1 : 1;
            $quotationNumber = $prefix . str_pad($sequence, 4, '0', STR_PAD_LEFT);

            // 7. Prepare and Create Quotation
            $quotationData = array_merge($data, [
                'branch_id' => $branchId,
                'quotation_number' => $quotationNumber,
                'created_by' => $user->id,
                'status' => 'draft',
                'monthly_return' => $monthlyReturn,
                'annual_return' => $annualReturn,
                'maturity_amount' => $maturityAmount,
                'month_6_breakdown' => $breakdowns['month_6_breakdown'],
                'year_1_breakdown' => $breakdowns['year_1_breakdown'],
                'year_2_breakdown' => $breakdowns['year_2_breakdown'],
                'year_3_breakdown' => $breakdowns['year_3_breakdown'],
                'year_4_breakdown' => $breakdowns['year_4_breakdown'],
                'year_5_breakdown' => $breakdowns['year_5_breakdown'],
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
                'data' => array_merge($quotation->load(['customer:id,full_name,name_with_initials', 'branch:id,name,code', 'investmentProduct:id,name,code,duration_months', 'creator'])->toArray(), [
                    'monthly_breakdown' => $calculations['monthly_breakdown']
                ])
            ], 201);

        } catch (\Throwable $th) {
            Log::error('Quotation creation failed', [
                'error' => $th->getMessage(),
                'line' => $th->getLine(),
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

            if ($quotation->investmentProduct) {
                $product = $quotation->investmentProduct;
                $product->load('annualRates');
                $calculations = $this->calculateInvestmentROI((float) $quotation->investment_amount, $product);
                
                $quotation->monthly_breakdown = $calculations['monthly_breakdown'];
                $quotation->total_interest = $calculations['total_interest'];
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

    public function update(UpdateQuotationRequest $request, $id)
    {
        try {
            $user = Auth::guard('api')->user();
            $data = $request->validated();
            $quotation = Quotation::findOrFail($id);

            // 1. Recalculate If Amount or Product Changed
            if ($request->has('investment_amount') || $request->has('investment_product_id')) {
                $productId = $data['investment_product_id'] ?? $quotation->investment_product_id;
                $amount = $data['investment_amount'] ?? $quotation->investment_amount;

                $product = InvestmentProduct::with('annualRates')->findOrFail($productId);
                $calculations = $this->calculateInvestmentROI((float) $amount, $product);

                $data = array_merge($data, [
                    'monthly_return' => $calculations['monthly_return'],
                    'annual_return' => $calculations['annual_return'],
                    'maturity_amount' => $calculations['maturity_amount'],
                    'month_6_breakdown' => $calculations['month_6_breakdown'],
                    'year_1_breakdown' => $calculations['year_1_breakdown'],
                    'year_2_breakdown' => $calculations['year_2_breakdown'],
                    'year_3_breakdown' => $calculations['year_3_breakdown'],
                    'year_4_breakdown' => $calculations['year_4_breakdown'],
                    'year_5_breakdown' => $calculations['year_5_breakdown'],
                ]);
            }

            $quotation->update($data);

            return response()->json([
                'status' => 'success',
                'message' => 'Quotation updated successfully',
                'data' => $quotation->load(['customer', 'branch', 'investmentProduct', 'creator'])
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Quotation update failed', [
                'error' => $th->getMessage(),
                'line' => $th->getLine(),
                'user_id' => Auth::guard('api')->id(),
                'quotation_id' => $id
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update quotation',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $quotation = Quotation::findOrFail($id);
            $quotation->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Quotation soft deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete quotation',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function restore($id)
    {
        try {
            $quotation = Quotation::withTrashed()->findOrFail($id);
            $quotation->restore();

            return response()->json([
                'status' => 'success',
                'message' => 'Quotation restored successfully',
                'data' => $quotation
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to restore quotation',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function forceDelete($id)
    {
        try {
            $quotation = Quotation::withTrashed()->findOrFail($id);
            $quotation->forceDelete();

            return response()->json([
                'status' => 'success',
                'message' => 'Quotation permanently deleted'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to permanently delete quotation',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function toggleStatus($id)
    {
        try {
            $quotation = Quotation::findOrFail($id);
            $quotation->is_active = !$quotation->is_active;
            $quotation->save();

            $status = $quotation->is_active ? 'activated' : 'deactivated';

            return response()->json([
                'status' => 'success',
                'message' => "Quotation {$status} successfully",
                'data' => ['is_active' => $quotation->is_active]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle quotation status',
                'error' => $th->getMessage()
            ], 500);
        }
    }

}
