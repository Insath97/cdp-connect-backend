<?php

namespace App\Traits;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

trait ActivityLogTrait
{
    /**
     * Log activity to database and Laravel log file.
     *
     * @param string $action
     * @param string $module
     * @param string $description
     * @param array|null $data
     * @return void
     */
    public function logActivity(string $action, string $module, string $description, ?array $data = null)
    {
        try {
            // DB logging
            ActivityLog::create([
                'user_id' => Auth::guard('api')->id(),
                'action' => $action,
                'module' => $module,
                'description' => $description,
                'properties' => $data,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            // Laravel file logging
            Log::info("[{$module}] {$action}: {$description} | User ID: " . (Auth::guard('api')->id() ?? 'Guest') . " | IP: " . request()->ip());

        } catch (\Throwable $th) {
            // Silently fail DB logging but log the failure to Laravel logs
            Log::error("Failed to log activity to database: " . $th->getMessage());
            Log::info("[{$module}] {$action}: {$description} (DB Log Failed)");
        }
    }
}
