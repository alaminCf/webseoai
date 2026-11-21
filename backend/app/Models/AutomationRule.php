<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRule extends Model
{
    use HasFactory;

    public const TRIGGER_SCHEDULE = 'schedule';
    public const TRIGGER_CRAWL_COMPLETED = 'crawl_completed';
    public const TRIGGER_ISSUE_DETECTED = 'issue_detected';
    public const TRIGGER_PERFORMANCE_THRESHOLD = 'performance_threshold';
    public const TRIGGER_CONTENT_APPROVED = 'content_approved';
    public const TRIGGER_ACTION_FAILED = 'action_failed';

    public const ACTION_FULL_AUDIT_AND_RECOMMEND = 'full_audit_and_recommend';
    public const ACTION_AUTO_STAGE_METADATA_FIX = 'auto_stage_metadata_fix';
    public const ACTION_PUBLISH_APPROVED_CONTENT = 'publish_approved_content';
    public const ACTION_SYNC_GSC_AND_REPORT = 'sync_gsc_and_report';
    public const ACTION_RECOVER_FAILED_EXECUTION = 'recover_failed_execution';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'organization_id',
        'website_id',
        'created_by',
        'name',
        'description',
        'trigger_type',
        'trigger_config',
        'action_type',
        'action_config',
        'is_write_capable',
        'require_human_approval',
        'dry_run_mode',
        'status',
        'max_runs_per_day',
        'monthly_cost_budget',
        'current_month_cost',
        'last_triggered_at',
        'version',
    ];

    protected $attributes = [
        'max_runs_per_day' => 10,
        'monthly_cost_budget' => 50.00,
        'current_month_cost' => 0.00,
        'require_human_approval' => true,
        'dry_run_mode' => false,
        'status' => self::STATUS_ACTIVE,
        'version' => 1,
    ];

    protected $casts = [
        'trigger_config' => 'array',
        'action_config' => 'array',
        'is_write_capable' => 'boolean',
        'require_human_approval' => 'boolean',
        'dry_run_mode' => 'boolean',
        'monthly_cost_budget' => 'decimal:2',
        'current_month_cost' => 'decimal:2',
        'last_triggered_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }
}
