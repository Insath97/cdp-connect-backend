<?php

namespace App\Http\Controllers\V1;

use App\Traits\ActivityLogTrait;

use App\Http\Controllers\Controller;
use App\Models\Investment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Carbon\Carbon;

class WelcomeCallController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Welcome Call Index', only: ['index', 'show']),
            new Middleware('permission:Welcome Call Update', only: ['updateStatus']),
        ];
    }

    /**
     * Display a listing of approved investments for welcome calls.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $user = Auth::guard('api')->user();

            $query = Investment::with(['customer', 'branch', 'investmentProduct', 'welcomeCallUser', 'bankDetail', 'beneficiary', 'unitHead'])
                ->where('status', 'approved');

            // Hierarchy Visibility Logic (consistent with InvestmentController)
            if ($user->hasRole('Branch Coordinator')) {
                $assignedBranchIds = $user->assignedBranches()->pluck('branches.id')->toArray();
                $query->whereIn('branch_id', $assignedBranchIds);
            } elseif (!$user->hasRole('Super Admin') && ($user->user_type !== 'admin')) {
                $descendantIds = $user->getAllDescendantIds();
                $accessibleUserIds = array_merge([$user->id], $descendantIds);
                $query->whereIn('created_by', $accessibleUserIds);
            }

            // Filters
            if ($request->has('welcome_call_status')) {
                $query->where('welcome_call_status', $request->welcome_call_status);
            }

            // Date Range Filters
            if ($request->filled('from_date') && $request->filled('end_date')) {
                $query->whereBetween('approved_at', [$request->from_date, $request->end_date]);
            } elseif ($request->filled('from_date')) {
                $query->whereDate('approved_at', '>=', $request->from_date);
            } elseif ($request->filled('end_date')) {
                $query->whereDate('approved_at', '<=', $request->end_date);
            } elseif (!$request->has('welcome_call_status')) {
                // Default to current date approved investments only if welcome_call_status is not filterable
                $query->whereDate('approved_at', Carbon::today());
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('policy_number', 'like', "%{$search}%")
                        ->orWhere('application_number', 'like', "%{$search}%")
                        ->orWhereHas('customer', function ($cq) use ($search) {
                            $cq->where('full_name', 'like', "%{$search}%");
                        });
                });
            }

            $investments = $query->orderBy('approved_at', 'desc')->paginate($perPage);

            // Clean response data
            $investments->getCollection()->transform(function ($inv) {
                return [
                    'id' => $inv->id,
                    'policy_number' => $inv->policy_number,
                    'application_number' => $inv->application_number,
                    'investment_amount' => (float)$inv->investment_amount,
                    'approved_at' => $inv->approved_at ? $inv->approved_at->format('Y-m-d') : null,
                    'welcome_call_status' => $inv->welcome_call_status,
                    'welcome_call_at' => $inv->welcome_call_at ? $inv->welcome_call_at->format('Y-m-d H:i:s') : null,
                    'welcome_call_notes' => $inv->welcome_call_notes,
                    'customer' => [
                        'id' => $inv->customer->id ?? null,
                        'full_name' => $inv->customer->full_name ?? 'N/A',
                        'customer_code' => $inv->customer->customer_code ?? 'N/A',
                        'phone' => $inv->customer->phone_primary ?? 'N/A',
                    ],
                    'branch' => [
                        'id' => $inv->branch->id ?? null,
                        'name' => $inv->branch->name ?? 'N/A',
                        'code' => $inv->branch->code ?? 'N/A',
                    ],
                    'plan' => [
                        'id' => $inv->investmentProduct->id ?? null,
                        'name' => $inv->investmentProduct->name ?? 'N/A',
                        'duration_months' => $inv->investmentProduct->duration_months ?? 0,
                    ],
                    'bank_detail' => [
                        'id' => $inv->bankDetail->id ?? null,
                        'bank_name' => $inv->bankDetail->bank_name ?? 'N/A',
                        'branch_name' => $inv->bankDetail->branch_name ?? 'N/A',
                        'account_number' => $inv->bankDetail->account_number ?? 'N/A',
                        'payment_method' => $inv->bankDetail->payment_method ?? 'N/A',
                    ],
                    'beneficiary' => [
                        'id' => $inv->beneficiary->id ?? null,
                        'full_name' => $inv->beneficiary->full_name ?? 'N/A',
                        'type' => $inv->beneficiary->type ?? 'N/A',
                        'id_type' => $inv->beneficiary->id_type ?? 'N/A',
                        'id_number' => $inv->beneficiary->id_number ?? 'N/A',
                        'phone_primary' => $inv->beneficiary->phone_primary ?? 'N/A',
                        'relationship' => $inv->beneficiary->relationship ?? 'N/A',
                        'share_percentage' => $inv->beneficiary->share_percentage ? (float)$inv->beneficiary->share_percentage : 0,
                        'id_image' => $inv->beneficiary->id_image ?? null,
                        'child_file' => $inv->beneficiary->child_file ?? null,
                    ],
                    'agent_name' => $inv->unitHead->name ?? 'N/A',
                    'agent_employee_code' => $inv->unitHead->employee_code ?? 'N/A',
                    'welcome_call_by' => $inv->welcomeCallUser->name ?? 'N/A',
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Approved investments retrieved successfully',
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
     * Display the specified investment details for welcome call.
     */
    public function show($id)
    {
        try {
            $investment = Investment::with(['customer', 'branch', 'investmentProduct', 'welcomeCallUser', 'bankDetail', 'beneficiary', 'unitHead'])
                ->where('status', 'approved')
                ->find($id);

            if (!$investment) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Approved investment not found'
                ], 404);
            }

            $data = [
                'id' => $investment->id,
                'policy_number' => $investment->policy_number,
                'application_number' => $investment->application_number,
                'investment_amount' => (float)$investment->investment_amount,
                'approved_at' => $investment->approved_at ? $investment->approved_at->format('Y-m-d') : null,
                'welcome_call_status' => $investment->welcome_call_status,
                'welcome_call_at' => $investment->welcome_call_at ? $investment->welcome_call_at->format('Y-m-d H:i:s') : null,
                'welcome_call_notes' => $investment->welcome_call_notes,
                'customer' => [
                    'id' => $investment->customer->id ?? null,
                    'full_name' => $investment->customer->full_name ?? 'N/A',
                    'customer_code' => $investment->customer->customer_code ?? 'N/A',
                    'phone' => $investment->customer->phone_primary ?? 'N/A',
                    'address' => $investment->customer->address_line_1 ?? 'N/A',
                ],
                'branch' => [
                    'id' => $investment->branch->id ?? null,
                    'name' => $investment->branch->name ?? 'N/A',
                    'code' => $investment->branch->code ?? 'N/A',
                ],
                'plan' => [
                    'id' => $investment->investmentProduct->id ?? null,
                    'name' => $investment->investmentProduct->name ?? 'N/A',
                    'duration_months' => $investment->investmentProduct->duration_months ?? 0,
                    'roi_percentage' => $investment->investmentProduct->roi_percentage ?? 0,
                ],
                'bank_detail' => [
                    'id' => $investment->bankDetail->id ?? null,
                    'bank_name' => $investment->bankDetail->bank_name ?? 'N/A',
                    'branch_name' => $investment->bankDetail->branch_name ?? 'N/A',
                    'account_number' => $investment->bankDetail->account_number ?? 'N/A',
                    'payment_method' => $investment->bankDetail->payment_method ?? 'N/A',
                ],
                'beneficiary' => [
                    'id' => $investment->beneficiary->id ?? null,
                    'full_name' => $investment->beneficiary->full_name ?? 'N/A',
                    'type' => $investment->beneficiary->type ?? 'N/A',
                    'id_type' => $investment->beneficiary->id_type ?? 'N/A',
                    'id_number' => $investment->beneficiary->id_number ?? 'N/A',
                    'phone_primary' => $investment->beneficiary->phone_primary ?? 'N/A',
                    'relationship' => $investment->beneficiary->relationship ?? 'N/A',
                    'share_percentage' => $investment->beneficiary->share_percentage ? (float)$investment->beneficiary->share_percentage : 0,
                    'id_image' => $investment->beneficiary->id_image ?? null,
                    'child_file' => $investment->beneficiary->child_file ?? null,
                ],
                'agent_name' => $investment->unitHead->name ?? 'N/A',
                'agent_employee_code' => $investment->unitHead->employee_code ?? 'N/A',
                'welcome_call_by' => $investment->welcomeCallUser->name ?? 'N/A',
            ];

            return response()->json([
                'status' => 'success',
                'message' => 'Investment details retrieved successfully',
                'data' => $data
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
     * Update the welcome call status of an investment.
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,completed,not_reachable,no_answer,others',
            'notes' => 'nullable|string'
        ]);

        try {
            $investment = Investment::query()->where('status', 'approved')->findOrFail($id);

            // Prevent changing status if it's already 'completed'
            if ($investment->welcome_call_status === 'completed' && $request->status !== 'completed') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Completed welcome calls cannot be changed to another status'
                ], 422);
            }

            $user = Auth::guard('api')->user();

            $investment->update([
                'welcome_call_status' => $request->status,
                'welcome_call_notes' => $request->notes,
                'welcome_call_by' => $user->id,
                'welcome_call_at' => now(),
            ]);

            $this->logActivity('Update', 'WelcomeCall', 'Welcome call status updated', [
                'investment_id' => $investment->id,
                'status' => $request->status,
                'updated_by' => $user->id
            ]);

            $investment->load('welcomeCallUser');

            $data = [
                'id' => $investment->id,
                'welcome_call_status' => $investment->welcome_call_status,
                'welcome_call_at' => $investment->welcome_call_at ? $investment->welcome_call_at->format('Y-m-d H:i:s') : null,
                'welcome_call_notes' => $investment->welcome_call_notes,
                'welcome_call_by' => $investment->welcomeCallUser->name ?? 'N/A',
            ];

            return response()->json([
                'status' => 'success',
                'message' => 'Welcome call status updated successfully',
                'data' => $data
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update welcome call status',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
