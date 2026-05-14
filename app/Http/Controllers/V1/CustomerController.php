<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCustomerRequest;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class CustomerController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Customer Index', only: ['index', 'show']),
            new Middleware('permission:Customer Create', only: ['store']),
            new Middleware('permission:Customer Update', only: ['update']),
            new Middleware('permission:Customer Delete', only: ['destroy']),
            new Middleware('permission:Customer Restore', only: ['restore']),
            new Middleware('permission:Customer Force Delete', only: ['forceDelete']),
            new Middleware('permission:Customer Toggle Status', only: ['toggleStatus']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $user = Auth::guard('api')->user();

            // Hierarchy Visibility Logic
            if (!$user->hasRole('Super Admin') && ($user->user_type !== 'admin')) {
                $accessibleBranchIds = [];
                
                if ($user->hasRole('Branch Coordinator') || $user->hasRole('Temp BOC')) {
                    $accessibleBranchIds = $user->assignedBranches()->pluck('branches.id')->toArray();
                } else {
                    // Fallback to primary branch
                    $accessibleBranchIds = [$user->branch_id];
                }

                // If user is hierarchical (GM, AGM, etc.), they might see more, but usually branch is the unit of isolation
                // For now, filter by branches they have access to.
                $query->where(function ($q) use ($accessibleBranchIds) {
                    $q->whereHas('investments', function ($iq) use ($accessibleBranchIds) {
                        $iq->whereIn('branch_id', $accessibleBranchIds);
                    })->orWhereHas('quotations', function ($qq) use ($accessibleBranchIds) {
                        $qq->whereIn('branch_id', $accessibleBranchIds);
                    });
                });
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                        ->orWhere('customer_code', 'like', "%{$search}%")
                        ->orWhere('id_number', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone_primary', 'like', "%{$search}%");
                });
            }

            // Filter by user (agent) if needed
            if ($request->has('customer_id')) {
                $query->where('customer_id', $request->customer_id);
            }

            $customers = $query->orderBy('created_at', 'desc')->paginate($perPage);

            Log::info('Customers index accessed', [
                'user_id' => Auth::id(),
                'filters' => $request->only(['search', 'customer_id']),
                'count' => $customers->count()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Customers retrieved successfully',
                'data' => $customers
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve customers',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function store(CreateCustomerRequest $request)
    {
        try {
            $currentUser = Auth::guard('api')->user();
            $data = $request->validated();

            // Generate Customer Code if not provided
            if (empty($data['customer_code'])) {
                $lastCustomer = Customer::orderBy('id', 'desc')->first();
                $lastId = $lastCustomer ? $lastCustomer->id : 0;
                $nextId = $lastId + 1;
                $data['customer_code'] = 'CUS-' . str_pad($nextId, 5, '0', STR_PAD_LEFT);
            }

            $customer = Customer::create($data);

            Log::info('Customer created', [
                'creator_id' => $currentUser->id,
                'customer_code' => $customer->customer_code
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Customer created successfully',
                'data' => $customer
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create customer',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $customer = Customer::with(['user'])->find($id);

            if (!$customer) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Customer not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Customer retrieved successfully',
                'data' => $customer
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve customer',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function update(UpdateCustomerRequest $request, string $id)
    {
        try {
            $customer = Customer::find($id);

            if (!$customer) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Customer not found'
                ], 404);
            }

            $data = $request->validated();
            $customer->update($data);

            Log::info('Customer updated', [
                'updater_id' => Auth::id(),
                'customer_id' => $customer->id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Customer updated successfully',
                'data' => $customer
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update customer',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $customer = Customer::find($id);

            if (!$customer) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Customer not found'
                ], 404);
            }

            $customer->delete();

            Log::info('Customer deleted (soft)', [
                'deleter_id' => Auth::id(),
                'customer_id' => $id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Customer deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete customer',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function restore(string $id)
    {
        try {
            $customer = Customer::withTrashed()->find($id);

            if (!$customer) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Customer not found'
                ], 404);
            }

            $customer->restore();

            Log::info('Customer restored', [
                'restorer_id' => Auth::id(),
                'customer_id' => $id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Customer restored successfully',
                'data' => $customer
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to restore customer',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function forceDelete(string $id)
    {
        try {
            $customer = Customer::withTrashed()->find($id);

            if (!$customer) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Customer not found'
                ], 404);
            }

            $customer->forceDelete();

            Log::info('Customer permanently deleted', [
                'deleter_id' => Auth::id(),
                'customer_id' => $id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Customer permanently deleted'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to permanently delete customer',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function toggleStatus(string $id)
    {
        try {
            $customer = Customer::find($id);

            if (!$customer) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Customer not found'
                ], 404);
            }

            $customer->is_active = !$customer->is_active;
            $customer->save();

            Log::info('Customer status toggled', [
                'user_id' => Auth::id(),
                'customer_id' => $customer->id,
                'new_status' => $customer->is_active
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Customer status updated successfully',
                'data' => [
                    'id' => $customer->id,
                    'is_active' => $customer->is_active
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle customer status',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function getPublicDetails($customer_code = null)
    {
        try {
            if ($customer_code) {
                $customer = Customer::with([
                    'bankDetails:id,customer_id,bank_name,branch_name,account_number,payment_method',
                    'beneficiaries:id,customer_id,full_name,id_type,id_number,phone_primary,relationship,share_percentage'
                ])
                    ->select('id', 'customer_code', 'full_name', 'email', 'phone_primary', 'id_type', 'id_number')
                    ->where('customer_code', $customer_code)->orderBy('id', 'asc')
                    ->first();

                if (!$customer) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Customer not found'
                    ], 404);
                }

                return response()->json([
                    'status' => 'success',
                    'message' => 'Customer details retrieved successfully',
                    'data' => $customer
                ], 200);
            }

            // If no customer_code, return all
            $perPage = request()->get('per_page', 15);
            $user = Auth::guard('api')->user();
            $query = Customer::with([
                'bankDetails:id,customer_id,bank_name,branch_name,account_number,payment_method',
                'beneficiaries:id,customer_id,full_name,id_type,id_number,phone_primary,relationship,share_percentage'
            ])
                ->select('id', 'customer_code', 'full_name', 'email', 'phone_primary', 'id_type', 'id_number')
                ->orderBy('id', 'asc');

            // Visibility Logic (Consistency)
            if ($user && !$user->hasRole('Super Admin') && ($user->user_type !== 'admin')) {
                if ($user->hasRole('Branch Coordinator') || $user->hasRole('Temp BOC')) {
                    $accessibleBranchIds = $user->assignedBranches()->pluck('branches.id')->toArray();
                    $query->where(function ($q) use ($accessibleBranchIds) {
                        $q->whereHas('investments', function ($iq) use ($accessibleBranchIds) {
                            $iq->whereIn('branch_id', $accessibleBranchIds);
                        })->orWhereHas('quotations', function ($qq) use ($accessibleBranchIds) {
                            $qq->whereIn('branch_id', $accessibleBranchIds);
                        });
                    });
                } else {
                    $descendantIds = $user->getAllDescendantIds();
                    $accessibleUserIds = array_merge([$user->id], $descendantIds);
                    $query->where(function ($q) use ($accessibleUserIds) {
                        $q->whereHas('investments', function ($iq) use ($accessibleUserIds) {
                            $iq->whereIn('created_by', $accessibleUserIds);
                        })->orWhereHas('quotations', function ($qq) use ($accessibleUserIds) {
                            $qq->whereIn('created_by', $accessibleUserIds);
                        });
                    });
                }
            }

            $customers = $query->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'message' => 'All customer details retrieved successfully',
                'data' => $customers
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve customer details',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
