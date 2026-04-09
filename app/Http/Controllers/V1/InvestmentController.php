<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateInvestmentRequest;
use App\Mail\InvestmentSentMail;
use App\Models\Investment;
use App\Models\Branch;
use App\Models\Target;
use App\Models\Beneficiary;
use App\Models\CustomerBankDetail;
use App\Models\Commission;
use App\Mail\InvestmentApprovedMail;
use App\Services\SmsService;
use App\Models\CommissionSetting;
use App\Models\SystemSetting;
use App\Traits\FileUploadTrait;
use App\Utilities\NumberToWords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;

use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use App\Traits\InvestmentCalculationTrait;

class InvestmentController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Investment Index', only: ['index', 'show']),
            new Middleware('permission:Investment Create', only: ['store']),
            new Middleware('permission:Investment Update', only: ['update']),
            new Middleware('permission:Investment Delete', only: ['destroy']),
            new Middleware('permission:Investment Approve', only: ['approve']),
            new Middleware('permission:Investment Certificate', only: ['printCertificate']),
            new Middleware('permission:Investment Maturity', only: ['investorMaturity']),
        ];
    }

    use FileUploadTrait, InvestmentCalculationTrait;
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $user = Auth::guard('api')->user();

            $query = Investment::with(['customer', 'branch', 'investmentProduct', 'creator', 'unitHead', 'checker', 'approver']);

            // Hierarchy Visibility Logic
            if (!$user->hasRole('Super Admin') && ($user->user_type !== 'admin')) {
                // Hierarchical users (GM, AGM, etc.) see their own and descendants
                $descendantIds = $user->getAllDescendantIds();
                $accessibleUserIds = array_merge([$user->id], $descendantIds);

                $query->whereIn('created_by', $accessibleUserIds);
            }

            // Branch Filter (Admins can filter by branch)
            if ($request->has('branch_id')) {
                $query->where('branch_id', $request->branch_id);
            }

            // Search by Policy, Application, Sales Code, or Customer Name
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('policy_number', 'like', "%{$search}%")
                        ->orWhere('application_number', 'like', "%{$search}%")
                        ->orWhere('sales_code', 'like', "%{$search}%")
                        ->orWhereHas('customer', function ($cq) use ($search) {
                            $cq->where('full_name', 'like', "%{$search}%");
                        });
                });
            }

            // Status Filter
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Ordering: Newest first, then GM -> AGM -> Branch
            // Use leftJoin to avoid filtering out records that might not have a level or branch (e.g., Super Admin entries)
            $investments = $query->leftJoin('users', 'investments.created_by', '=', 'users.id')
                ->leftJoin('levels', 'users.level_id', '=', 'levels.id')
                ->leftJoin('branches', 'investments.branch_id', '=', 'branches.id')
                ->select('investments.*')
                ->orderBy('investments.created_at', 'desc')
                ->orderByRaw('COALESCE(levels.tire_level, 999) ASC')
                ->orderBy('branches.name', 'asc')
                ->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'message' => 'Investments retrieved successfully',
                'data' => $investments
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve investments',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateInvestmentRequest $request)
    {
        DB::beginTransaction();
        try {
            $currentUser = Auth::guard('api')->user();
            $data = $request->validated();

            // 1. Intelligent Branch Selection
            $branchId = $data['branch_id'] ?? $currentUser->branch_id;

            if (!$branchId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Branch is required. Please select a branch or update your profile.'
                ], 422);
            }

            $data['branch_id'] = $branchId;
            $branch = Branch::findOrFail($branchId);

            // 2. Resolve Target Period Key from Reservation Date
            $reservationDate = Carbon::parse($data['reservation_date']);
            $targetPeriodKey = $reservationDate->format('Y-m');
            $data['target_period_key'] = $targetPeriodKey;

            // 2.1 Validate Target existence for the selected Unit Head
            $unitHeadId = $data['unit_head_id'];
            $targetExists = Target::where('user_id', $unitHeadId)
                ->where('period_key', $targetPeriodKey)
                ->exists();

            if (!$targetExists) {
                return response()->json([
                    'status' => 'error',
                    'message' => "The selected Unit Head does not have a target assigned for the period {$targetPeriodKey}. Please assign a target first."
                ], 422);
            }

            // 3. Auto-generate Application Number: APP-{BranchCode}-{YYMM}{Sequence}
            $yymm = $reservationDate->format('ym');
            $appPrefix = 'APP-' . $branch->code . '-' . $yymm;

            $lastApp = Investment::where('application_number', 'like', $appPrefix . '%')
                ->orderBy('application_number', 'desc')
                ->first();

            $appSequence = $lastApp ? (int) substr($lastApp->application_number, -4) + 1 : 1;
            $data['application_number'] = $appPrefix . str_pad($appSequence, 4, '0', STR_PAD_LEFT);

            // 4. Auto-generate Sales Code: {BranchCode}-{Sequence}
            $salesPrefix = $branch->code . '-';
            $lastSales = Investment::where('sales_code', 'like', $salesPrefix . '%')
                ->orderBy('sales_code', 'desc')
                ->first();

            $salesSequence = $lastSales ? (int) substr($lastSales->sales_code, -4) + 1 : 1;
            $data['sales_code'] = $salesPrefix . str_pad($salesSequence, 4, '0', STR_PAD_LEFT);

            // 5. Handle Nested Beneficiary Creation
            if ($request->has('beneficiary')) {
                $beneficiary = Beneficiary::create(array_merge($request->beneficiary, [
                    'customer_id' => $data['customer_id']
                ]));
                $data['beneficiary_id'] = $beneficiary->id;
            }

            // 4. Handle Nested Bank Detail Creation
            if ($request->has('bank_detail')) {
                $bankDetail = CustomerBankDetail::create(array_merge($request->bank_detail, [
                    'customer_id' => $data['customer_id']
                ]));
                $data['customer_bank_detail_id'] = $bankDetail->id;
            }

            // 5. Set Defaults
            $data['created_by'] = $currentUser->id;

            $imagePath = $this->handleFileUpload($request, 'payment_proof', null, 'investments/payment', $data['application_number'] ?? '');
            if ($imagePath) {
                $data['payment_proof'] = $imagePath ?? null;
            }

            $data['status'] = 'pending';

            $investment = Investment::create($data);

            try {
                $recipientEmail = SystemSetting::getSetting('investment_admin_notification_email', 'admin@cdpconnect.com');

                $emailData = [
                    'investment' => $investment->load(['customer', 'branch', 'investmentProduct']),
                    'application_number' => $investment->application_number,
                    'customer_name' => $investment->customer->full_name,
                    'branch_name' => $investment->branch->name,
                    'investment_amount' => $investment->investment_amount,
                    'payment_proof' => $investment->payment_proof,
                ];

                Mail::to($recipientEmail)->send(new InvestmentSentMail($emailData));
            } catch (\Throwable $th) {
                Log::error('Failed to send investment creation email: ' . $th->getMessage());
            }

            DB::commit();

            Log::info('Investment created', [
                'user_id' => $currentUser->id,
                'investment_id' => $investment->id,
                'application_number' => $investment->application_number
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Investment created successfully',
                'data' => $investment->load(['customer', 'branch', 'investmentProduct', 'beneficiary', 'bankDetail'])
            ], 201);
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Investment creation failed', [
                'error' => $th->getMessage(),
                'user_id' => Auth::guard('api')->id()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create investment',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $investment = Investment::with([
                'customer',
                'branch',
                'investmentProduct',
                'beneficiary',
                'bankDetail',
                'creator',
                'unitHead',
                'checker',
                'approver'
            ])->find($id);

            if (!$investment) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Investment not found'
                ], 404);
            }

            $investment->amount_in_words = NumberToWords::convert($investment->investment_amount);

            if ($investment->investmentProduct) {
                $product = $investment->investmentProduct;
                $product->load('annualRates');
                $calculations = $this->calculateInvestmentROI((float)$investment->investment_amount, $product);
                $investment->yearly_breakdown = $calculations['yearly_breakdown'];
            }

            $investment->makeHidden(['created_at', 'updated_at', 'deleted_at', 'created_by', 'unit_head_id', 'checked_by', 'checked_at']);
            if ($investment->customer)
                $investment->customer->makeHidden(['created_at', 'updated_at', 'deleted_at']);
            if ($investment->approver)
                $investment->approver->makeHidden(['created_at', 'updated_at', 'email_verified_at']);

            return response()->json([
                'status' => 'success',
                'message' => 'Investment details retrieved successfully',
                'data' => $investment
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve investment details',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Get data for investment certificate.
     */
    public function printCertificate(string $id)
    {
        try {
            $investment = Investment::with([
                'customer:id,full_name,name_with_initials,customer_code,id_number,address_line_1,city',
                'branch:id,name,code',
                'investmentProduct' => function ($query) {
                    $query->select('id', 'name', 'code', 'duration_months', 'roi_percentage', 'is_variable_roi')
                        ->with('annualRates');
                },
                'unitHead',
                'approver:id,name'
            ])->findOrFail($id);

            if ($investment->status !== 'approved') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Certificates can only be generated for approved investments.'
                ], 422);
            }

            // Calculate ROI and Breakdowns
            if ($investment->investmentProduct) {
                $calculations = $this->calculateInvestmentROI(
                    (float) $investment->investment_amount,
                    $investment->investmentProduct
                );

                // Append calculations to the investment object
                foreach ($calculations as $key => $value) {
                    $investment->{$key} = $value;
                }
            }

            $investment->amount_in_words = NumberToWords::convert($investment->investment_amount);

            $investment->makeHidden(['created_at', 'updated_at', 'deleted_at', 'created_by']);
            if ($investment->customer)
                $investment->customer->makeHidden(['created_at', 'updated_at', 'deleted_at']);
            if ($investment->approver)
                $investment->approver->makeHidden(['created_at', 'updated_at', 'email_verified_at']);

            return response()->json([
                'status' => 'success',
                'message' => 'Certificate data retrieved successfully',
                'data' => $investment
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve certificate data',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    public function update(Request $request, string $id) {}

    /**
     * Approve the specified investment.
     */
    public function approve(Request $request, SmsService $smsService, string $id)
    {
        DB::beginTransaction();
        try {
            $user = Auth::guard('api')->user();
            $investment = Investment::with(['branch', 'unitHead'])->findOrFail($id);

            if ($investment->status !== 'pending') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only pending investments can be approved.'
                ], 422);
            }

            // 1. Generate Policy Number: {BranchCode}-{YYMM}{Sequence}
            $branch = $investment->branch;
            $yymm = date('ym');
            $prefix = 'CDP-' . $branch->code . '-';

            $lastPolicy = Investment::where('policy_number', 'like', $prefix . '%')
                ->orderBy('policy_number', 'desc')
                ->first();

            $sequence = $lastPolicy ? (int) substr($lastPolicy->policy_number, -4) + 1 : 1;
            $policyNumber = $prefix . str_pad($sequence, 8, '0', STR_PAD_LEFT);

            // 2. Update Investment Status
            $investment->update([
                'status' => 'approved',
                'policy_number' => $policyNumber,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

            // 3. Trigger Target Achievement Sync
            Target::syncAchievement(
                $investment->unit_head_id,
                $investment->target_period_key,
                $investment->investment_amount
            );

            // 4. Calculate and Store Commissions
            $this->processCommissions($investment);

            DB::commit();

            Log::info('Investment approved', [
                'investment_id' => $investment->id,
                'policy_number' => $investment->policy_number,
                'approved_by' => $user->id
            ]);

            $investment->load(['customer.user', 'investmentProduct']);

            // 5. Send Welcome Notifications (Email & SMS)
            try {
                $customer = $investment->customer;
                $recipientEmail = $customer->email ?? null;
                $recipientPhone = $customer->phone_primary ?? null;

                $data = [
                    'customer_name' => $customer->full_name,
                    'policy_number' => $investment->policy_number,
                    'investment_amount' => $investment->investment_amount,
                    'product_name' => $investment->investmentProduct->name,
                    'duration_months' => $investment->investmentProduct->duration_months,
                    'monthly_payout_day' => Carbon::parse($investment->monthly_payment_date)->day,
                ];

                // Send Email
                if ($recipientEmail) {
                    Mail::to($recipientEmail)->send(new InvestmentApprovedMail($data));
                }

                // Send SMS
                if ($recipientPhone) {
                    $amount = number_format($investment->investment_amount, 0);
                    $duration = $investment->investmentProduct->duration_months ?? 0;
                    $welcomeSms = "Dear {$investment->customer->full_name},\n\n" .
                        "Welcome to CDP Empire!\n\n" .
                        "Your Investment has been successfully created.\n\n" .
                        "━━━━━━━━━━━━━━━━━━━\n" .
                        "INVESTMENT DETAILS:\n" .
                        "━━━━━━━━━━━━━━━━━━━\n" .
                        "Amount: LKR {$amount}/-\n" .
                        "Duration: {$duration} Months\n" .
                        "Policy No: {$investment->policy_number}\n\n" .
                        "Thank you for choosing CDP Empire (Pvt) Ltd.\n\n" .
                        "For any inquiries:\n" .
                        "Hotline: +94 114 007 007\n" .
                        "Website: https://cdp.lk/";

                    $smsService->sendSms($recipientPhone, $welcomeSms);
                }
            } catch (\Throwable $notificationError) {
                Log::error('Failed to send investment approval notifications', [
                    'investment_id' => $investment->id,
                    'error' => $notificationError->getMessage()
                ]);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Investment approved and policy generated successfully',
                'data' => $investment
            ], 200);
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Investment approval failed', [
                'error' => $th->getMessage(),
                'investment_id' => $id
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to approve investment',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate and store commissions for a newly approved investment.
     */
    protected function processCommissions(Investment $investment)
    {
        // 1. Fetch percentages from the linked InvestmentProduct
        $product = $investment->investmentProduct;

        if (!$product) {
            Log::warning('Commission processing skipped: Investment product not found', [
                'investment_id' => $investment->id
            ]);
            return;
        }

        $unitHeadPct = $product->unit_head_commission_pct ?? 0;
        $parentPct = $product->parent_commission_pct ?? 0;

        $amount = $investment->investment_amount;

        // 2. Unit Head Commission
        if ($investment->unit_head_id) {
            $unitHeadCommissionAmount = ($amount * $unitHeadPct) / 100;

            Commission::create([
                'investment_id' => $investment->id,
                'user_id' => $investment->unit_head_id,
                'investment_amount' => $amount,
                'commission_amount' => $unitHeadCommissionAmount,
                'commission_percentage' => $unitHeadPct,
                'tier' => 'unit_head',
                'period_key' => $investment->target_period_key,
                'status' => 'pending',
            ]);

            // 3. Parent Commission
            $unitHead = $investment->unitHead;
            if ($unitHead && $unitHead->parent_user_id) {
                Commission::create([
                    'investment_id' => $investment->id,
                    'user_id' => $unitHead->parent_user_id,
                    'investment_amount' => $amount,
                    'commission_amount' => ($unitHeadCommissionAmount * $parentPct) / 100,
                    'commission_percentage' => $parentPct,
                    'tier' => 'parent',
                    'period_key' => $investment->target_period_key,
                    'status' => 'pending',
                ]);
            }
        }
    }

    /**
     * Get a paginated list of all investments with their detailed maturity schedules (Year and Month labels).
     */
    public function investorMaturity(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $user = Auth::guard('api')->user();

            $query = Investment::with(['customer', 'investmentProduct.annualRates', 'branch', 'creator']);

            // 1. Hierarchy Visibility Logic
            if (!$user->hasRole('Super Admin') && ($user->user_type !== 'admin')) {
                // Hierarchical users see their own and descendants' investments
                $descendantIds = $user->getAllDescendantIds();
                $accessibleUserIds = array_merge([$user->id], $descendantIds);
                $query->whereIn('created_by', $accessibleUserIds);
            }

            // 2. Filters
            if ($request->has('investment_product_id')) {
                $query->where('investment_product_id', $request->investment_product_id);
            }

            if ($request->has('period_key')) {
                $query->where('target_period_key', $request->period_key);
            }

            if ($request->has('branch_id')) {
                $query->where('branch_id', $request->branch_id);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('customer', function ($cq) use ($search) {
                        $cq->where('full_name', 'like', "%{$search}%")
                            ->orWhere('id_number', 'like', "%{$search}%")
                            ->orWhere('customer_code', 'like', "%{$search}%");
                    })->orWhere('policy_number', 'like', "%{$search}%")
                        ->orWhere('application_number', 'like', "%{$search}%")
                        ->orWhere('sales_code', 'like', "%{$search}%");
                });
            }

            // 3. Status Filter (Default to approved for maturity analysis)
            $status = $request->get('status', 'approved');
            $query->where('status', $status);

            // 4. Execution & Pagination
            $investments = $query->orderBy('created_at', 'desc')->paginate($perPage);

            // 5. Transform data to include detailed maturity schedules
            $investments->getCollection()->transform(function ($inv) {
                $calculations = [];
                if ($inv->investmentProduct) {
                    $calculations = $this->calculateInvestmentROI((float)$inv->investment_amount, $inv->investmentProduct);
                }

                $startDate = $inv->reservation_date ?? $inv->created_at;
                $monthlySchedule = [];
                // Use a copy of the start date for calculation, ensure it's Carbon
                $payoutDate = Carbon::parse($startDate);

                foreach ($calculations['yearly_breakdown'] ?? [] as $yearData) {
                    $monthlyPayout = $yearData['monthly_payout'];
                    $monthsInYear = $yearData['duration_months'];

                    for ($i = 0; $i < $monthsInYear; $i++) {
                        $payoutDate->addMonth(); // Payout usually starts 1 month after reservation
                        $monthlySchedule[] = [
                            'date' => $payoutDate->format('Y-m-d'),
                            'year' => $payoutDate->year,
                            'month' => $payoutDate->format('F'),
                            'payout_amount' => round($monthlyPayout, 2)
                        ];
                    }
                }

                return [
                    'id' => $inv->id,
                    'policy_number' => $inv->policy_number,
                    'customer_name' => $inv->customer->full_name ?? 'N/A',
                    'id_number' => $inv->customer->id_number ?? 'N/A',
                    'plan' => $inv->investmentProduct->name ?? 'N/A',
                    'invest_date' => $inv->reservation_date ? $inv->reservation_date->format('Y-m-d') : 'N/A',
                    'investment_amount' => (float)$inv->investment_amount,
                    'duration_months' => $inv->investmentProduct->duration_months ?? 0,
                    'monthly_maturity' => round($calculations['monthly_return'] ?? 0, 2),
                    'total_maturity' => round($calculations['maturity_amount'] ?? 0, 2),
                    'total_interest' => round($calculations['total_interest'] ?? 0, 2),
                    'detailed_payout_schedule' => $monthlySchedule,
                    'creator' => $inv->creator->name ?? 'N/A',
                    'branch' => $inv->branch->name ?? 'N/A',
                    'status' => $inv->status
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Investment maturity data retrieved successfully',
                'data' => $investments
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Investment maturity report failed in InvestmentController', [
                'error' => $th->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve investment maturity report',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
