<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'report_type',
        'period_key',
        'scope_key',
        'filters_hash',
        'filters',
        'snapshot_data',
        'generated_by',
    ];

    protected $casts = [
        'filters' => 'array',
        'snapshot_data' => 'array',
    ];

    /* Relationships */

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /* Scopes */

    public function scopeForPeriod($query, string $reportType, string $periodKey, string $scopeKey = 'global', string $filtersHash = 'default')
    {
        return $query->where('report_type', $reportType)
            ->where('period_key', $periodKey)
            ->where('scope_key', $scopeKey)
            ->where('filters_hash', $filtersHash);
    }
}
