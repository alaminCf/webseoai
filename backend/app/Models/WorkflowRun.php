<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowRun extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_WAITING_FOR_APPROVAL = 'waiting_for_approval';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_PARTIALLY_FAILED = 'partially_failed';

    protected $fillable = [
        'organization_id',
        'website_id',
        'automation_rule_id',
        'triggered_by_user_id',
        'run_uuid',
        'idempotency_key',
        'trigger_source',
        'rule_version',
        'status',
        'trigger_payload',
        'step_results',
        'retry_count',
        'max_retries',
        'error_details',
        'cost_incurred',
        'tokens_consumed',
        'queued_at',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'trigger_payload' => 'array',
        'step_results' => 'array',
        'cost_incurred' => 'decimal:4',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(WorkflowApproval::class);
    }

    /**
     * Append a step result to the step_results array.
     */
    public function logStep(string $stepName, string $status, array $data = [], ?string $error = null): void
    {
        $steps = $this->step_results ?? [];
        $steps[] = [
            'step' => $stepName,
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
            'data' => $data,
            'error' => $error,
        ];

        $this->update(['step_results' => $steps]);
    }
}
