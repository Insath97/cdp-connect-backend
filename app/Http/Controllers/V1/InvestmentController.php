<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateInvestmentRequest;
use App\Models\Investment;
use App\Models\Branch;
use App\Models\Target;
use App\Models\Beneficiary;
use App\Models\CustomerBankDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class InvestmentController extends Controller
{
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

            // Ordering: GM -> AGM -> Branch (Hierarchical Order)
            // Use leftJoin to avoid filtering out records that might not have a level or branch (e.g., Super Admin entries)
            $investments = $query->leftJoin('users', 'investments.created_by', '=', 'users.id')
                ->leftJoin('levels', 'users.level_id', '=', 'levels.id')
                ->leftJoin('branches', 'investments.branch_id', '=', 'branches.id')
                ->select('investments.*')
                ->orderByRaw('COALESCE(levels.tire_level, 999) ASC')
                ->orderBy('branches.name', 'asc')
                ->orderBy('investments.created_at', 'desc')
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
            $data['status'] = 'pending';

            $investment = Investment::create($data);

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
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    public function update(Request $request, string $id)
    {

    }

    /**
     * Approve the specified investment.
     */
    public function approve(Request $request, string $id)
    {
        DB::beginTransaction();
        try {
            $user = Auth::guard('api')->user();
            $investment = Investment::with('branch')->findOrFail($id);

            if ($investment->status !== 'pending') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only pending investments can be approved.'
                ], 422);
            }

            // 1. Generate Policy Number: {BranchCode}-{YYMM}{Sequence}
            $branch = $investment->branch;
            $yymm = date('ym');
            $prefix = 'CDP'.$branch->code . '-';

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

            DB::commit();

            Log::info('Investment approved', [
                'investment_id' => $investment->id,
                'policy_number' => $policyNumber,
                'approved_by' => $user->id
            ]);

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
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
