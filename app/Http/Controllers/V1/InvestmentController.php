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
use App\Models\InvestmentPayout;
use App\Http\Requests\UpdateInvestmentRequest;
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
            new Middleware('permission:Investment Cancel', only: ['cancel']),
            new Middleware('permission:Investment Terminate', only: ['terminate']),
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
            if ($user->hasRole('Branch Coordinator')) {
                $assignedBranchIds = $user->assignedBranches()->pluck('branches.id')->toArray();
                $query->whereIn('investments.branch_id', $assignedBranchIds, 'and', false);
            } elseif (!$user->hasRole('Super Admin') && ($user->user_type !== 'admin')) {
                // Hierarchical users (GM, AGM, etc.) see their own and descendants
                $descendantIds = $user->getAllDescendantIds();
                $accessibleUserIds = array_merge([$user->id], $descendantIds);

                $query->whereIn('created_by', $accessibleUserIds, 'and', false);
            }

            // Branch Filter (Admins can filter by branch)
            if ($request->has('branch_id')) {
                if ($user->hasRole('Branch Coordinator')) {
                    $assignedBranchIds = $user->assignedBranches()->pluck('branches.id')->toArray();
                    if (!in_array($request->branch_id, $assignedBranchIds)) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Unauthorized access to this branch data.'
                        ], 403);
                    }
                }
                $query->where('investments.branch_id', '=', $request->branch_id, 'and');
            }

            // Search by Policy, Application, Sales Code, or Customer Name
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('policy_number', 'like', "%{$search}%", 'and')
                        ->orWhere('application_number', 'like', "%{$search}%", 'and')
                        ->orWhere('sales_code', 'like', "%{$search}%", 'and')
                        ->orWhereHas('customer', function ($cq) use ($search) {
                            $cq->where('full_name', 'like', "%{$search}%", 'and');
                        });
                });
            }

            // Status Filter
            if ($request->has('status')) {
                $query->where('status', '=', $request->status, 'and');
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
            $targetExists = Target::where('user_id', '=', $unitHeadId, 'and')
                ->where('period_key', '=', $targetPeriodKey, 'and')
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

            $lastApp = Investment::where('application_number', 'like', $appPrefix . '%', 'and')
                ->orderBy('application_number', 'desc')
                ->first();

            $appSequence = $lastApp ? (int) substr($lastApp->application_number, -4) + 1 : 1;
            $data['application_number'] = $appPrefix . str_pad((string)$appSequence, 4, '0', STR_PAD_LEFT);

            // 4. Auto-generate Sales Code: {BranchCode}-{Sequence}
            $salesPrefix = $branch->code . '-';
            $lastSales = Investment::where('sales_code', 'like', $salesPrefix . '%', 'and')
                ->orderBy('sales_code', 'desc')
                ->first();

            $salesSequence = $lastSales ? (int) substr($lastSales->sales_code, -4) + 1 : 1;
            $data['sales_code'] = $salesPrefix . str_pad((string)$salesSequence, 4, '0', STR_PAD_LEFT);

            // 5. Handle Nested Beneficiary Creation
            if ($request->has('beneficiary')) {
                $beneficiaryData = $request->beneficiary;

                if ($request->hasFile('beneficiary.id_image')) {
                    $idImagePath = $this->handleFileUpload($request, 'beneficiary.id_image', null, 'beneficiaries/id_images');
                    $beneficiaryData['id_image'] = $idImagePath;
                }

                if ($request->hasFile('beneficiary.child_file')) {
                    $childFilePath = $this->handleFileUpload($request, 'beneficiary.child_file', null, 'beneficiaries/child_files');
                    $beneficiaryData['child_file'] = $childFilePath;
                }

                $beneficiary = Beneficiary::create(array_merge($beneficiaryData, [
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

    /**
     * Approve the specified investment.
     */
    public function approve(Request $request, SmsService $smsService, string $id)
    {
        DB::beginTransaction();
        try {
            $user = Auth::guard('api')->user();
            $investment = Investment::with(['branch', 'unitHead'])->findOrFail($id);

            if ($investment->status === 'cancelled') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cancelled investments cannot be approved.'
                ], 422);
            }

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

            $lastPolicy = Investment::where('policy_number', 'like', $prefix . '%', 'and')
                ->orderBy('policy_number', 'desc')
                ->first();

            $sequence = $lastPolicy ? (int) substr($lastPolicy->policy_number, -4) + 1 : 1;
            $policyNumber = $prefix . str_pad((string)$sequence, 8, '0', STR_PAD_LEFT);

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
                $investment->investment_amount,
                false
            );

            // 4. Calculate and Store Commissions
            $this->processCommissions($investment);

            // 4.1 Generate Payout Schedule
            $this->generatePayoutSchedule($investment);

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

                $sendSms = $request->boolean('send_sms', false);

                // Send Email
                if ($sendSms && $recipientEmail) {
                    Mail::to($recipientEmail)->send(new InvestmentApprovedMail($data));
                }

                // Send SMS

                if ($sendSms && $recipientPhone) {
                    $amount = number_format((float)$investment->investment_amount, 0);
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
     * Cancel the specified investment.
     */

    /**
     * Calculate and store commissions for a newly approved investment.
     */
    protected function processCommissions(Investment $investment)
    {
        Commission::generateForInvestment($investment);
    }

    /**
     * Generate monthly payout schedule for an approved investment.
     */
    protected function generatePayoutSchedule(Investment $investment)
    {
        try {
            $product = $investment->investmentProduct;
            if (!$product) return;

            $calculations = $this->calculateInvestmentROI((float)$investment->investment_amount, $product);

            $startDate = $investment->reservation_date ?? $investment->created_at;
            $payoutDate = Carbon::parse($startDate);

            $payouts = [];
            foreach ($calculations['yearly_breakdown'] ?? [] as $yearData) {
                $monthlyPayout = $yearData['monthly_payout'];
                $monthsInYear = $yearData['duration_months'];

                for ($i = 0; $i < $monthsInYear; $i++) {
                    $payoutDate->addMonth(); // Payout starts 1 month after reservation
                    $payouts[] = [
                        'investment_id' => $investment->id,
                        'scheduled_date' => $payoutDate->format('Y-m-d'),
                        'amount' => round($monthlyPayout, 2),
                        'status' => 'unpaid',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            if (!empty($payouts)) {
                InvestmentPayout::insert($payouts);
            }
        } catch (\Throwable $th) {
            Log::error('Failed to generate payout schedule', [
                'investment_id' => $investment->id,
                'error' => $th->getMessage()
            ]);
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

            $query = Investment::with(['customer', 'investmentProduct.annualRates', 'branch', 'creator', 'bankDetail']);

            // 1. Hierarchy Visibility Logic
            if ($user->hasRole('Branch Coordinator')) {
                $assignedBranchIds = $user->assignedBranches()->pluck('branches.id')->toArray();
                $query->whereIn('investments.branch_id', $assignedBranchIds, 'and', false);
            } elseif (!$user->hasRole('Super Admin') && ($user->user_type !== 'admin')) {
                // Hierarchical users see their own and descendants' investments
                $descendantIds = $user->getAllDescendantIds();
                $accessibleUserIds = array_merge([$user->id], $descendantIds);
                $query->whereIn('created_by', $accessibleUserIds, 'and', false);
            }

            // 2. Filters
            if ($request->has('investment_product_id')) {
                $query->where('investment_product_id', '=', $request->investment_product_id, 'and');
            }

            if ($request->has('period_key')) {
                $query->where('target_period_key', '=', $request->period_key, 'and');
            }

            if ($request->has('branch_id')) {
                if ($user->hasRole('Branch Coordinator')) {
                    $assignedBranchIds = $user->assignedBranches()->pluck('branches.id')->toArray();
                    if (!in_array($request->branch_id, $assignedBranchIds)) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Unauthorized access to this branch data.'
                        ], 403);
                    }
                }
                $query->where('investments.branch_id', '=', $request->branch_id, 'and');
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('customer', function ($cq) use ($search) {
                        $cq->where('full_name', 'like', "%{$search}%", 'and')
                            ->orWhere('id_number', 'like', "%{$search}%", 'and')
                            ->orWhere('customer_code', 'like', "%{$search}%", 'and');
                    })->orWhere('policy_number', 'like', "%{$search}%", 'and')
                        ->orWhere('application_number', 'like', "%{$search}%", 'and')
                        ->orWhere('sales_code', 'like', "%{$search}%", 'and');
                });
            }

            // 3. Status Filter (Default to approved for maturity analysis)
            $status = $request->get('status', 'approved');
            $query->where('status', '=', $status, 'and');

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
                    'account_details' => [
                        'bank_name' => $inv->bankDetail->bank_name ?? 'N/A',
                        'branch_name' => $inv->bankDetail->branch_name ?? 'N/A',
                        'account_number' => $inv->bankDetail->account_number ?? 'N/A',
                        'payment_method' => $inv->bankDetail->payment_method ?? 'N/A',
                    ],
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

    public function update(UpdateInvestmentRequest $request, string $id)
    {
        DB::beginTransaction();
        try {
            $user = Auth::guard('api')->user();
            $investment = Investment::with(['beneficiary', 'bankDetail'])->findOrFail($id);

            // 1. Strict Role Check: Only Super Admin can edit
            if (!$user->hasRole('Super Admin')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only Super Admin can update investment details.'
                ], 403);
            }

            if ($investment->status === 'cancelled') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cancelled investments cannot be modified.'
                ], 422);
            }

            $data = $request->validated();
            $oldAmount = (float) $investment->investment_amount;
            $oldUnitHeadId = $investment->unit_head_id;
            $oldPeriodKey = $investment->target_period_key;
            $status = $investment->status;

            // 2. Handle reservation_date -> target_period_key
            if (isset($data['reservation_date'])) {
                $reservationDate = Carbon::parse($data['reservation_date']);
                $data['target_period_key'] = $reservationDate->format('Y-m');
            }

            // 3. Handle payment_proof file updates
            $imagePath = $this->handleFileUpload($request, 'payment_proof', $investment->payment_proof, 'investments/payment', $investment->application_number);
            if ($imagePath) {
                $data['payment_proof'] = $imagePath;
            }

            // 4. Handle Nested Beneficiary Update/Creation
            if ($request->has('beneficiary')) {
                if ($investment->beneficiary_id && $investment->beneficiary) {
                    $beneficiaryData = $request->beneficiary;

                    if ($request->hasFile('beneficiary.id_image')) {
                        $idImagePath = $this->handleFileUpload($request, 'beneficiary.id_image', $investment->beneficiary->id_image, 'beneficiaries/id_images');
                        $beneficiaryData['id_image'] = $idImagePath;
                    }

                    if ($request->hasFile('beneficiary.child_file')) {
                        $childFilePath = $this->handleFileUpload($request, 'beneficiary.child_file', $investment->beneficiary->child_file, 'beneficiaries/child_files');
                        $beneficiaryData['child_file'] = $childFilePath;
                    }

                    $investment->beneficiary->update($beneficiaryData);
                } else {
                    $beneficiaryData = $request->beneficiary;

                    if ($request->hasFile('beneficiary.id_image')) {
                        $idImagePath = $this->handleFileUpload($request, 'beneficiary.id_image', null, 'beneficiaries/id_images');
                        $beneficiaryData['id_image'] = $idImagePath;
                    }

                    if ($request->hasFile('beneficiary.child_file')) {
                        $childFilePath = $this->handleFileUpload($request, 'beneficiary.child_file', null, 'beneficiaries/child_files');
                        $beneficiaryData['child_file'] = $childFilePath;
                    }

                    $beneficiary = Beneficiary::create(array_merge($beneficiaryData, [
                        'customer_id' => $investment->customer_id
                    ]));
                    $data['beneficiary_id'] = $beneficiary->id;
                }
            }

            // 5. Handle Nested Bank Detail Update/Creation
            if ($request->has('bank_detail')) {
                if ($investment->customer_bank_detail_id && $investment->bankDetail) {
                    $investment->bankDetail->update($request->bank_detail);
                } else {
                    $bankDetail = CustomerBankDetail::create(array_merge($request->bank_detail, [
                        'customer_id' => $investment->customer_id
                    ]));
                    $data['customer_bank_detail_id'] = $bankDetail->id;
                }
            }

            $investment->update($data);
            $investment->refresh();

            // 6. Handle Target Re-sync if status is 'approved' and amounts/unit head changed
            if ($status === 'approved') {
                $newAmount = (float) $investment->investment_amount;
                $newUnitHeadId = $investment->unit_head_id;
                $newPeriodKey = $investment->target_period_key;

                if ($oldAmount !== $newAmount || $oldUnitHeadId !== $newUnitHeadId || $oldPeriodKey !== $newPeriodKey) {
                    // Recalculate for old state
                    Target::recalculateForUser($oldUnitHeadId, $oldPeriodKey);

                    // Recalculate for new state (if different)
                    if ($oldUnitHeadId !== $newUnitHeadId || $oldPeriodKey !== $newPeriodKey) {
                        Target::recalculateForUser($newUnitHeadId, $newPeriodKey);
                    }
                }
            }

            DB::commit();

            Log::info('Investment updated by Super Admin', [
                'admin_id' => $user->id,
                'investment_id' => $investment->id,
                'updated_fields' => array_keys($data)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Investment updated successfully',
                'data' => $investment->load(['customer', 'branch', 'investmentProduct', 'beneficiary', 'bankDetail', 'unitHead'])
            ], 200);
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Investment update failed', [
                'error' => $th->getMessage(),
                'admin_id' => Auth::guard('api')->id(),
                'investment_id' => $id
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update investment',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $user = Auth::guard('api')->user();
            $investment = Investment::findOrFail($id);

            // 1. Strict Role Check: Only Super Admin can delete
            if (!$user->hasRole('Super Admin')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only Super Admin can delete investments.'
                ], 403);
            }

            $status = $investment->status;

            // 2. Prevent deletion of approved investments
            if ($status === 'approved') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Approved investments cannot be deleted.'
                ], 422);
            }

            $unitHeadId = $investment->unit_head_id;
            $periodKey = $investment->target_period_key;

            // 3. Handle file cleanup
            if ($investment->payment_proof) {
                $this->deleteFile($investment->payment_proof);
            }

            // 4. Soft Delete
            $investment->delete();

            // 4. Handle Target Re-sync if it was 'approved'
            if ($status === 'approved') {
                Target::recalculateForUser($unitHeadId, $periodKey);
            }

            Log::info('Investment deleted by Super Admin', [
                'admin_id' => $user->id,
                'investment_id' => $id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Investment deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete investment',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function destroyApprovedInvestement(string $id)
    {
        try {
            $user = Auth::guard('api')->user();
            $investment = Investment::findOrFail($id);

            // 1. Strict Role Check: Only Super Admin can delete
            if (!$user->hasRole('Super Admin')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only Super Admin can delete investments.'
                ], 403);
            }

            $status = $investment->status;
            $unitHeadId = $investment->unit_head_id;
            $periodKey = $investment->target_period_key;

            // 3. Handle file cleanup
            if ($investment->payment_proof) {
                $this->deleteFile($investment->payment_proof);
            }

            // 4. Soft Delete
            $investment->delete();

            // 5. Hierarchical Target Re-sync if it was 'approved'
            if ($status === 'approved') {
                Target::recalculateHierarchyTargets($unitHeadId, $periodKey);
            }

            Log::info('Approved investment deleted by Super Admin', [
                'admin_id' => $user->id,
                'investment_id' => $id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Approved investment deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete approved investment',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel or Reject the specified investment based on its current status.
     * 
     * @param \Illuminate\Http\Request $request
     * @param string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancel(Request $request, SmsService $smsService, string $id)
    {
        DB::beginTransaction();
        try {
            $user = Auth::guard('api')->user();
            $investment = Investment::with(['investmentProduct', 'payouts', 'customer'])->findOrFail($id);

            if (in_array($investment->status, ['cancelled', 'rejected'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Investment is already ' . $investment->status . '.'
                ], 422);
            }

            $request->validate([
                'cancellation_reason' => 'required|string|max:1000'
            ]);

            $reason = $request->cancellation_reason;
            $oldStatus = $investment->status;

            if ($oldStatus === 'pending') {
                // Scenario A: Pending Investment (Rejection)
                $investment->update([
                    'status' => 'rejected',
                    'rejected_at' => now(),
                    'rejection_reason' => $reason
                ]);

                DB::commit();

                // Send SMS for Rejection
                try {
                    $sendSms = $request->boolean('send_sms', false);
                    $recipientPhone = $investment->customer->phone_primary ?? null;

                    if ($sendSms && $recipientPhone) {
                        $rejectionSms = "Dear {$investment->customer->full_name},\n\n" .
                            "Your Investment application has been rejected.\n" .
                            "Application No: {$investment->application_number}\n" .
                            "Reason: {$reason}\n\n" .
                            "Thank you for choosing CDP Empire (Pvt) Ltd.\n\n" .
                            "For any inquiries:\n" .
                            "Hotline: +94 114 007 007\n" .
                            "Website: https://cdp.lk/";

                        $smsService->sendSms($recipientPhone, $rejectionSms);
                    }
                } catch (\Throwable $smsError) {
                    Log::error('Failed to send rejection SMS', ['error' => $smsError->getMessage()]);
                }

                return response()->json([
                    'status' => 'success',
                    'message' => 'Investment rejected successfully',
                    'data' => [
                        'investment' => $investment->load(['customer:id,full_name', 'branch:id,name']),
                        'status' => 'rejected'
                    ]
                ], 200);
            }

            // Scenario B: Approved Investment (Cancellation)
            // 1. Calculate Payout Deduction based on complete months completed
            $reservationDate = Carbon::parse($investment->reservation_date);
            $completeMonthsActive = (int) $reservationDate->diffInMonths(now());

            // Get the monthly payout amount (assuming it's consistent across payouts)
            $firstPayout = $investment->payouts()->first();
            $monthlyPayoutAmount = $firstPayout ? (float) $firstPayout->amount : 0;
            $totalPayoutDeduction = $monthlyPayoutAmount * $completeMonthsActive;

            // Check for 14-day rule for Admin Cost deduction
            $reservationDate = Carbon::parse($investment->reservation_date);
            $daysSinceReservation = $reservationDate->diffInDays(now());

            $adminCostAmount = 0;
            if ($daysSinceReservation > 14) {
                $adminCostPercentage = (float) SystemSetting::getSetting('admin_cost', 0);
                $adminCostAmount = (float) $investment->investment_amount * $adminCostPercentage;
            }

            $refundAmount = (float) $investment->investment_amount;
            $refundAmount -= (float) $totalPayoutDeduction;
            $refundAmount -= (float) $adminCostAmount;
            $refundAmount = max(0, $refundAmount);

            // 2. Update Investment Status
            $investment->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'refund_amount' => round($refundAmount, 2),
                'admin_cost_amount' => round($adminCostAmount, 2)
            ]);

            // 3. Hierarchical Target Achievement Recalculation
            Target::recalculateHierarchyTargets($investment->unit_head_id, $investment->target_period_key);

            // 4. Commission Recovery
            $this->recoverCommissions($investment);

            // 5. Payout Adjustment
            $investment->payouts()->where('status', 'unpaid')->update(['status' => 'cancelled']);

            DB::commit();

            // Send SMS for Cancellation
            try {
                $sendSms = $request->boolean('send_sms', false);
                $recipientPhone = $investment->customer->phone_primary ?? null;

                if ($sendSms && $recipientPhone) {
                    $invAmount = number_format((float)$investment->investment_amount, 2);
                    $penalty = number_format((float)$adminCostAmount, 2);
                    $reduction = number_format((float)$totalPayoutDeduction, 2);
                    $refund = number_format((float)$refundAmount, 2);

                    $cancelSms = "Dear {$investment->customer->full_name},\n\n" .
                        "Your Investment [{$investment->policy_number}] has been cancelled.\n\n" .
                        "━━━━━━━━━━━━━━━━━━━\n" .
                        "DETAILS:\n" .
                        "━━━━━━━━━━━━━━━━━━━\n" .
                        "Inv. Amount: LKR {$invAmount}\n" .
                        "Admin Cost: LKR {$penalty}\n" .
                        "Paid Divider: LKR {$reduction}\n" .
                        "Refund: LKR {$refund}\n" .
                        "━━━━━━━━━━━━━━━━━━━\n" .
                        "Thank you for choosing CDP Empire (Pvt) Ltd.\n\n" .
                        "For any inquiries:\n" .
                        "Hotline: +94 114 007 007\n" .
                        "Website: https://cdp.lk/";

                    $smsService->sendSms($recipientPhone, $cancelSms);
                }
            } catch (\Throwable $smsError) {
                Log::error('Failed to send cancellation SMS', ['error' => $smsError->getMessage()]);
            }

            $commissions = Commission::where('investment_id', $investment->id)
                ->with('user:id,name,employee_code')
                ->get();

            $totalRecoverAmount = $commissions->sum('recover_amount');

            return response()->json([
                'status' => 'success',
                'message' => 'Investment cancelled successfully',
                'data' => [
                    'investment' => $investment->load([
                        'customer:id,full_name,customer_code,id_number',
                        'branch:id,name,code',
                        'unitHead:id,name,employee_code',
                        'investmentProduct:id,name,code,duration_months'
                    ]),
                    'refund_amount' => $refundAmount,
                    'penalty_amount' => $adminCostAmount,
                    'payout_reduction_amount' => $totalPayoutDeduction,
                    'reduced_payout_count' => $completeMonthsActive,
                    'recover_commission_amount' => $totalRecoverAmount,
                    'commissions' => $commissions->map(function ($comm) {
                        return [
                            'user' => $comm->user->name ?? 'N/A',
                            'tier' => $comm->tier,
                            'original_amount' => $comm->commission_amount,
                            'earned_amount' => $comm->earned_amount,
                            'recover_amount' => $comm->recover_amount,
                            'status' => $comm->status
                        ];
                    })
                ]
            ], 200);
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Investment action failed', [
                'error' => $th->getMessage(),
                'investment_id' => $id
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process investment action',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Terminate the specified investment (Clean cancellation).
     * Only for approved investments. No deductions. Future payouts cancelled.
     */
    public function terminate(Request $request, SmsService $smsService, string $id)
    {
        DB::beginTransaction();
        try {
            $user = Auth::guard('api')->user();
            $investment = Investment::with(['investmentProduct', 'payouts', 'customer'])->findOrFail($id);

            if ($investment->status !== 'approved') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only approved investments can be terminated.'
                ], 422);
            }

            $request->validate([
                'termination_reason' => 'required|string|max:1000'
            ]);

            $reason = $request->termination_reason;

            // Update Investment Status to Terminated
            // Full refund (no deductions)
            $investment->update([
                'status' => 'terminated',
                'terminated_at' => now(),
                'termination_reason' => $reason,
                'refund_amount' => $investment->investment_amount
            ]);

            // Cancel future unpaid payouts
            $investment->payouts()->where('status', 'unpaid')->update(['status' => 'cancelled']);

            // Note: No commission recovery, no target recalculation as requested by user

            DB::commit();

            // Send SMS for Termination
            try {
                $sendSms = $request->boolean('send_sms', false);
                $recipientPhone = $investment->customer->phone_primary ?? null;

                if ($sendSms && $recipientPhone) {
                    $invAmount = number_format((float)$investment->investment_amount, 2);
                    $refund = number_format((float)$investment->investment_amount, 2);

                    $terminateSms = "Dear {$investment->customer->full_name},\n\n" .
                        "Your Investment [{$investment->policy_number}] has been terminated.\n\n" .
                        "━━━━━━━━━━━━━━━━━━━\n" .
                        "DETAILS:\n" .
                        "━━━━━━━━━━━━━━━━━━━\n" .
                        "Inv. Amount: LKR {$invAmount}\n" .
                        "Refund: LKR {$refund}\n" .
                        "━━━━━━━━━━━━━━━━━━━\n" .
                        "Thank you for choosing CDP Empire (Pvt) Ltd.\n\n" .
                        "For any inquiries:\n" .
                        "Hotline: +94 114 007 007\n" .
                        "Website: https://cdp.lk/";

                    $smsService->sendSms($recipientPhone, $terminateSms);
                }
            } catch (\Throwable $smsError) {
                Log::error('Failed to send termination SMS', ['error' => $smsError->getMessage()]);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Investment terminated successfully',
                'data' => [
                    'investment' => $investment->load(['customer:id,full_name,customer_code', 'branch:id,name'])->makeHidden('payouts'),
                    'refund_amount' => $investment->investment_amount
                ]
            ], 200);

        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Investment termination failed', [
                'error' => $th->getMessage(),
                'investment_id' => $id
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to terminate investment',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Process commission recovery for a cancelled investment.
     * 
     * @param \App\Models\Investment $investment
     * @return void
     */
    protected function recoverCommissions(Investment $investment)
    {
        $commissions = Commission::where('investment_id', $investment->id)->get();
        $reservationDate = Carbon::parse($investment->reservation_date);
        $daysSinceReservation = $reservationDate->diffInDays(now());

        // Use complete months only for calculation (ignore partial days/hours)
        $totalMonths = (int) ($investment->investmentProduct->duration_months ?? 12);
        $completeMonthsActive = (int) $reservationDate->diffInMonths(now());
        $completeMonthsActive = min($completeMonthsActive, $totalMonths);

        foreach ($commissions as $commission) {
            // Rule 1: If cancelled within 14 days, no commission is earned (full recovery)
            if ($daysSinceReservation <= 14) {
                $commission->update([
                    'status' => 'cancelled',
                    'earned_amount' => 0,
                    'recover_amount' => $commission->commission_amount
                ]);
                continue;
            }

            // Rule 2: Based on complete months active
            if ($totalMonths > 0) {
                $earnedAmount = ((float)$commission->commission_amount / $totalMonths) * $completeMonthsActive;
                $recoverAmount = (float)$commission->commission_amount - $earnedAmount;

                $commission->update([
                    'status' => 'cancelled',
                    'earned_amount' => round($earnedAmount, 2),
                    'recover_amount' => round($recoverAmount, 2)
                ]);
            }
        }
    }
}
