<?php

namespace App\Http\Controllers\V1;

use App\Traits\ActivityLogTrait;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateLegalRequest;
use App\Http\Requests\UpdateLegalRequest;
use App\Models\Investment;
use App\Models\Legal;
use App\Traits\InvestmentCalculationTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LegalController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    use InvestmentCalculationTrait;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Legal Index', only: ['index', 'show', 'invesmentIndex', 'invesmentShow']),
            new Middleware('permission:Legal Create', only: ['store']),
            new Middleware('permission:Legal Update', only: ['update']),
            new Middleware('permission:Legal Delete', only: ['destroy']),
        ];
    }

    private function transformLegal(Legal $legal)
    {
        return [
            'id' => $legal->id,
            'legal_number' => $legal->legal_number,
            'date_of_agreement' => $legal->date_of_agreement ? $legal->date_of_agreement->format('Y-m-d') : null,
            'language' => $legal->language,
            'full_name' => $legal->full_name,
            'name_with_initials' => $legal->name_with_initials,
            'id_type' => $legal->id_type,
            'id_number' => $legal->id_number,
            'email' => $legal->email,
            'address_line_1' => $legal->address_line_1,
            'address_line_2' => $legal->address_line_2,
            'city' => $legal->city,
            'state' => $legal->state,
            'country' => $legal->country,
            'postal_code' => $legal->postal_code,
            'branch_location' => $legal->branch_location,
            'execution_location' => $legal->execution_location,
            'business_entered_date' => $legal->business_entered_date,
            'completed_date' => $legal->completed_date,
            'execution_year' => $legal->execution_year,
            'amount_in_words' => $legal->amount_in_words,
            'plan' => $legal->plan,
            'monthly_profit' => $legal->monthly_profit,
            'monthly_profit_day' => $legal->monthly_profit_day,

            'monthly_return' => (float) $legal->monthly_return,
            'annual_return' => (float) $legal->annual_return,
            'maturity_amount' => (float) $legal->maturity_amount,
            'month_6_breakdown' => (float) $legal->month_6_breakdown,
            'year_1_breakdown' => (float) $legal->year_1_breakdown,
            'year_2_breakdown' => (float) $legal->year_2_breakdown,
            'year_3_breakdown' => (float) $legal->year_3_breakdown,
            'year_4_breakdown' => (float) $legal->year_4_breakdown,
            'year_5_breakdown' => (float) $legal->year_5_breakdown,
            'yearly_breakdown' => $legal->yearly_breakdown,
            'month_6_breakdown_in_words' => $legal->month_6_breakdown_in_words,
            'year_1_breakdown_in_words' => $legal->year_1_breakdown_in_words,
            'year_2_breakdown_in_words' => $legal->year_2_breakdown_in_words,
            'year_3_breakdown_in_words' => $legal->year_3_breakdown_in_words,
            'year_4_breakdown_in_words' => $legal->year_4_breakdown_in_words,
            'year_5_breakdown_in_words' => $legal->year_5_breakdown_in_words,

            'bank_name' => $legal->bank_name,
            'branch_name' => $legal->branch_name,
            'account_number' => $legal->account_number,

            'beneficiary_full_name' => $legal->beneficiary_full_name,
            'beneficiary_id_type' => $legal->beneficiary_id_type,
            'beneficiary_id_number' => $legal->beneficiary_id_number,
            'beneficiary_phone_primary' => $legal->beneficiary_phone_primary,
            'beneficiary_relationship' => $legal->beneficiary_relationship,
            'beneficiary_share_percentage' => (float) $legal->beneficiary_share_percentage,

            'witness_01_name' => $legal->witness_01_name,
            'witness_01_nic' => $legal->witness_01_nic,
            'witness_01_address' => $legal->witness_01_address,
            'witness_02_name' => $legal->witness_02_name,
            'witness_02_nic' => $legal->witness_02_nic,
            'witness_02_address' => $legal->witness_02_address,

            'investment' => $legal->investment ? [
                'id' => $legal->investment->id,
                'policy_number' => $legal->investment->policy_number,
                'application_number' => $legal->investment->application_number,
                'sales_code' => $legal->investment->sales_code,
                'investment_amount' => (float) $legal->investment->investment_amount,
                'payment_type' => $legal->investment->payment_type,
                'business_type' => $legal->investment->business_type,
                'status' => $legal->investment->status,
                'reservation_date' => $legal->investment->reservation_date,
                'created_at' => $legal->investment->created_at ? $legal->investment->created_at->toIso8601String() : null,
                'creator' => $legal->investment->creator ? [
                    'id' => $legal->investment->creator->id,
                    'name' => $legal->investment->creator->name,
                    'email' => $legal->investment->creator->email,
                    'is_active' => (bool) $legal->investment->creator->is_active,
                ] : null,
                'investment_product' => $legal->investment->investmentProduct ? [
                    'id' => $legal->investment->investmentProduct->id,
                    'name' => $legal->investment->investmentProduct->name,
                    'code' => $legal->investment->investmentProduct->code,
                    'duration_months' => $legal->investment->investmentProduct->duration_months,
                    'roi_percentage' => (float) $legal->investment->investmentProduct->roi_percentage,
                ] : null,
                'branch' => $legal->investment->branch ? [
                    'id' => $legal->investment->branch->id,
                    'name' => $legal->investment->branch->name,
                    'code' => $legal->investment->branch->code,
                ] : null,
                'unit_head' => $legal->investment->unitHead ? [
                    'id' => $legal->investment->unitHead->id,
                    'name' => $legal->investment->unitHead->name,
                    'email' => $legal->investment->unitHead->email,
                    'employee_code' => $legal->investment->unitHead->employee_code,
                    'is_active' => (bool) $legal->investment->unitHead->is_active,
                ] : null,
            ] : null,
        ];
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = Legal::with([
                'investment.creator',
                'investment.investmentProduct',
                'investment.bankDetail',
                'investment.beneficiary',
                'investment.branch',
                'investment.customer',
                'investment.unitHead',
            ]);

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('legal_number', 'like', "%{$search}%")
                        ->orWhere('full_name', 'like', "%{$search}%")
                        ->orWhere('id_number', 'like', "%{$search}%");
                });
            }

            if ($request->has('language')) {
                $query->where('language', $request->language);
            }

            if ($request->has('branch_id')) {
                $query->where('branch_id', $request->branch_id);
            }

            if ($request->has('customer_id')) {
                $query->where('customer_id', $request->customer_id);
            }

            if ($request->has('investment_product_id')) {
                $query->where('investment_product_id', $request->investment_product_id);
            }

            if ($request->has('investment_id')) {
                $query->where('investment_id', $request->investment_id);
            }

            if ($request->has('start_date')) {
                $query->whereDate('created_at', '>=', $request->start_date);
            }

            if ($request->has('end_date')) {
                $query->whereDate('created_at', '<=', $request->end_date);
            }

            if ($request->boolean('today', false)) {
                $query->whereDate('created_at', Carbon::today());
            }

            $legals = $query->orderBy('created_at', 'desc')->paginate($perPage);

            $transformedLegals = $legals->getCollection()->map(function ($legal) {
                return $this->transformLegal($legal);
            });

            $legals->setCollection($transformedLegals);

            return response()->json([
                'status' => 'success',
                'message' => 'Legals retrieved successfully',
                'data' => $legals,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve legals',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function store(CreateLegalRequest $request)
    {
        DB::beginTransaction();
        try {
            $data = $request->validated();

            // Check if a Legal record already exists for the combination of investment_id and language
            $existingLegal = Legal::query()->where('investment_id', $data['investment_id'])
                ->where('language', $data['language'])
                ->first();

            if ($existingLegal) {
                // If it exists, update it instead of creating a new one
                $investment = Investment::with(['customer', 'branch', 'beneficiary', 'bankDetail'])->findOrFail($existingLegal->investment_id);
                $calculations = [];
                if ($investment->investmentProduct) {
                    $calculations = $this->calculateInvestmentROI(
                        (float) $investment->investment_amount,
                        $investment->investmentProduct
                    );
                }

                $existingLegal->update([
                    'date_of_agreement' => $data['date_of_agreement'] ?? $existingLegal->date_of_agreement,
                    'year_in_words' => $data['year_in_words'] ?? $existingLegal->year_in_words,
                    'year' => $data['year'] ?? $existingLegal->year,
                    'full_name' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['full_name'] ?? $existingLegal->full_name) : $existingLegal->full_name,
                    'name_with_initials' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['name_with_initials'] ?? $existingLegal->name_with_initials) : $existingLegal->name_with_initials,
                    'address_line_1' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['address_line_1'] ?? $existingLegal->address_line_1) : $existingLegal->address_line_1,
                    'address_line_2' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['address_line_2'] ?? $existingLegal->address_line_2) : $existingLegal->address_line_2,
                    'city' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['city'] ?? $existingLegal->city) : $existingLegal->city,
                    'state' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['state'] ?? $existingLegal->state) : $existingLegal->state,
                    'country' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['country'] ?? $existingLegal->country) : $existingLegal->country,
                    'branch_location' => $data['branch_location'] ?? $existingLegal->branch_location,
                    'execution_location' => $data['execution_location'] ?? $existingLegal->execution_location,
                    'business_entered_date' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['business_entered_date'] ?? $existingLegal->business_entered_date) : $existingLegal->business_entered_date,
                    'completed_date' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['completed_date'] ?? $existingLegal->completed_date) : $existingLegal->completed_date,
                    'execution_year' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['execution_year'] ?? $existingLegal->execution_year) : $existingLegal->execution_year,
                    'amount_in_words' => in_array($data['language'], ['tamil', 'english']) ? ($data['amount_in_words'] ?? $existingLegal->amount_in_words) : $existingLegal->amount_in_words,
                    'plan' => in_array($data['language'], ['tamil', 'english']) ? ($data['plan'] ?? $existingLegal->plan) : $existingLegal->plan,
                    'monthly_profit' => in_array($data['language'], ['tamil', 'english']) ? ($data['monthly_profit'] ?? $existingLegal->monthly_profit) : $existingLegal->monthly_profit,
                    'monthly_profit_day' => in_array($data['language'], ['tamil', 'english']) ? ($data['monthly_profit_day'] ?? $existingLegal->monthly_profit_day) : $existingLegal->monthly_profit_day,
                    'month_6_breakdown_in_words' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['month_6_breakdown_in_words'] ?? $existingLegal->month_6_breakdown_in_words) : $existingLegal->month_6_breakdown_in_words,
                    'year_1_breakdown_in_words' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['year_1_breakdown_in_words'] ?? $existingLegal->year_1_breakdown_in_words) : $existingLegal->year_1_breakdown_in_words,
                    'year_2_breakdown_in_words' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['year_2_breakdown_in_words'] ?? $existingLegal->year_2_breakdown_in_words) : $existingLegal->year_2_breakdown_in_words,
                    'year_3_breakdown_in_words' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['year_3_breakdown_in_words'] ?? $existingLegal->year_3_breakdown_in_words) : $existingLegal->year_3_breakdown_in_words,
                    'year_4_breakdown_in_words' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['year_4_breakdown_in_words'] ?? $existingLegal->year_4_breakdown_in_words) : $existingLegal->year_4_breakdown_in_words,
                    'year_5_breakdown_in_words' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['year_5_breakdown_in_words'] ?? $existingLegal->year_5_breakdown_in_words) : $existingLegal->year_5_breakdown_in_words,
                    'witness_01_name' => $data['witness_01_name'] ?? $existingLegal->witness_01_name,
                    'witness_01_nic' => $data['witness_01_nic'] ?? $existingLegal->witness_01_nic,
                    'witness_01_address' => $data['witness_01_address'] ?? $existingLegal->witness_01_address,
                    'witness_02_name' => $data['witness_02_name'] ?? $existingLegal->witness_02_name,
                    'witness_02_nic' => $data['witness_02_nic'] ?? $existingLegal->witness_02_nic,
                    'witness_02_address' => $data['witness_02_address'] ?? $existingLegal->witness_02_address,
                    'bank_name' => $data['bank_name'] ?? $existingLegal->bank_name,
                    'branch_name' => $data['branch_name'] ?? $existingLegal->branch_name,
                    'account_number' => $data['account_number'] ?? $existingLegal->account_number,
                    'beneficiary_full_name' => $data['beneficiary_full_name'] ?? $existingLegal->beneficiary_full_name,
                    'beneficiary_id_type' => $data['beneficiary_id_type'] ?? $existingLegal->beneficiary_id_type,
                    'beneficiary_id_number' => $data['beneficiary_id_number'] ?? $existingLegal->beneficiary_id_number,
                    'beneficiary_phone_primary' => $data['beneficiary_phone_primary'] ?? $existingLegal->beneficiary_phone_primary,
                    'beneficiary_relationship' => $data['beneficiary_relationship'] ?? $existingLegal->beneficiary_relationship,
                    'beneficiary_share_percentage' => $data['beneficiary_share_percentage'] ?? $existingLegal->beneficiary_share_percentage,

                    'monthly_return' => round($calculations['monthly_return'] ?? 0, 2),
                    'annual_return' => round($calculations['annual_return'] ?? 0, 2),
                    'maturity_amount' => round($calculations['maturity_amount'] ?? 0, 2),
                    'month_6_breakdown' => round($calculations['month_6_breakdown'] ?? 0, 2),
                    'year_1_breakdown' => round($calculations['year_1_breakdown'] ?? 0, 2),
                    'year_2_breakdown' => round($calculations['year_2_breakdown'] ?? 0, 2),
                    'year_3_breakdown' => round($calculations['year_3_breakdown'] ?? 0, 2),
                    'year_4_breakdown' => round($calculations['year_4_breakdown'] ?? 0, 2),
                    'year_5_breakdown' => round($calculations['year_5_breakdown'] ?? 0, 2),
                    'yearly_breakdown' => $calculations['yearly_breakdown'] ?? null,
                ]);

                DB::commit();

                $this->logActivity('Update', 'Legal', 'Legal updated automatically on duplicate request', [
                    'user_id' => Auth::id(),
                    'legal_id' => $existingLegal->id,
                    'investment_id' => $existingLegal->investment_id,
                    'language' => $existingLegal->language,
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Legal agreement updated successfully',
                    'data' => $this->transformLegal($existingLegal),
                ], 200);
            }

            // Create a new legal agreement
            $investment = Investment::with(['customer', 'branch', 'beneficiary', 'bankDetail'])->findOrFail($data['investment_id']);
            $customer = $investment->customer;
            $branch = $investment->branch;
            $beneficiary = $investment->beneficiary;
            $bankDetail = $investment->bankDetail;

            // Generate unique legal_number: LEG-{BranchCode}-{YYMM}{Sequence}
            $yymm = date('ym');
            $prefix = 'LEG-'.($branch->code ?? 'GEN').'-'.$yymm;

            $lastLegal = Legal::query()->where('legal_number', 'like', $prefix.'%')
                ->orderBy('legal_number', 'desc')
                ->first();

            $sequence = $lastLegal ? (int) substr($lastLegal->legal_number, -4) + 1 : 1;
            $legalNumber = $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);

            $calculations = [];
            if ($investment->investmentProduct) {
                $calculations = $this->calculateInvestmentROI(
                    (float) $investment->investment_amount,
                    $investment->investmentProduct
                );
            }

            $legal = Legal::create([
                'investment_id' => $investment->id,
                'language' => $data['language'],
                'legal_number' => $legalNumber,
                'date_of_agreement' => $data['date_of_agreement'] ?? now(),
                'branch_id' => $investment->branch_id,
                'customer_id' => $investment->customer_id,
                'full_name' => in_array($data['language'], ['tamil', 'sinhala']) ? $data['full_name'] : $customer->full_name,
                'name_with_initials' => in_array($data['language'], ['tamil', 'sinhala']) ? $data['name_with_initials'] : $customer->name_with_initials,
                'id_type' => $customer->id_type ?? 'nic',
                'id_number' => $customer->id_number,
                'email' => $customer->email,
                'address_line_1' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['address_line_1'] ?? null) : $customer->address_line_1,
                'address_line_2' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['address_line_2'] ?? null) : $customer->address_line_2,
                'landmark' => $customer->landmark,
                'city' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['city'] ?? null) : $customer->city,
                'state' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['state'] ?? null) : $customer->state,
                'country' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['country'] ?? 'Sri Lanka') : ($customer->country ?? 'Sri Lanka'),
                'postal_code' => $customer->postal_code,
                'branch_location' => $data['branch_location'] ?? null,
                'execution_location' => $data['execution_location'] ?? null,
                'business_entered_date' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['business_entered_date'] ?? null) : null,
                'completed_date' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['completed_date'] ?? null) : null,
                'execution_year' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['execution_year'] ?? null) : null,
                'amount_in_words' => in_array($data['language'], ['tamil', 'english']) ? ($data['amount_in_words'] ?? null) : null,
                'plan' => in_array($data['language'], ['tamil', 'english']) ? ($data['plan'] ?? null) : null,
                'monthly_profit' => in_array($data['language'], ['tamil', 'english']) ? ($data['monthly_profit'] ?? null) : null,
                'monthly_profit_day' => in_array($data['language'], ['tamil', 'english']) ? ($data['monthly_profit_day'] ?? null) : null,
                'month_6_breakdown_in_words' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['month_6_breakdown_in_words'] ?? null) : null,
                'year_1_breakdown_in_words' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['year_1_breakdown_in_words'] ?? null) : null,
                'year_2_breakdown_in_words' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['year_2_breakdown_in_words'] ?? null) : null,
                'year_3_breakdown_in_words' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['year_3_breakdown_in_words'] ?? null) : null,
                'year_4_breakdown_in_words' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['year_4_breakdown_in_words'] ?? null) : null,
                'year_5_breakdown_in_words' => in_array($data['language'], ['tamil', 'sinhala']) ? ($data['year_5_breakdown_in_words'] ?? null) : null,
                'investment_product_id' => $investment->investment_product_id,
                'year_in_words' => $data['year_in_words'] ?? null,
                'year' => $data['year'] ?? null,
                'witness_01_name' => $data['witness_01_name'] ?? null,
                'witness_01_nic' => $data['witness_01_nic'] ?? null,
                'witness_01_address' => $data['witness_01_address'] ?? null,
                'witness_02_name' => $data['witness_02_name'] ?? null,
                'witness_02_nic' => $data['witness_02_nic'] ?? null,
                'witness_02_address' => $data['witness_02_address'] ?? null,
                'created_by' => Auth::id(),
                'bank_name' => $data['bank_name'] ?? ($bankDetail->bank_name ?? null),
                'branch_name' => $data['branch_name'] ?? ($bankDetail->branch_name ?? null),
                'account_number' => $data['account_number'] ?? ($bankDetail->account_number ?? null),
                'beneficiary_full_name' => $data['beneficiary_full_name'] ?? ($beneficiary->full_name ?? null),
                'beneficiary_id_type' => $data['beneficiary_id_type'] ?? ($beneficiary->id_type ?? 'nic'),
                'beneficiary_id_number' => $data['beneficiary_id_number'] ?? ($beneficiary->id_number ?? null),
                'beneficiary_phone_primary' => $data['beneficiary_phone_primary'] ?? ($beneficiary->phone_primary ?? null),
                'beneficiary_relationship' => $data['beneficiary_relationship'] ?? ($beneficiary->relationship ?? null),
                'beneficiary_share_percentage' => $data['beneficiary_share_percentage'] ?? ($beneficiary->share_percentage ?? null),

                'monthly_return' => round($calculations['monthly_return'] ?? 0, 2),
                'annual_return' => round($calculations['annual_return'] ?? 0, 2),
                'maturity_amount' => round($calculations['maturity_amount'] ?? 0, 2),
                'month_6_breakdown' => round($calculations['month_6_breakdown'] ?? 0, 2),
                'year_1_breakdown' => round($calculations['year_1_breakdown'] ?? 0, 2),
                'year_2_breakdown' => round($calculations['year_2_breakdown'] ?? 0, 2),
                'year_3_breakdown' => round($calculations['year_3_breakdown'] ?? 0, 2),
                'year_4_breakdown' => round($calculations['year_4_breakdown'] ?? 0, 2),
                'year_5_breakdown' => round($calculations['year_5_breakdown'] ?? 0, 2),
                'yearly_breakdown' => $calculations['yearly_breakdown'] ?? null,
            ]);

            DB::commit();

            $this->logActivity('Create', 'Legal', 'Legal created', [
                'user_id' => Auth::id(),
                'legal_id' => $legal->id,
                'legal_number' => $legal->legal_number,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Legal agreement created successfully',
                'data' => $this->transformLegal($legal),
            ], 201);

        } catch (\Throwable $th) {
            DB::rollBack();
            $this->logActivity('Error', 'Legal', 'Failed to store legal', [
                'error' => $th->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process legal agreement',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $legal = Legal::with([
                'investment.creator',
                'investment.investmentProduct',
                'investment.bankDetail',
                'investment.beneficiary',
                'investment.branch',
                'investment.customer',
                'investment.unitHead',
            ])->find($id);

            if (! $legal) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Legal not found',
                ], 404);
            }

            if (! $legal->investment) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Associated investment not found',
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Legal retrieved successfully',
                'data' => $this->transformLegal($legal),
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve legal',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function update(UpdateLegalRequest $request, string $id)
    {
        try {
            $legal = Legal::query()->find($id);

            if (! $legal) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Legal not found',
                ], 404);
            }

            $data = $request->validated();
            if ($legal->language === 'english') {
                unset(
                    $data['full_name'],
                    $data['name_with_initials'],
                    $data['address_line_1'],
                    $data['address_line_2'],
                    $data['city'],
                    $data['state'],
                    $data['country'],
                    $data['business_entered_date'],
                    $data['completed_date'],
                    $data['execution_year'],
                    $data['month_6_breakdown_in_words'],
                    $data['year_1_breakdown_in_words'],
                    $data['year_2_breakdown_in_words'],
                    $data['year_3_breakdown_in_words'],
                    $data['year_4_breakdown_in_words'],
                    $data['year_5_breakdown_in_words']
                );
            }
            if ($legal->language === 'sinhala') {
                unset(
                    $data['amount_in_words'],
                    $data['plan'],
                    $data['monthly_profit'],
                    $data['monthly_profit_day']
                );
            }
            $legal->update($data);

            $this->logActivity('Update', 'Legal', 'Legal updated', [
                'user_id' => Auth::id(),
                'legal_id' => $legal->id,
                'updated_fields' => array_keys($data),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Legal agreement updated successfully',
                'data' => $this->transformLegal($legal),
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update legal',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $legal = Legal::query()->find($id);

            if (! $legal) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Legal not found',
                ], 404);
            }

            $legal->delete($id);

            $this->logActivity('Delete', 'Legal', 'Legal deleted', [
                'user_id' => Auth::id(),
                'legal_id' => $id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Legal agreement deleted successfully',
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete legal',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    private function transformInvestment(Investment $investment)
    {
        $investment->loadMissing([
            'creator' => function ($q) {
                $q->select('id', 'name', 'email', 'is_active');
            },
            'investmentProduct' => function ($q) {
                $q->select('id', 'name', 'code', 'duration_months', 'roi_percentage', 'is_variable_roi')->with('annualRates');
            },
            'bankDetail' => function ($q) {
                $q->select('id', 'bank_name', 'branch_name', 'account_number');
            },
            'beneficiary' => function ($q) {
                $q->select('id', 'full_name', 'relationship', 'share_percentage', 'phone_primary', 'type', 'id_type', 'id_number');
            },
            'branch' => function ($q) {
                $q->select('id', 'name', 'code');
            },
            'customer',
            'unitHead' => function ($q) {
                $q->select('id', 'name', 'email', 'employee_code', 'is_active');
            },
        ]);

        $calculations = [];
        if ($investment->investmentProduct) {
            $calculations = $this->calculateInvestmentROI((float) $investment->investment_amount,
                $investment->investmentProduct
            );
        }

        // Append calculated agent & breakdown values dynamically
        $investment->agent_name = $investment->creator->name ?? 'N/A';
        $investment->monthly_return = round($calculations['monthly_return'] ?? 0, 2);
        $investment->annual_return = round($calculations['annual_return'] ?? 0, 2);
        $investment->maturity_amount = round($calculations['maturity_amount'] ?? 0, 2);
        $investment->month_6_breakdown = round($calculations['month_6_breakdown'] ?? 0, 2);
        $investment->year_1_breakdown = round($calculations['year_1_breakdown'] ?? 0, 2);
        $investment->year_2_breakdown = round($calculations['year_2_breakdown'] ?? 0, 2);
        $investment->year_3_breakdown = round($calculations['year_3_breakdown'] ?? 0, 2);
        $investment->year_4_breakdown = round($calculations['year_4_breakdown'] ?? 0, 2);
        $investment->year_5_breakdown = round($calculations['year_5_breakdown'] ?? 0, 2);
        $investment->yearly_breakdown = $calculations['yearly_breakdown'] ?? [];

        // Clean up investment product serialization output as requested
        if ($investment->investmentProduct) {
            $investment->investmentProduct->makeHidden(['is_variable_roi', 'created_at', 'updated_at', 'annualRates']);
        }

        return $investment;
    }

    public function invesmentIndex(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = Investment::with([
                'customer',
                'branch' => function ($q) {
                    $q->select('id', 'name', 'code');
                },
                'beneficiary' => function ($q) {
                    $q->select('id', 'full_name', 'relationship', 'share_percentage', 'phone_primary', 'type', 'id_type', 'id_number');
                },
                'bankDetail' => function ($q) {
                    $q->select('id', 'bank_name', 'branch_name', 'account_number');
                },
                'investmentProduct' => function ($q) {
                    $q->select('id', 'name', 'code', 'duration_months', 'roi_percentage', 'is_variable_roi')->with('annualRates');
                },
                'unitHead' => function ($q) {
                    $q->select('id', 'name', 'email', 'employee_code', 'is_active');
                },
                'creator' => function ($q) {
                    $q->select('id', 'name', 'email', 'is_active');
                },
            ]);

            // Filter by status if provided
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filter by branch
            if ($request->has('branch_id')) {
                $query->where('branch_id', $request->branch_id);
            }

            // Filter by customer_id
            if ($request->has('customer_id')) {
                $query->where('customer_id', $request->customer_id);
            }

            // Filter by investment_product_id
            if ($request->has('investment_product_id')) {
                $query->where('investment_product_id', $request->investment_product_id);
            }

            // Filter by business_type
            if ($request->has('business_type')) {
                $query->where('business_type', $request->business_type);
            }

            // Filter by payment_type
            if ($request->has('payment_type')) {
                $query->where('payment_type', $request->payment_type);
            }

            // Date filters
            if ($request->has('start_date')) {
                $query->whereDate('created_at', '>=', $request->start_date);
            }

            if ($request->has('end_date')) {
                $query->whereDate('created_at', '<=', $request->end_date);
            }

            if ($request->boolean('today', false)) {
                $query->whereDate('created_at', Carbon::today());
            }

            // Add search filtering
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

            $investments = $query->orderBy('created_at', 'desc')->paginate($perPage);

            // Clean up serialized output columns and calculate breakdown details
            $investments->getCollection()->transform(function ($inv) {
                return $this->transformInvestment($inv);
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Investments retrieved successfully',
                'data' => $investments,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve investments',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function invesmentShow(string $id)
    {
        try {
            $investment = Investment::find($id);

            if (! $investment) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Investment not found',
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Investment retrieved successfully',
                'data' => $this->transformInvestment($investment),
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve investment',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}
