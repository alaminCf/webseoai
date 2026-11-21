<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchConsoleSyncLog extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'website_id',
        'property_id',
        'sync_type',
        'start_date',
        'end_date',
        'rows_synced',
        'status',
        'error_message',
        'metrics_summary',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'rows_synced' => 'integer',
        'metrics_summary' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
