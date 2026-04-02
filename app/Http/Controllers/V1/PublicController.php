<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerBankDetail;
use App\Models\Beneficiary;
use Illuminate\Http\Request;

class PublicController extends Controller
{
    /**
     * List all customers publicly (limited fields)
     */
    public function customers(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $customers = Customer::select('id', 'full_name', 'customer_code', 'id_number')
                ->where('is_active', true)
                ->orderBy('full_name', 'asc')
                ->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'message' => 'Public customers list retrieved successfully',
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

    /**
     * Get specific customer details including bank and beneficiaries (limited fields)
     */
    public function customerDetail($customer_code)
    {
        try {
            $customer = Customer::with([
                'bankDetails:id,customer_id,bank_name,branch_name,account_number,payment_method',
                'beneficiaries:id,customer_id,full_name,id_type,id_number,phone_primary,relationship,share_percentage'
            ])
                ->select('id', 'full_name', 'customer_code', 'id_number')
                ->where('customer_code', $customer_code)
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
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve customer details',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
