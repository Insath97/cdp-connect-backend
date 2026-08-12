<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Investment;
use App\Traits\ActivityLogTrait;
use App\Traits\InvestmentCalculationTrait;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Carbon\Carbon;

class RenewalController extends Controller implements HasMiddleware
{
    use ActivityLogTrait, InvestmentCalculationTrait;

    /**
     * Get the middleware that should be assigned to the controller.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Renewal Index', only: ['index']),
        ];
    }

    /**
     * Display a listing of renewal business investments.
     * Defaults to investments that expired before 10 days (or within specified day window).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->get('per_page', 15);
            $days = (int) $request->get('days', 10);
            $user = Auth::guard('api')->user();

            // 1. Build Query with required relations & join product to compute maturity date in SQL
            $query = Investment::with([
                'customer',
                'branch',
                'investmentProduct',
                'creator',
                'unitHead',
                'checker',
                'approver',
                'bankDetail'
            ])
            ->join('investment_products', 'investments.investment_product_id', '=', 'investment_products.id')
            ->select('investments.*');

            // 2. Hierarchy Data Scoping
            if ($user->hasRole('Branch Coordinator')) {
                $assignedBranchIds = $user->assignedBranches()->pluck('branches.id')->toArray();
                $query->whereIn('investments.branch_id', $assignedBranchIds);
            } elseif (!$user->hasRole('Super Admin') && ($user->user_type !== 'admin')) {
                $descendantIds = $user->getAllDescendantIds();
                $accessibleUserIds = array_merge([$user->id], $descendantIds);
                $query->whereIn('investments.created_by', $accessibleUserIds);
            }

            // 3. Branch Filter
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
                $query->where('investments.branch_id', '=', $request->branch_id);
            }

            // 4. Investment Product Filter
            if ($request->filled('investment_product_id')) {
                $query->where('investments.investment_product_id', '=', $request->investment_product_id);
            }

            // 5. Target Period Key Filter
            if ($request->filled('period_key')) {
                $query->where('investments.target_period_key', '=', $request->period_key);
            }

            // 6. Status Filter (Default to approved investments)
            $status = $request->get('status', 'approved');
            if ($status !== 'all') {
                $query->where('investments.status', '=', $status);
            }

            // 7. Search Filter (Policy, Application, Sales Code, Customer Name/Code/Phone/ID)
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('investments.policy_number', 'like', "%{$search}%")
                        ->orWhere('investments.application_number', 'like', "%{$search}%")
                        ->orWhere('investments.sales_code', 'like', "%{$search}%")
                        ->orWhereHas('customer', function ($cq) use ($search) {
                            $cq->where('full_name', 'like', "%{$search}%")
                                ->orWhere('id_number', 'like', "%{$search}%")
                                ->orWhere('customer_code', 'like', "%{$search}%")
                                ->orWhere('phone_primary', 'like', "%{$search}%");
                        });
                });
            }

            // 8. Renewal Expiry Filtering Logic
            // Maturity Date SQL expression: reservation_date + duration_months
            $maturityDateSql = "DATE_ADD(investments.reservation_date, INTERVAL investment_products.duration_months MONTH)";

            if ($request->filled('from_date') && $request->filled('to_date')) {
                $fromDate = Carbon::parse($request->from_date)->startOfDay();
                $toDate = Carbon::parse($request->to_date)->endOfDay();
                $query->whereRaw("{$maturityDateSql} BETWEEN ? AND ?", [$fromDate, $toDate]);
            } else {
                $filterType = $request->get('filter_type', 'expired'); // 'expired', 'expiring_soon', 'all_expired'

                if ($filterType === 'expiring_soon') {
                    // Expiring in the next N days
                    $query->whereRaw("{$maturityDateSql} >= CURDATE() AND {$maturityDateSql} <= DATE_ADD(CURDATE(), INTERVAL ? DAY)", [$days]);
                } elseif ($filterType === 'all_expired') {
                    // All past expired businesses
                    $query->whereRaw("{$maturityDateSql} <= CURDATE()");
                } else {
                    // Default ('expired'): Expired on or before today within the last N days (default 10 days)
                    $query->whereRaw("{$maturityDateSql} <= CURDATE() AND {$maturityDateSql} >= DATE_SUB(CURDATE(), INTERVAL ? DAY)", [$days]);
                }
            }

            // Order by computed maturity date descending
            $query->orderByRaw("{$maturityDateSql} DESC");

            // 9. Execute Pagination
            $renewals = $query->paginate($perPage);

            // 10. Transform Response Collection
            $renewals->getCollection()->transform(function ($inv) {
                $reservationDate = $inv->reservation_date ? Carbon::parse($inv->reservation_date) : null;
                $durationMonths = $inv->investmentProduct->duration_months ?? 0;
                
                $maturityDate = $reservationDate ? $reservationDate->copy()->addMonths($durationMonths) : null;
                
                $daysDiff = null;
                $isExpired = false;
                if ($maturityDate) {
                    $today = Carbon::now()->startOfDay();
                    $maturityDay = $maturityDate->copy()->startOfDay();
                    // Diff in days: negative means expired X days ago, positive means X days remaining
                    $daysDiff = (int) $today->diffInDays($maturityDay, false);
                    $isExpired = $daysDiff <= 0;
                }

                $calculations = [];
                if ($inv->investmentProduct) {
                    $calculations = $this->calculateInvestmentROI((float) $inv->investment_amount, $inv->investmentProduct);
                }

                return [
                    'id' => $inv->id,
                    'policy_number' => $inv->policy_number,
                    'application_number' => $inv->application_number,
                    'sales_code' => $inv->sales_code,
                    'reservation_date' => $reservationDate ? $reservationDate->format('Y-m-d') : null,
                    'maturity_date' => $maturityDate ? $maturityDate->format('Y-m-d') : null,
                    'days_diff' => $daysDiff,
                    'days_expired' => $daysDiff !== null ? abs($daysDiff) : null,
                    'is_expired' => $isExpired,
                    'status' => $inv->status,
                    'business_type' => $inv->business_type,
                    'investment_amount' => (float) $inv->investment_amount,
                    'monthly_maturity' => round($calculations['monthly_return'] ?? 0, 2),
                    'total_maturity' => round($calculations['maturity_amount'] ?? 0, 2),
                    'total_interest' => round($calculations['total_interest'] ?? 0, 2),
                    'customer' => [
                        'id' => $inv->customer->id ?? null,
                        'full_name' => $inv->customer->full_name ?? 'N/A',
                        'customer_code' => $inv->customer->customer_code ?? 'N/A',
                        'id_number' => $inv->customer->id_number ?? 'N/A',
                        'phone_primary' => $inv->customer->phone_primary ?? 'N/A',
                        'email' => $inv->customer->email ?? null,
                    ],
                    'branch' => [
                        'id' => $inv->branch->id ?? null,
                        'name' => $inv->branch->name ?? 'N/A',
                        'code' => $inv->branch->code ?? null,
                    ],
                    'product' => [
                        'id' => $inv->investmentProduct->id ?? null,
                        'name' => $inv->investmentProduct->name ?? 'N/A',
                        'duration_months' => $durationMonths,
                        'roi_percentage' => $inv->investmentProduct->roi_percentage ?? 0,
                    ],
                    'creator' => [
                        'id' => $inv->creator->id ?? null,
                        'name' => $inv->creator->name ?? 'N/A',
                        'username' => $inv->creator->username ?? null,
                    ],
                    'bank_details' => [
                        'bank_name' => $inv->bankDetail->bank_name ?? 'N/A',
                        'branch_name' => $inv->bankDetail->branch_name ?? 'N/A',
                        'account_number' => $inv->bankDetail->account_number ?? 'N/A',
                        'payment_method' => $inv->bankDetail->payment_method ?? 'N/A',
                    ]
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Renewal business list retrieved successfully',
                'data' => $renewals,
                'meta' => [
                    'days_filter' => $days,
                    'filter_type' => $request->get('filter_type', 'expired'),
                ]
            ], 200);

        } catch (\Throwable $th) {
            $this->logActivity('Error', 'Renewal', 'Failed to retrieve renewal business list', [
                'error' => $th->getMessage(),
                'user_id' => Auth::id(),
                'request' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve renewal business list',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
