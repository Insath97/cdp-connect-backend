<?php

namespace App\Services;

use App\Models\ReportSnapshot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ReportSnapshotService
{
    /**
     * Retrieve a snapshot if available.
     */
    public function getSnapshot(
        string $reportType,
        string $periodKey,
        string $scopeKey = 'global',
        string $filtersHash = 'default'
    ): ?ReportSnapshot {
        return ReportSnapshot::forPeriod($reportType, $periodKey, $scopeKey, $filtersHash)->first();
    }

    /**
     * Check if a snapshot exists.
     */
    public function hasSnapshot(
        string $reportType,
        string $periodKey,
        string $scopeKey = 'global',
        string $filtersHash = 'default'
    ): bool {
        return ReportSnapshot::forPeriod($reportType, $periodKey, $scopeKey, $filtersHash)->exists();
    }

    /**
     * Store or update a snapshot for a given report and period.
     */
    public function storeSnapshot(
        string $reportType,
        string $periodKey,
        array $data,
        ?int $userId = null,
        string $scopeKey = 'global',
        ?array $filters = null,
        string $filtersHash = 'default'
    ): ReportSnapshot {
        $userId = $userId ?? Auth::id();

        return ReportSnapshot::updateOrCreate(
            [
                'report_type' => $reportType,
                'period_key' => $periodKey,
                'scope_key' => $scopeKey,
                'filters_hash' => $filtersHash,
            ],
            [
                'filters' => $filters,
                'snapshot_data' => $data,
                'generated_by' => $userId,
            ]
        );
    }

    /**
     * Delete a snapshot by ID.
     */
    public function deleteSnapshot(int $id): bool
    {
        $snapshot = ReportSnapshot::find($id);
        if ($snapshot) {
            return (bool) $snapshot->delete();
        }
        return false;
    }

    /**
     * Delete all snapshots for a given period.
     */
    public function deletePeriodSnapshots(string $periodKey): int
    {
        return ReportSnapshot::where('period_key', $periodKey)->delete();
    }

    /**
     * List all snapshots with metadata and generator info.
     */
    public function listSnapshots(?string $periodKey = null, ?string $reportType = null, int $perPage = 20)
    {
        $query = ReportSnapshot::with(['generatedBy:id,name,username,email'])
            ->select('id', 'report_type', 'period_key', 'scope_key', 'filters_hash', 'generated_by', 'created_at', 'updated_at')
            ->orderBy('period_key', 'desc')
            ->orderBy('created_at', 'desc');

        if ($periodKey) {
            $query->where('period_key', $periodKey);
        }

        if ($reportType) {
            $query->where('report_type', $reportType);
        }

        return $query->paginate($perPage);
    }

    /**
     * In-memory pagination helper for snapshot arrays.
     */
    public function paginateArray(array $items, int $perPage, int $currentPage, array $options = []): LengthAwarePaginator
    {
        $total = count($items);
        $offset = ($currentPage - 1) * $perPage;
        $slicedItems = array_slice($items, $offset, $perPage);

        return new LengthAwarePaginator(
            array_values($slicedItems),
            $total,
            $perPage,
            $currentPage,
            $options
        );
    }
}
