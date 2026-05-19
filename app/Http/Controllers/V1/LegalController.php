<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateLegalRequest;
use App\Http\Requests\UpdateLegalRequest;
use App\Models\Legal;
use App\Models\Investment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class LegalController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Legal Index', only: ['index', 'show']),
            new Middleware('permission:Legal Create', only: ['store']),
            new Middleware('permission:Legal Update', only: ['update']),
            new Middleware('permission:Legal Delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = Legal::with(['branch', 'customer', 'investment', 'investmentProduct']);

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

            $legals = $query->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'message' => 'Legals retrieved successfully',
                'data' => $legals
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve legals',
                'error' => $th->getMessage()
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
                $existingLegal->update([
                    'date_of_agreement' => $data['date_of_agreement'] ?? $existingLegal->date_of_agreement,
                    'year_in_words' => $data['year_in_words'] ?? $existingLegal->year_in_words,
                    'year' => $data['year'] ?? $existingLegal->year,
                    'witness_01_name' => $data['witness_01_name'] ?? $existingLegal->witness_01_name,
                    'witness_01_nic' => $data['witness_01_nic'] ?? $existingLegal->witness_01_nic,
                    'witness_01_address' => $data['witness_01_address'] ?? $existingLegal->witness_01_address,
                    'witness_02_name' => $data['witness_02_name'] ?? $existingLegal->witness_02_name,
                    'witness_02_nic' => $data['witness_02_nic'] ?? $existingLegal->witness_02_nic,
                    'witness_02_address' => $data['witness_02_address'] ?? $existingLegal->witness_02_address,
                ]);

                DB::commit();

                Log::info('Legal updated automatically on duplicate request', [
                    'user_id' => Auth::id(),
                    'legal_id' => $existingLegal->id,
                    'investment_id' => $existingLegal->investment_id,
                    'language' => $existingLegal->language
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Legal agreement updated successfully',
                    'data' => $existingLegal->load(['branch', 'customer', 'investment', 'investmentProduct'])
                ], 200);
            }

            // Create a new legal agreement
            $investment = Investment::with(['customer', 'branch'])->findOrFail($data['investment_id']);
            $customer = $investment->customer;
            $branch = $investment->branch;

            // Generate unique legal_number: LEG-{BranchCode}-{YYMM}{Sequence}
            $yymm = date('ym');
            $prefix = 'LEG-' . ($branch->code ?? 'GEN') . '-' . $yymm;

            $lastLegal = Legal::query()->where('legal_number', 'like', $prefix . '%')
                ->orderBy('legal_number', 'desc')
                ->first();

            $sequence = $lastLegal ? (int) substr($lastLegal->legal_number, -4) + 1 : 1;
            $legalNumber = $prefix . str_pad((string)$sequence, 4, '0', STR_PAD_LEFT);

            $legal = Legal::create([
                'investment_id' => $investment->id,
                'language' => $data['language'],
                'legal_number' => $legalNumber,
                'date_of_agreement' => $data['date_of_agreement'] ?? now(),
                'branch_id' => $investment->branch_id,
                'customer_id' => $investment->customer_id,
                'full_name' => $customer->full_name,
                'name_with_initials' => $customer->name_with_initials,
                'id_type' => $customer->id_type ?? 'nic',
                'id_number' => $customer->id_number,
                'email' => $customer->email,
                'address_line_1' => $customer->address_line_1,
                'address_line_2' => $customer->address_line_2,
                'landmark' => $customer->landmark,
                'city' => $customer->city,
                'state' => $customer->state,
                'country' => $customer->country ?? 'Sri Lanka',
                'postal_code' => $customer->postal_code,
                'investment_product_id' => $investment->investment_product_id,
                'year_in_words' => $data['year_in_words'] ?? null,
                'year' => $data['year'] ?? null,
                'witness_01_name' => $data['witness_01_name'] ?? null,
                'witness_01_nic' => $data['witness_01_nic'] ?? null,
                'witness_01_address' => $data['witness_01_address'] ?? null,
                'witness_02_name' => $data['witness_02_name'] ?? null,
                'witness_02_nic' => $data['witness_02_nic'] ?? null,
                'witness_02_address' => $data['witness_02_address'] ?? null,
                'created_by' => Auth::id()
            ]);

            DB::commit();

            Log::info('Legal created', [
                'user_id' => Auth::id(),
                'legal_id' => $legal->id,
                'legal_number' => $legal->legal_number
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Legal agreement created successfully',
                'data' => $legal->load(['branch', 'customer', 'investment', 'investmentProduct'])
            ], 201);

        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Failed to store legal', [
                'error' => $th->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process legal agreement',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $legal = Legal::with(['branch', 'customer', 'investment', 'investmentProduct'])->find($id);

            if (!$legal) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Legal not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Legal retrieved successfully',
                'data' => $legal
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve legal',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function update(UpdateLegalRequest $request, string $id)
    {
        try {
            $legal = Legal::query()->find($id);

            if (!$legal) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Legal not found'
                ], 404);
            }

            $data = $request->validated();
            $legal->update($data);

            Log::info('Legal updated', [
                'user_id' => Auth::id(),
                'legal_id' => $legal->id,
                'updated_fields' => array_keys($data)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Legal agreement updated successfully',
                'data' => $legal->load(['branch', 'customer', 'investment', 'investmentProduct'])
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update legal',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $legal = Legal::query()->find($id);

            if (!$legal) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Legal not found'
                ], 404);
            }

            $legal->delete($id);

            Log::info('Legal deleted', [
                'user_id' => Auth::id(),
                'legal_id' => $id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Legal agreement deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete legal',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
