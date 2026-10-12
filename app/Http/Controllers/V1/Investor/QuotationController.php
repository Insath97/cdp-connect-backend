<?php

namespace App\Http\Controllers\V1\Investor;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateInvestorQuotationRequest;
use App\Http\Requests\UpdateInvestorQuotationRequest;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\InvestmentProduct;
use App\Models\Quotation;
use App\Traits\ActivityLogTrait;
use App\Traits\InvestmentCalculationTrait;
use App\Traits\ResolvesInvestorCustomer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class QuotationController extends Controller
{
    use ActivityLogTrait, InvestmentCalculationTrait, ResolvesInvestorCustomer;

    /**
     * List all quotations for the authenticated investor.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $customer = $this->resolveCustomer();

            if (!$customer) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Customer profile not found.',
                ], 404);
            }

            $query = Quotation::where(function ($q) use ($customer) {
                $q->where('customer_id', $customer->id)
                  ->orWhereRaw('LOWER(TRIM(id_number)) = ?', [strtolower($customer->id_number)]);
            })->with([
                'investmentProduct:id,name,code,duration_months,roi_percentage,plan_type',
                'branch:id,name,code',
                'marketingUser:id,name,username,email'
            ]);

            // Filter by status if provided
            if ($request->filled('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            // Filter by creator type if provided (investor vs consultant)
            if ($request->filled('creator_type')) {
                $query->where('creator_type', $request->creator_type);
            }

            // Search by quotation number or plan name
            if ($request->filled('search')) {
                $search = trim((string) $request->search);
                $query->where(function ($q) use ($search) {
                    $q->where('quotation_number', 'like', "%{$search}%")
                      ->orWhereHas('investmentProduct', function ($pq) use ($search) {
                          $pq->where('name', 'like', "%{$search}%");
                      });
                });
            }

            // Date filtering
            if ($request->filled('from_date')) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }
            if ($request->filled('to_date')) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }

            $perPage = (int) $request->get('per_page', 15);
            $quotations = $query->orderBy('created_at', 'desc')->paginate($perPage);

            $this->logActivity('View Quotations', 'Investor Portal', "Quotations listed for customer: {$customer->full_name}", [
                'user_id'     => auth('api')->id(),
                'customer_id' => $customer->id,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Quotations retrieved successfully',
                'data'    => $quotations,
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Investor Quotations List Error: ' . $th->getMessage(), [
                'exception' => $th,
                'user_id'   => auth('api')->id(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to retrieve quotations',
                'error'   => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Show quotation details with yearly breakdown.
     *
     * @param int|string $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        try {
            $customer = $this->resolveCustomer();

            if (!$customer) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Customer profile not found.',
                ], 404);
            }

            $quotation = Quotation::where(function ($q) use ($customer) {
                $q->where('customer_id', $customer->id)
                  ->orWhereRaw('LOWER(TRIM(id_number)) = ?', [strtolower($customer->id_number)]);
            })
            ->with([
                'investmentProduct.annualRates',
                'branch:id,name,code',
                'marketingUser:id,name,username,email'
            ])
            ->find($id);

            if (!$quotation) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Quotation not found.',
                ], 404);
            }

            $yearlyBreakdown = [];
            if ($quotation->investmentProduct) {
                $calculations = $this->calculateInvestmentROI(
                    (float) $quotation->investment_amount,
                    $quotation->investmentProduct
                );
                $yearlyBreakdown = $calculations['yearly_breakdown'] ?? [];
            }

            $data = $quotation->toArray();
            $data['yearly_breakdown'] = $yearlyBreakdown;

            return response()->json([
                'status'  => 'success',
                'message' => 'Quotation details retrieved successfully',
                'data'    => $data,
            ], 200);

        } catch (\Throwable $th) {
            Log::error("Investor Quotation Show Error [ID: {$id}]: " . $th->getMessage(), [
                'exception' => $th,
                'user_id'   => auth('api')->id(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to retrieve quotation details',
                'error'   => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Create a new quotation initiated by the investor (Mobile).
     *
     * @param CreateInvestorQuotationRequest $request
     * @return JsonResponse
     */
    public function store(CreateInvestorQuotationRequest $request): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $customer = $this->resolveCustomer();

            if (!$customer) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Customer profile not found. Please contact support.',
                ], 404);
            }

            if (!$customer->is_active) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Your customer account is currently inactive.',
                ], 403);
            }

            $amount = (float) $request->input('investment_amount');
            $productId = $request->input('investment_product_id');

            // 1. Fetch active and accessible Investment Product
            $product = InvestmentProduct::active()
                ->visibleTo($user)
                ->with('annualRates')
                ->findOrFail($productId);

            // 2. Intelligent Branch Resolution:
            // Priority: Request input -> Customer User branch -> Customer's latest investment branch -> Head Office -> First Active Branch
            $branchId = $request->input('branch_id');

            if (!$branchId && !empty($user->branch_id)) {
                $branchId = $user->branch_id;
            }

            if (!$branchId) {
                $branchId = $customer->investments()->latest('id')->value('branch_id');
            }

            if (!$branchId) {
                $branchId = Branch::where('is_head_office', true)->value('id');
            }

            if (!$branchId) {
                $branchId = Branch::where('is_active', true)->value('id') ?? Branch::value('id');
            }

            if (!$branchId) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Unable to determine assigned branch. Please select a branch.',
                ], 422);
            }

            $branch = Branch::findOrFail($branchId);

            // 3. Investment Calculations
            $calculations = $this->calculateInvestmentROI($amount, $product);

            // 4. Generate Quotation Number: {BRANCH_CODE}-{YY}{SEQUENCE_0001}
            $yearStr = date('y');
            $prefix = $branch->code . '-' . $yearStr;
            $lastNum = Quotation::where('quotation_number', 'like', $prefix . '%')
                ->orderBy('quotation_number', 'desc')
                ->first();

            $sequence = $lastNum ? (int) substr($lastNum->quotation_number, -4) + 1 : 1;
            $quotationNumber = $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);

            // 5. Check if customer is already linked to an existing marketing user
            $lastQuotation = Quotation::where('id_number', $customer->id_number)->latest('id')->first();
            $marketingUserId = $lastQuotation?->marketing_user_id;

            if (!$marketingUserId) {
                $marketingUserId = $customer->investments()->latest('id')->value('created_by');
            }

            // Format address from customer profile
            $addressParts = array_filter([
                $customer->address_line_1,
                $customer->address_line_2,
                $customer->city,
                $customer->state,
            ]);
            $formattedAddress = implode(', ', $addressParts);

            // 6. Persist Quotation with creator_type = 'investor'
            $quotationData = [
                'quotation_number'      => $quotationNumber,
                'customer_id'           => $customer->id,
                'f_name'                => $customer->f_name ?? null,
                'l_name'                => $customer->l_name ?? null,
                'full_name'             => $customer->full_name,
                'name_with_initials'    => $customer->name_with_initials,
                'id_type'               => $customer->id_type ?? 'nic',
                'id_number'             => $customer->id_number,
                'phone_primary'         => $customer->phone_primary,
                'email'                 => $customer->email,
                'address'               => $formattedAddress ?: null,
                'branch_id'             => $branchId,
                'investment_product_id' => $product->id,
                'investment_amount'     => $amount,
                'monthly_return'        => $calculations['monthly_return'],
                'annual_return'         => $calculations['annual_return'],
                'maturity_amount'       => $calculations['maturity_amount'],
                'month_6_breakdown'     => $calculations['month_6_breakdown'],
                'year_1_breakdown'      => $calculations['year_1_breakdown'],
                'year_2_breakdown'      => $calculations['year_2_breakdown'],
                'year_3_breakdown'      => $calculations['year_3_breakdown'],
                'year_4_breakdown'      => $calculations['year_4_breakdown'],
                'year_5_breakdown'      => $calculations['year_5_breakdown'],
                'creator_type'          => 'investor',
                'status'                => 'draft',
                'is_active'             => true,
                'valid_until'           => Carbon::now()->addDays(30),
                'notes'                 => $request->input('notes'),
                'created_by'            => $user->id,
                'marketing_user_id'     => $marketingUserId,
            ];

            $quotation = Quotation::create($quotationData);

            // 7. Activity Logging
            $this->logActivity('Create', 'Investor Portal', 'Quotation created by investor', [
                'user_id'          => $user->id,
                'customer_id'      => $customer->id,
                'quotation_id'     => $quotation->id,
                'quotation_number' => $quotation->quotation_number,
                'amount'           => $amount,
                'product_id'       => $product->id,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Quotation created successfully',
                'data'    => array_merge(
                    $quotation->load([
                        'customer:id,full_name,name_with_initials',
                        'branch:id,name,code',
                        'investmentProduct:id,name,code,duration_months,roi_percentage',
                        'marketingUser:id,name,username,email',
                    ])->toArray(),
                    [
                        'yearly_breakdown' => $calculations['yearly_breakdown'] ?? [],
                    ]
                ),
            ], 201);

        } catch (\Throwable $th) {
            Log::error('Investor Quotation Creation Failed: ' . $th->getMessage(), [
                'exception' => $th,
                'user_id'   => auth('api')->id(),
                'input'     => $request->only(['investment_product_id', 'investment_amount', 'branch_id']),
            ]);

            $this->logActivity('Error', 'Investor Portal', 'Quotation creation failed: ' . $th->getMessage(), [
                'user_id' => auth('api')->id(),
                'error'   => $th->getMessage(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to create quotation',
                'error'   => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Update an existing quotation created by the investor.
     *
     * @param UpdateInvestorQuotationRequest $request
     * @param int|string $id
     * @return JsonResponse
     */
    public function update(UpdateInvestorQuotationRequest $request, $id): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $customer = $this->resolveCustomer();

            if (!$customer) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Customer profile not found.',
                ], 404);
            }

            if (!$customer->is_active) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Your customer account is currently inactive.',
                ], 403);
            }

            // 1. Locate Quotation belonging to this customer
            $quotation = Quotation::where(function ($q) use ($customer) {
                $q->where('customer_id', $customer->id)
                  ->orWhereRaw('LOWER(TRIM(id_number)) = ?', [strtolower($customer->id_number)]);
            })->find($id);

            if (!$quotation) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Quotation not found.',
                ], 404);
            }

            // 2. Security Check: Prevent customer from modifying consultant-created quotations
            if ($quotation->creator_type === 'consultant') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Quotations prepared by consultants cannot be modified. You can create a new quotation instead.',
                ], 403);
            }

            // 3. Status Check: Only draft quotations can be modified
            if ($quotation->status !== 'draft') {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Only draft quotations can be modified. Current status is '{$quotation->status}'.",
                ], 422);
            }

            // 4. Update and Recalculate If Amount or Product Changed
            $recalculated = false;
            $yearlyBreakdown = [];

            if ($request->has('investment_amount') || $request->has('investment_product_id')) {
                $productId = $request->input('investment_product_id', $quotation->investment_product_id);
                $amount = (float) $request->input('investment_amount', $quotation->investment_amount);

                $product = InvestmentProduct::active()
                    ->visibleTo($user)
                    ->with('annualRates')
                    ->findOrFail($productId);

                $calculations = $this->calculateInvestmentROI($amount, $product);
                $yearlyBreakdown = $calculations['yearly_breakdown'] ?? [];

                $quotation->investment_product_id = $product->id;
                $quotation->investment_amount     = $amount;
                $quotation->monthly_return        = $calculations['monthly_return'];
                $quotation->annual_return         = $calculations['annual_return'];
                $quotation->maturity_amount       = $calculations['maturity_amount'];
                $quotation->month_6_breakdown     = $calculations['month_6_breakdown'];
                $quotation->year_1_breakdown      = $calculations['year_1_breakdown'];
                $quotation->year_2_breakdown      = $calculations['year_2_breakdown'];
                $quotation->year_3_breakdown      = $calculations['year_3_breakdown'];
                $quotation->year_4_breakdown      = $calculations['year_4_breakdown'];
                $quotation->year_5_breakdown      = $calculations['year_5_breakdown'];
                $recalculated = true;
            }

            // 5. Update branch if provided
            if ($request->filled('branch_id')) {
                $branch = Branch::findOrFail($request->input('branch_id'));
                $quotation->branch_id = $branch->id;
            }

            // 6. Update notes if provided
            if ($request->has('notes')) {
                $quotation->notes = $request->input('notes');
            }

            $quotation->save();

            // If not recalculated, load current product calculations for complete breakdown response
            if (!$recalculated && $quotation->investmentProduct) {
                $product = $quotation->investmentProduct->load('annualRates');
                $calculations = $this->calculateInvestmentROI((float) $quotation->investment_amount, $product);
                $yearlyBreakdown = $calculations['yearly_breakdown'] ?? [];
            }

            // 7. Activity Log
            $this->logActivity('Update', 'Investor Portal', 'Investor quotation updated', [
                'user_id'          => $user->id,
                'customer_id'      => $customer->id,
                'quotation_id'     => $quotation->id,
                'quotation_number' => $quotation->quotation_number,
                'amount'           => $quotation->investment_amount,
                'product_id'       => $quotation->investment_product_id,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Quotation updated successfully',
                'data'    => array_merge(
                    $quotation->load([
                        'customer:id,full_name,name_with_initials',
                        'branch:id,name,code',
                        'investmentProduct:id,name,code,duration_months,roi_percentage',
                        'marketingUser:id,name,username,email',
                    ])->toArray(),
                    [
                        'yearly_breakdown' => $yearlyBreakdown,
                    ]
                ),
            ], 200);

        } catch (\Throwable $th) {
            Log::error("Investor Quotation Update Error [ID: {$id}]: " . $th->getMessage(), [
                'exception' => $th,
                'user_id'   => auth('api')->id(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to update quotation',
                'error'   => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Preview and simulate ROI calculations without saving to database.
     * Useful for live investment calculator in the mobile app.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function calculate(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'investment_product_id' => 'required|exists:investment_products,id',
                'investment_amount'     => 'required|numeric|min:1',
            ]);

            $user = auth('api')->user();
            $amount = (float) $request->input('investment_amount');
            $productId = $request->input('investment_product_id');

            $product = InvestmentProduct::active()
                ->visibleTo($user)
                ->with('annualRates')
                ->findOrFail($productId);

            $calculations = $this->calculateInvestmentROI($amount, $product);

            return response()->json([
                'status'  => 'success',
                'message' => 'Investment calculations completed',
                'data'    => [
                    'product' => [
                        'id'              => $product->id,
                        'name'            => $product->name,
                        'code'            => $product->code,
                        'duration_months' => $product->duration_months,
                        'roi_percentage'  => $product->roi_percentage,
                        'is_variable_roi' => $product->is_variable_roi,
                    ],
                    'investment_amount' => $amount,
                    'monthly_return'    => $calculations['monthly_return'],
                    'annual_return'     => $calculations['annual_return'],
                    'maturity_amount'   => $calculations['maturity_amount'],
                    'breakdowns'        => [
                        'month_6_breakdown' => $calculations['month_6_breakdown'],
                        'year_1_breakdown'  => $calculations['year_1_breakdown'],
                        'year_2_breakdown'  => $calculations['year_2_breakdown'],
                        'year_3_breakdown'  => $calculations['year_3_breakdown'],
                        'year_4_breakdown'  => $calculations['year_4_breakdown'],
                        'year_5_breakdown'  => $calculations['year_5_breakdown'],
                    ],
                    'yearly_breakdown'  => $calculations['yearly_breakdown'] ?? [],
                ],
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to calculate investment return',
                'error'   => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }
}
