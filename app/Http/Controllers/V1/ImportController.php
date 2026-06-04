<?php

namespace App\Http\Controllers\V1;

use App\Traits\ActivityLogTrait;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkImportRequest;
use App\Services\BulkImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ImportController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Import Index', only: ['index', 'listTables']),
            new Middleware('permission:Bulk Import', only: ['import']),
        ];
    }

    protected $importService;

    public function __construct(BulkImportService $importService)
    {
        $this->importService = $importService;
    }

    /**
     * List only the table names for a frontend select box.
     */
    public function listTables(): JsonResponse
    {
        try {
            $configs = $this->importService->getImportableConfig();
            $tables = array_map(function ($table) {
                return [
                    'id' => $table,
                    'name' => ucwords(str_replace('_', ' ', $table))
                ];
            }, array_keys($configs));

            return response()->json([
                'status' => 'success',
                'data' => $tables
            ], 200);

        } catch (\Throwable $th) {
            $this->logActivity('Error', 'Import', "Bulk import table list failure: " . $th->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve table list.',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * List all importable tables and their required headers.
     */
    public function index(): JsonResponse
    {
        try {
            $tables = $this->importService->getImportableTables();

            return response()->json([
                'status' => 'success',
                'data' => $tables
            ], 200);

        } catch (\Throwable $th) {
            $this->logActivity('Error', 'Import', "Bulk import table list failure: " . $th->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve importable tables.',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Handle bulk import for various system tables.
     */
    public function import(BulkImportRequest $request, string $table): JsonResponse
    {
        try {
            // Log the attempt
            $this->logActivity('Info', 'Import', "Bulk import started for table: $table", [
                'admin_id' => Auth::id(),
                'file_name' => $request->file('file')->getClientOriginalName()
            ]);

            $results = $this->importService->import($request->file('file'), $table);

            $this->logActivity('Info', 'Import', "Bulk import completed for table: $table", [
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
            $this->logActivity('Error', 'Import', "Bulk import critical failure: " . $th->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Import failed due to a system error.',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
