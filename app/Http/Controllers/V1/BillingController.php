<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Billing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Carbon\Carbon;

class BillingController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Billing Index', only: ['index', 'show']),
            new Middleware('permission:Billing Update Status', only: ['updateStatus']),
        ];
    }

    /**
     * Display a listing of billing records.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $user = Auth::guard('api')->user();

            $query = Billing::with([
                'customer',
                'branch',
                'investment.investmentProduct',
                'investment.creator'
            ]);

            // Hierarchy Visibility Logic
            if ($user->hasRole('Branch Coordinator')) {
                $assignedBranchIds = $user->assignedBranches()->pluck('branches.id')->toArray();
                $query->whereIn('branch_id', $assignedBranchIds, 'and', false);
            } elseif (!$user->hasRole('Super Admin') && ($user->user_type !== 'admin')) {
                $descendantIds = $user->getAllDescendantIds();
                $accessibleUserIds = array_merge([$user->id], $descendantIds);
                $query->whereHas('investment', function ($q) use ($accessibleUserIds) {
                    $q->whereIn('created_by', $accessibleUserIds, 'and', false);
                });
            }

            // Branch Filter
            if ($request->filled('branch_id')) {
                if ($user->hasRole('Branch Coordinator')) {
                    $assignedBranchIds = $user->assignedBranches()->pluck('branches.id')->toArray();
                    if (!in_array($request->branch_id, $assignedBranchIds)) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Unauthorized access to this branch data.'
                        ], 403);
                    }
                }
                $query->where('branch_id', $request->branch_id);
            }

            // Date Filters
            if ($request->filled('from_date') && $request->filled('to_date')) {
                $query->whereBetween('created_at', [$request->from_date . ' 00:00:00', $request->to_date . ' 23:59:59']);
            } elseif ($request->filled('from_date')) {
                $query->whereDate('created_at', '>=', $request->from_date);
            } elseif ($request->filled('to_date')) {
                $query->whereDate('created_at', '<=', $request->to_date);
            } else {
                // Default to current date if no dates are specified
                $query->whereDate('created_at', Carbon::today());
            }

            // Specific Filters: customer_name, policy_number
            if ($request->filled('customer_name')) {
                $query->whereHas('customer', function ($cq) use ($request) {
                    $cq->where('full_name', 'like', "%{$request->customer_name}%");
                });
            }

            if ($request->filled('policy_number')) {
                $query->whereHas('investment', function ($iq) use ($request) {
                    $iq->where('policy_number', 'like', "%{$request->policy_number}%");
                });
            }

            // General Search Filter
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('billing_number', 'like', "%{$search}%")
                        ->orWhereHas('investment', function ($iq) use ($search) {
                            $iq->where('policy_number', 'like', "%{$search}%")
                               ->orWhere('application_number', 'like', "%{$search}%");
                        })
                        ->orWhereHas('customer', function ($cq) use ($search) {
                            $cq->where('full_name', 'like', "%{$search}%");
                        });
                });
            }

            // Calculate stats based on the filtered query (before ordering and paginating)
            $statsQuery = clone $query;
            $totalAmountPending = (float) (clone $statsQuery)->where('status', '=', 'pending', 'and')->sum('investment_amount');
            $totalAmountReceived = (float) (clone $statsQuery)->where('status', '=', 'received', 'and')->sum('investment_amount');

            $billings = $query->orderBy('created_at', 'desc')->paginate($perPage);

            // Clean response data to show only important fields
            $billings->getCollection()->transform(function ($billing) {
                return [
                    'id' => $billing->id,
                    'billing_number' => $billing->billing_number,
                    'investment_amount' => (float)$billing->investment_amount,
                    'status' => $billing->status,
                    'status_updated_at' => $billing->status_updated_at ? $billing->status_updated_at->format('Y-m-d H:i:s') : null,
                    'created_at' => $billing->created_at ? $billing->created_at->format('Y-m-d H:i:s') : null,
                    'customer' => [
                        'id' => $billing->customer->id ?? null,
                        'full_name' => $billing->customer->full_name ?? 'N/A',
                        'customer_code' => $billing->customer->customer_code ?? 'N/A',
                        'phone' => $billing->customer->phone_primary ?? 'N/A',
                    ],
                    'branch' => [
                        'id' => $billing->branch->id ?? null,
                        'name' => $billing->branch->name ?? 'N/A',
                        'code' => $billing->branch->code ?? 'N/A',
                    ],
                    'investment' => [
                        'id' => $billing->investment->id ?? null,
                        'policy_number' => $billing->investment->policy_number ?? 'N/A',
                        'application_number' => $billing->investment->application_number ?? 'N/A',
                        'agent_name' => $billing->investment->creator->name ?? 'N/A',
                    ],
                    'plan' => [
                        'id' => $billing->investmentProduct->id ?? null,
                        'name' => $billing->investmentProduct->name ?? 'N/A',
                        'duration_months' => $billing->investmentProduct->duration_months ?? 0,
                    ]
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Billings retrieved successfully',
                'stats' => [
                    'total_amount_pending' => round($totalAmountPending, 2),
                    'total_amount_received' => round($totalAmountReceived, 2),
                ],
                'data' => $billings
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve billing records',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified billing record.
     */
    public function show($id)
    {
        try {
            $user = Auth::guard('api')->user();

            $query = Billing::with([
                'customer',
                'branch',
                'investment.investmentProduct',
                'investment.creator'
            ]);

            // Hierarchy Visibility Logic
            if ($user->hasRole('Branch Coordinator')) {
                $assignedBranchIds = $user->assignedBranches()->pluck('branches.id')->toArray();
                $query->whereIn('branch_id', $assignedBranchIds, 'and', false);
            } elseif (!$user->hasRole('Super Admin') && ($user->user_type !== 'admin')) {
                $descendantIds = $user->getAllDescendantIds();
                $accessibleUserIds = array_merge([$user->id], $descendantIds);
                $query->whereHas('investment', function ($q) use ($accessibleUserIds) {
                    $q->whereIn('created_by', $accessibleUserIds, 'and', false);
                });
            }

            $billing = $query->find($id);

            if (!$billing) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Billing record not found or unauthorized.'
                ], 404);
            }

            $formattedBilling = [
                'id' => $billing->id,
                'billing_number' => $billing->billing_number,
                'investment_amount' => (float)$billing->investment_amount,
                'status' => $billing->status,
                'status_updated_at' => $billing->status_updated_at ? $billing->status_updated_at->format('Y-m-d H:i:s') : null,
                'created_at' => $billing->created_at ? $billing->created_at->format('Y-m-d H:i:s') : null,
                'customer' => [
                    'id' => $billing->customer->id ?? null,
                    'full_name' => $billing->customer->full_name ?? 'N/A',
                    'customer_code' => $billing->customer->customer_code ?? 'N/A',
                    'phone' => $billing->customer->phone_primary ?? 'N/A',
                ],
                'branch' => [
                    'id' => $billing->branch->id ?? null,
                    'name' => $billing->branch->name ?? 'N/A',
                    'code' => $billing->branch->code ?? 'N/A',
                ],
                'investment' => [
                    'id' => $billing->investment->id ?? null,
                    'policy_number' => $billing->investment->policy_number ?? 'N/A',
                    'application_number' => $billing->investment->application_number ?? 'N/A',
                    'agent_name' => $billing->investment->creator->name ?? 'N/A',
                ],
                'plan' => [
                    'id' => $billing->investmentProduct->id ?? null,
                    'name' => $billing->investmentProduct->name ?? 'N/A',
                    'duration_months' => $billing->investmentProduct->duration_months ?? 0,
                ]
            ];

            return response()->json([
                'status' => 'success',
                'message' => 'Billing details retrieved successfully',
                'data' => $formattedBilling
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve billing details',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function updateStatus($id)
    {

        try {
            $user = Auth::guard('api')->user();

            // Find billing with hierarchy enforcement
            $query = Billing::query();
            if ($user->hasRole('Branch Coordinator')) {
                $assignedBranchIds = $user->assignedBranches()->pluck('branches.id')->toArray();
                $query->whereIn('branch_id', $assignedBranchIds, 'and', false);
            } elseif (!$user->hasRole('Super Admin') && ($user->user_type !== 'admin')) {
                $descendantIds = $user->getAllDescendantIds();
                $accessibleUserIds = array_merge([$user->id], $descendantIds);
                $query->whereHas('investment', function ($q) use ($accessibleUserIds) {
                    $q->whereIn('created_by', $accessibleUserIds, 'and', false);
                });
            }

            $billing = $query->findOrFail($id);

            $billing->update([
                'status' => 'received',
                'status_updated_at' => now(),
            ]);

            Log::info('Billing status updated', [
                'billing_id' => $billing->id,
                'billing_number' => $billing->billing_number,
                'status' => $billing->status,
                'updated_by' => $user->id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Billing status updated successfully',
                'data' => $billing
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update billing status',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
