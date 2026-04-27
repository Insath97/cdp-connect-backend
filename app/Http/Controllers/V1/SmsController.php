<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class SmsController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Sms Send', only: ['send']),
            new Middleware('permission:Sms Import Send', only: ['importAndSend']),
            new Middleware('permission:Sms Send All', only: ['sendToAllCustomers']),
        ];
    }

    protected $smsService;

    public function __construct(SmsService $smsService)
    {
        $this->smsService = $smsService;
    }

    /**
     * Send SMS to direct numbers provided in request.
     * Supports num1, num2... or numbers[] array.
     */
    public function send(Request $request): JsonResponse
    {
        $message = $request->input('message', $this->getDefaultMessage());
        $numbers = $this->extractNumbersFromRequest($request);

        if (empty($numbers)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No valid phone numbers provided.'
            ], 422);
        }

        $results = $this->smsService->sendBulkSms($numbers, $message);

        return response()->json([
            'status' => 'success',
            'message' => 'SMS sending process initiated.',
            'data' => [
                'total_numbers' => count($numbers),
                'results' => $results
            ]
        ], 200);
    }

    /**
     * Import numbers from CSV and send SMS.
     */
    public function importAndSend(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt',
            'column_index' => 'nullable|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid file or parameters.',
                'errors' => $validator->errors()
            ], 422);
        }

        $file = $request->file('file');
        $columnIndex = $request->input('column_index', 0); // Default to first column
        $message = $request->input('message', $this->getDefaultMessage());

        $numbers = [];
        try {
            $handle = fopen($file->getRealPath(), 'r');
            // Skip header if needed? Let's try to detect if first row is a header
            $firstRow = fgetcsv($handle);

            // If first row looks like a number, add it. Otherwise, treat as header.
            if ($firstRow && isset($firstRow[$columnIndex])) {
                if (preg_match('/^[0-9+]+$/', preg_replace('/[^0-9+]/', '', $firstRow[$columnIndex]))) {
                    $numbers[] = $firstRow[$columnIndex];
                }
            }

            while (($rowData = fgetcsv($handle)) !== false) {
                if (isset($rowData[$columnIndex]) && !empty($rowData[$columnIndex])) {
                    $numbers[] = $rowData[$columnIndex];
                }
            }
            fclose($handle);
        } catch (\Throwable $th) {
            Log::error("Bulk SMS import failure: " . $th->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to read file.',
                'error' => $th->getMessage()
            ], 500);
        }

        if (empty($numbers)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No numbers found in the specified column.'
            ], 422);
        }

        $results = $this->smsService->sendBulkSms($numbers, $message);

        return response()->json([
            'status' => 'success',
            'message' => 'SMS sending process initiated for imported numbers.',
            'data' => [
                'total_numbers' => count($numbers),
                'results' => $results
            ]
        ], 200);
    }

    public function sendToAllCustomers(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $message = $request->input('message', $this->getDefaultMessage());

            $customerQuery = Customer::where('is_active', true);

            if ($user->hasRole('Branch Coordinator')) {
                $assignedBranchIds = $user->assignedBranches()->pluck('branches.id')->toArray();
                $customerQuery->whereHas('user', function ($uq) use ($assignedBranchIds) {
                    $uq->whereIn('branch_id', $assignedBranchIds);
                });
            }

            $customers = $customerQuery->select('phone_primary', 'phone_secondary')->get();

            $numbers = [];
            foreach ($customers as $customer) {
                if (!empty($customer->phone_primary)) {
                    $numbers[] = $customer->phone_primary;
                }
                if (!empty($customer->phone_secondary)) {
                    $numbers[] = $customer->phone_secondary;
                }
            }

            $numbers = array_unique(array_filter($numbers));

            if (empty($numbers)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No customers found with valid phone numbers.'
                ], 422);
            }

            $results = $this->smsService->sendBulkSms($numbers, $message);

            Log::info('Bulk SMS sent to all customers', [
                'user_id' => $user->id,
                'total_numbers' => count($numbers)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'SMS sending process initiated for all customers.',
                'data' => [
                    'total_numbers' => count($numbers),
                    'results' => $results
                ]
            ], 200);
        } catch (\Throwable $th) {
            Log::error("Bulk SMS to all customers failure: " . $th->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send SMS to all customers.',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Get the default SMS message template.
     */
    protected function getDefaultMessage(): string
    {
        return "Dear Sir/Madam,\n\nThank you for choosing Ceylon Development Plantation Empire (Pvt) Ltd. We are here to serve you and are committed to providing you with the best Services. Our team will contact you shortly with further details and personalized assistance.\n\nWe look forward to serving you.\n\nThank you.\nCeylon Development Plantation Empire (Pvt) Ltd.\n0114 007 007\nwww.cdp.lk";
    }

    /**
     * Extract numbers from request parameters like num1, num2, etc. or numbers array.
     */
    protected function extractNumbersFromRequest(Request $request): array
    {
        $numbers = [];

        // Support array 'numbers'
        if ($request->has('numbers') && is_array($request->input('numbers'))) {
            $numbers = array_merge($numbers, $request->input('numbers'));
        }

        // Support num1, num2, etc.
        $allParams = $request->all();
        foreach ($allParams as $key => $value) {
            if (preg_match('/^num\d+$/', $key) && !empty($value)) {
                $numbers[] = $value;
            }
        }

        // Remove duplicates and empty values
        return array_unique(array_filter($numbers));
    }
}
