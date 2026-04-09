<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\DatabaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DatabaseController extends Controller implements HasMiddleware
{
    protected $databaseService;

    public function __construct(DatabaseService $databaseService)
    {
        $this->databaseService = $databaseService;
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Database Export', only: ['export']),
            new Middleware('permission:Database Import', only: ['import']),
        ];
    }

    /**
     * Export the database and download the file (Protected).
     */
    public function export(): BinaryFileResponse|JsonResponse
    {
        return $this->handleExport();
    }

    /**
     * Public Export for testing purposes.
     */
    public function publicExport(): BinaryFileResponse|JsonResponse
    {
        return $this->handleExport();
    }

    /**
     * Handle the common export logic.
     */
    protected function handleExport(): BinaryFileResponse|JsonResponse
    {
        try {
            $filePath = $this->databaseService->export();
            $filename = basename($filePath);

            return response()->download($filePath, $filename, [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ])->deleteFileAfterSend(true);

        } catch (\Throwable $th) {
            Log::error("Database export failure: " . $th->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to export database.',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Import a database from an uploaded SQL file.
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file'
        ]);

        try {
            $file = $request->file('file');
            
            // Check file extension (case-insensitive)
            if (strtolower($file->getClientOriginalExtension()) !== 'sql') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid file type. Please upload a .sql file.'
                ], 422);
            }

            Log::info("Database import started by Admin ID: " . Auth::id());

            // Store temporarily
            $filePath = $file->storeAs('temp', 'import.sql');
            $fullPath = storage_path('app/' . $filePath);

            $this->databaseService->import($fullPath);

            // Clean up
            @unlink($fullPath);

            Log::info("Database imported successfully.");

            return response()->json([
                'status' => 'success',
                'message' => 'Database imported successfully.'
            ], 200);

        } catch (\Throwable $th) {
            Log::error("Database import failure: " . $th->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to import database.',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
