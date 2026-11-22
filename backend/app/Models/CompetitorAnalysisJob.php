<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitorAnalysisJob extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'website_id',
        'initiated_by',
        'job_type',
        'status',
        'progress_percent',
        'competitor_ids',
        'parameters',
        'result_summary',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'progress_percent' => 'integer',
        'competitor_ids' => 'array',
        'parameters' => 'array',
        'result_summary' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
