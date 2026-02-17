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

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = Customer::with(['user']);

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                        ->orWhere('customer_code', 'like', "%{$search}%")
                        ->orWhere('id_number', 'like', "%{$search}%")
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

            // Setup default values or logic if needed
            // If customer_id is not provided, maybe assign current user?
            // The migration allows nullable, but usually we track who created it.
            // If customer_id represents the "Agent", we can set it.
            if (!isset($data['customer_id'])) {
                $data['customer_id'] = $currentUser->id;
            }

            $customer = Customer::create($data);

            Log::info('Customer created', [
                'creator_id' => $currentUser->id,
                'customer_id' => $customer->id,
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
}
