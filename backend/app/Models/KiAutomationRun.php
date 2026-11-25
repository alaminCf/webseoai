<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KiAutomationRun extends Model
{
    use HasFactory;

    protected $table = 'ki_automation_runs';

    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'website_id',
        'workflow_id',
        'trigger_type',
        'status',
        'keywords_discovered',
        'new_keywords',
        'clusters_updated',
        'opportunities_found',
        'alerts_created',
        'content_recommendations_created',
        'duration_ms',
        'summary',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'keywords_discovered' => 'integer',
        'new_keywords' => 'integer',
        'clusters_updated' => 'integer',
        'opportunities_found' => 'integer',
        'alerts_created' => 'integer',
        'content_recommendations_created' => 'integer',
        'duration_ms' => 'integer',
        'summary' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(KiAutomationWorkflow::class, 'workflow_id');
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
