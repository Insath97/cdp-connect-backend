<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateReceiptRequest;
use App\Models\Investment;
use App\Models\Receipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ReceiptController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Receipt Index', only: ['show', 'indexByInvestment']),
            new Middleware('permission:Receipt Create', only: ['store']),
        ];
    }
    /**
     * Store a newly created receipt in storage.
     */
    public function store(CreateReceiptRequest $request)
    {
        DB::beginTransaction();
        try {
            $user = Auth::guard('api')->user();
            $data = $request->validated();

            $investment = Investment::findOrFail($data['investment_id']);

            // Permission Check: Ensure user can access this investment
            if (!$user->hasRole('Super Admin') && ($user->user_type !== 'admin')) {
                $descendantIds = $user->getAllDescendantIds();
                $accessibleUserIds = array_merge([$user->id], $descendantIds);

                if (!in_array($investment->created_by, $accessibleUserIds)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'You do not have permission to print a receipt for this investment.'
                    ], 403);
                }
            }

            // Status Check: Only approved investments can have receipts
            if ($investment->status !== 'approved') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Receipts can only be generated for approved investments.'
                ], 422);
            }

            // Create the receipt
            $receipt = Receipt::create([
                'investment_id' => $investment->id,
                'printed_by' => $user->id,
                'amount' => $data['amount'] ?? $investment->investment_amount,
            ]);

            DB::commit();

            Log::info('Receipt created', [
                'user_id' => $user->id,
                'receipt_id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number
            ]);

            $receipt->load(['investment.customer', 'investment.investmentProduct', 'investment.approver', 'printedBy']);
            $receipt->investment->makeHidden(['created_at', 'updated_at', 'deleted_at', 'created_by', 'unit_head_id', 'checked_by', 'checked_at']);

            return response()->json([
                'status' => 'success',
                'message' => 'Receipt generated successfully',
                'data' => $receipt
            ], 201);

        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Receipt creation failed', [
                'error' => $th->getMessage(),
                'user_id' => Auth::guard('api')->id()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate receipt',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified receipt.
     */
    public function show(string $id)
    {
        try {
            $receipt = Receipt::with([
                'investment.customer:id,full_name,name_with_initials,customer_code',
                'investment.branch:id,name,code',
                'investment.investmentProduct:id,name,code,duration_months,roi_percentage',
                'investment.approver:id,name',
                'printedBy:id,name'
            ])->find($id);

            if (!$receipt) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Receipt not found'
                ], 404);
            }

            // Hide unnecessary internal fields
            $receipt->investment->makeHidden([
                'created_at',
                'updated_at',
                'deleted_at',
                'created_by',
                'unit_head_id',
                'checked_by',
                'checked_at',
                'customer_id',
                'branch_id',
                'investment_product_id',
                'beneficiary_id',
                'customer_bank_detail_id'
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Receipt details retrieved successfully',
                'data' => $receipt
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve receipt details',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of receipts for a specific investment.
     */
    public function indexByInvestment(string $investmentId)
    {
        try {
            $receipts = Receipt::where('investment_id', $investmentId)
                ->with(['investment.customer', 'investment.branch', 'investment.approver', 'printedBy'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Receipts retrieved successfully',
                'data' => $receipts
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve receipts',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
