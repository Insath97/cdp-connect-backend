<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\ExpiredInvestment;
use App\Models\Investment;
use App\Services\SmsService;
use App\Traits\ActivityLogTrait;
use App\Traits\FileUploadTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ExpiredBusinessController extends Controller implements HasMiddleware
{
    use ActivityLogTrait, FileUploadTrait;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Expired Business Index|Expired Investment Index', only: ['index', 'show']),
            new Middleware('permission:Expired Business Update|Expired Investment Update', only: ['updatePayment', 'syncExpiredBusiness']),
        ];
    }

    /**
     * Display a listing of expired business (expired investments) along with complete related data.
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::guard('api')->user();
            $perPage = (int) $request->get('per_page', 15);

            // 1. Auto-sync any investments with status = 'expired' missing a settlement record
            $this->ensureExpiredInvestmentsSynchronized();

            // 2. Base Query with along data
            $query = ExpiredInvestment::with([
                'investment' => function ($q) {
                    $q->select(
                        'id',
                        'policy_number',
                        'application_number',
                        'sales_code',
                        'reservation_date',
                        'investment_amount',
                        'business_type',
                        'payment_type',
                        'status',
                        'customer_id',
                        'branch_id',
                        'investment_product_id',
                        'beneficiary_id',
                        'customer_bank_detail_id',
                        'created_by',
                        'unit_head_id'
                    );
                },
                'investment.customer' => function ($q) {
                    $q->select('id', 'full_name', 'customer_code', 'id_number', 'phone_primary', 'phone_secondary')
                        ->with(['bankDetails' => function ($bq) {
                            $bq->select('id', 'customer_id', 'bank_name', 'branch_name', 'account_number');
                        }]);
                },
                'investment.branch' => function ($q) {
                    $q->select('id', 'name', 'code');
                },
                'investment.investmentProduct' => function ($q) {
                    $q->select('id', 'name', 'code', 'duration_months', 'roi_percentage');
                },
                'investment.beneficiary' => function ($q) {
                    $q->select('id', 'full_name', 'relationship', 'phone_primary');
                },
                'investment.bankDetail' => function ($q) {
                    $q->select('id', 'customer_id', 'bank_name', 'branch_name', 'account_number');
                },
                'investment.creator' => function ($q) {
                    $q->select('id', 'name', 'email');
                },
                'investment.unitHead' => function ($q) {
                    $q->select('id', 'name', 'email');
                },
                'branch' => function ($q) {
                    $q->select('id', 'name', 'code');
                },
                'paidBy' => function ($q) {
                    $q->select('id', 'name', 'email');
                },
            ]);

            // Only consider records whose investment is expired
            $query->whereHas('investment', function ($q) {
                $q->where('status', 'expired');
            });

            // 3. Hierarchy Visibility Logic
            if ($user->hasRole('Branch Coordinator')) {
                $assignedBranchIds = $user->assignedBranches()->pluck('branches.id')->toArray();
                $query->whereIn('branch_id', $assignedBranchIds);
            } elseif (! $user->hasRole('Super Admin') && ($user->user_type !== 'admin')) {
                $descendantIds = $user->getAllDescendantIds();
                $accessibleUserIds = array_merge([$user->id], $descendantIds);
                $query->whereHas('investment', function ($q) use ($accessibleUserIds) {
                    $q->whereIn('created_by', $accessibleUserIds);
                });
            }

            // 4. Branch Filter
            if ($request->filled('branch_id')) {
                if ($user->hasRole('Branch Coordinator')) {
                    $assignedBranchIds = $user->assignedBranches()->pluck('branches.id')->toArray();
                    if (! in_array($request->branch_id, $assignedBranchIds)) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Unauthorized access to this branch data.',
                        ], 403);
                    }
                }
                $query->where('branch_id', $request->branch_id);
            }

            // 5. Settlement Status Filter (paid/unpaid)
            $statusFilter = $request->get('settlement_status', $request->get('status'));
            if ($statusFilter && in_array($statusFilter, ['paid', 'unpaid'])) {
                $query->where('status', $statusFilter);
            }

            // 6. Date Range Filters
            if ($request->filled('from_date') && $request->filled('to_date')) {
                $query->whereBetween('created_at', [$request->from_date, $request->to_date]);
            } elseif ($request->filled('from_date')) {
                $query->whereDate('created_at', '>=', $request->from_date);
            } elseif ($request->filled('to_date')) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }

            // 7. Search Filter
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('transaction_number', 'like', "%{$search}%")
                        ->orWhereHas('investment', function ($iq) use ($search) {
                            $iq->where('policy_number', 'like', "%{$search}%")
                                ->orWhere('application_number', 'like', "%{$search}%")
                                ->orWhere('sales_code', 'like', "%{$search}%")
                                ->orWhereHas('customer', function ($cq) use ($search) {
                                    $cq->where('full_name', 'like', "%{$search}%")
                                        ->orWhere('customer_code', 'like', "%{$search}%")
                                        ->orWhere('id_number', 'like', "%{$search}%");
                                });
                        });
                });
            }

            // 8. Calculate Summary Statistics before pagination
            $statsQuery = clone $query;
            $stats = [
                'total_expired_count' => (clone $statsQuery)->count(),
                'total_unpaid_count' => (clone $statsQuery)->where('status', 'unpaid')->count(),
                'total_paid_count' => (clone $statsQuery)->where('status', 'paid')->count(),
                'total_expired_amount' => round((float) (clone $statsQuery)->sum('investment_amount'), 2),
                'total_unpaid_amount' => round((float) (clone $statsQuery)->where('status', 'unpaid')->sum('investment_amount'), 2),
                'total_paid_amount' => round((float) (clone $statsQuery)->where('status', 'paid')->sum('investment_amount'), 2),
            ];

            // 9. Execute Pagination
            $expiredInvestments = $query->orderBy('created_at', 'desc')->paginate($perPage);

            // 10. Transform Collection for fallback bank details and image URLs
            $expiredInvestments->getCollection()->transform(function ($item) {
                $investment = $item->investment;
                if ($investment) {
                    if (! $investment->bankDetail && $investment->customer && $investment->customer->bankDetails->isNotEmpty()) {
                        $investment->setRelation('bankDetail', $investment->customer->bankDetails->first());
                    }
                    if ($investment->customer) {
                        $investment->customer->unsetRelation('bankDetails');
                    }
                }
                if ($item->image) {
                    $item->image_url = asset($item->image);
                } else {
                    $item->image_url = null;
                }
                return $item;
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Expired business records retrieved successfully',
                'stats' => $stats,
                'data' => $expiredInvestments,
            ], 200);

        } catch (\Throwable $th) {
            $this->logActivity('Error', 'ExpiredBusiness', 'Failed to retrieve expired business records', [
                'error' => $th->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve expired business records',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified expired business record.
     */
    public function show($id)
    {
        try {
            $user = Auth::guard('api')->user();

            $query = ExpiredInvestment::with([
                'investment.customer' => function ($q) {
                    $q->with('bankDetails');
                },
                'investment.branch',
                'investment.investmentProduct',
                'investment.beneficiary',
                'investment.bankDetail',
                'investment.creator',
                'investment.unitHead',
                'branch',
                'paidBy',
            ]);

            // Query by ExpiredInvestment id or Investment id
            $item = $query->where(function ($q) use ($id) {
                $q->where('id', $id)
                    ->orWhere('investment_id', $id);
            })->first();

            if (! $item) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Expired business record not found.',
                ], 404);
            }

            // Hierarchy verification
            if ($user->hasRole('Branch Coordinator')) {
                $assignedBranchIds = $user->assignedBranches()->pluck('branches.id')->toArray();
                if (! in_array($item->branch_id, $assignedBranchIds)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Unauthorized access to this branch data.',
                    ], 403);
                }
            } elseif (! $user->hasRole('Super Admin') && ($user->user_type !== 'admin')) {
                $descendantIds = $user->getAllDescendantIds();
                $accessibleUserIds = array_merge([$user->id], $descendantIds);
                if (! in_array($item->investment->created_by ?? null, $accessibleUserIds)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Unauthorized access to this investment data.',
                    ], 403);
                }
            }

            // Fallback bank details
            $investment = $item->investment;
            if ($investment) {
                if (! $investment->bankDetail && $investment->customer && $investment->customer->bankDetails->isNotEmpty()) {
                    $investment->setRelation('bankDetail', $investment->customer->bankDetails->first());
                }
            }

            if ($item->image) {
                $item->image_url = asset($item->image);
            } else {
                $item->image_url = null;
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Expired business details retrieved successfully',
                'data' => $item,
            ], 200);

        } catch (\Throwable $th) {
            $this->logActivity('Error', 'ExpiredBusiness', 'Failed to retrieve expired business details', [
                'id' => $id,
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve expired business details',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Process / Update the settlement payment for an expired business record.
     */
    public function updatePayment(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:paid,unpaid',
            'investment_amount' => 'nullable|numeric|min:0',
            'payment_method' => 'required_if:status,paid|nullable|string|max:100',
            'transaction_number' => 'required_if:status,paid|nullable|string|max:100',
            'remarks' => 'nullable|string|max:1000',
            'image' => 'nullable|file|mimes:jpg,jpeg,png,pdf,webp|max:10240',
            'paid_at' => 'nullable|date',
            'send_sms' => 'nullable|boolean',
        ]);

        try {
            $user = Auth::guard('api')->user();

            $item = ExpiredInvestment::where('id', $id)
                ->orWhere('investment_id', $id)
                ->firstOrFail();

            // Hierarchy verification
            if ($user->hasRole('Branch Coordinator')) {
                $assignedBranchIds = $user->assignedBranches()->pluck('branches.id')->toArray();
                if (! in_array($item->branch_id, $assignedBranchIds)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Unauthorized access to this branch data.',
                    ], 403);
                }
            } elseif (! $user->hasRole('Super Admin') && ($user->user_type !== 'admin')) {
                $descendantIds = $user->getAllDescendantIds();
                $accessibleUserIds = array_merge([$user->id], $descendantIds);
                if (! in_array($item->investment->created_by ?? null, $accessibleUserIds)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Unauthorized access to this investment data.',
                    ], 403);
                }
            }

            $updateData = [
                'status' => $request->status,
                'remarks' => $request->remarks ?? $item->remarks,
            ];

            if ($request->filled('investment_amount')) {
                $updateData['investment_amount'] = $request->investment_amount;
            }

            if ($request->status === 'paid') {
                $updateData['payment_method'] = $request->payment_method;
                $updateData['transaction_number'] = $request->transaction_number;
                $updateData['paid_at'] = $request->paid_at ? Carbon::parse($request->paid_at) : now();
                $updateData['paid_by'] = $user->id;
            } else {
                $updateData['paid_at'] = null;
                $updateData['paid_by'] = null;
                if ($request->has('payment_method')) {
                    $updateData['payment_method'] = $request->payment_method;
                }
                if ($request->has('transaction_number')) {
                    $updateData['transaction_number'] = $request->transaction_number;
                }
            }

            // Handle file upload
            if ($request->hasFile('image')) {
                $uploadedImagePath = $this->handleFileUpload(
                    $request,
                    'image',
                    $item->image,
                    'expired_investments',
                    'settlement_' . $item->id . '_' . time()
                );
                if ($uploadedImagePath) {
                    $updateData['image'] = $uploadedImagePath;
                }
            }

            $item->update($updateData);
            $item->load(['investment.customer', 'branch', 'paidBy']);

            // Send SMS notification if requested and status is paid
            if ($request->status === 'paid' && $request->boolean('send_sms')) {
                try {
                    $customer = $item->investment->customer ?? null;
                    if ($customer) {
                        $phone = $customer->phone_primary ?? $customer->phone_secondary;
                        if ($phone) {
                            $paidAmount = number_format((float) $item->investment_amount, 2);
                            $policyNumber = $item->investment->policy_number ?? 'N/A';
                            $method = $item->payment_method ? strtoupper(str_replace('_', ' ', $item->payment_method)) : 'Bank';

                            $smsMessage = "Dear {$customer->full_name},\n\n" .
                                "Policy No: {$policyNumber}\n" .
                                "Your matured investment settlement of LKR {$paidAmount} has been processed via {$method}.\n" .
                                "Ref: {$item->transaction_number}\n\n" .
                                "Thank you for choosing CDP Empire (Pvt) Ltd.\n" .
                                "Hotline: +94 114 007 007";

                            $smsService = app(SmsService::class);
                            $smsService->sendSms($phone, $smsMessage);

                            $this->logActivity('Info', 'ExpiredBusiness', 'SMS notification sent for expired investment payout', [
                                'expired_investment_id' => $item->id,
                                'phone' => $phone,
                            ]);
                        }
                    }
                } catch (\Throwable $smsEx) {
                    $this->logActivity('Warning', 'ExpiredBusiness', 'Failed to dispatch SMS notification for settlement: ' . $smsEx->getMessage(), [
                        'expired_investment_id' => $item->id,
                    ]);
                }
            }

            $this->logActivity('Update', 'ExpiredBusiness', 'Expired investment settlement updated successfully', [
                'expired_investment_id' => $item->id,
                'status' => $item->status,
                'amount' => $item->investment_amount,
                'updated_by' => $user->id,
            ]);

            if ($item->image) {
                $item->image_url = asset($item->image);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Expired business settlement updated successfully',
                'data' => $item,
            ], 200);

        } catch (\Throwable $th) {
            $this->logActivity('Error', 'ExpiredBusiness', 'Failed to update expired business settlement', [
                'id' => $id,
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update expired business settlement',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk sync all expired investments missing settlement records.
     */
    public function syncExpiredBusiness()
    {
        try {
            $count = $this->ensureExpiredInvestmentsSynchronized();

            return response()->json([
                'status' => 'success',
                'message' => "Successfully synchronized {$count} expired business record(s).",
                'synced_count' => $count,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to synchronize expired business records',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Helper to auto-create missing ExpiredInvestment records.
     */
    protected function ensureExpiredInvestmentsSynchronized(): int
    {
        $missingInvestments = Investment::where('status', 'expired')
            ->whereDoesntHave('expiredSettlement')
            ->get();

        if ($missingInvestments->isEmpty()) {
            return 0;
        }

        $records = [];
        $now = now();
        foreach ($missingInvestments as $inv) {
            $records[] = [
                'investment_id' => $inv->id,
                'customer_id' => $inv->customer_id,
                'branch_id' => $inv->branch_id,
                'investment_amount' => $inv->investment_amount,
                'status' => 'unpaid',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        ExpiredInvestment::insert($records);

        return count($records);
    }
}
