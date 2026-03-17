<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkImportRequest;
use App\Services\BulkImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class ImportController extends Controller
{
    protected $importService;

    public function __construct(BulkImportService $importService)
    {
        $this->importService = $importService;
    }

    /**
     * Handle bulk import for various system tables.
     */
    public function import(BulkImportRequest $request, string $table): JsonResponse
    {
        try {
            // Log the attempt
            Log::info("Bulk import started for table: $table", [
                'admin_id' => Auth::id(),
                'file_name' => $request->file('file')->getClientOriginalName()
            ]);

            $results = $this->importService->import($request->file('file'), $table);

            Log::info("Bulk import completed for table: $table", [
                'admin_id' => Auth::id(),
                'imported' => $results['imported'],
                'failed' => $results['failed']
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Import process completed.',
                'data' => $results
            ], 200);

        } catch (\Throwable $th) {
            Log::error("Bulk import critical failure: " . $th->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Import failed due to a system error.',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
