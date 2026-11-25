<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KiAutomationWorkflow extends Model
{
    use HasFactory;

    protected $table = 'ki_automation_workflows';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';

    public const FREQ_DAILY = 'daily';
    public const FREQ_WEEKLY = 'weekly';
    public const FREQ_MONTHLY = 'monthly';
    public const FREQ_CUSTOM = 'custom';

    protected $fillable = [
        'organization_id',
        'website_id',
        'created_by_user_id',
        'name',
        'status',
        'seed_keywords',
        'business_category',
        'target_country',
        'target_language',
        'discovery_sources',
        'schedule_frequency',
        'custom_interval_hours',
        'auto_cluster',
        'auto_monitor_rank',
        'auto_content_recommendations',
        'notify_on_opportunities',
        'min_opportunity_score',
        'last_run_at',
        'next_run_at',
        'total_keywords_discovered',
        'total_opportunities_found',
        'last_error',
    ];

    protected $casts = [
        'seed_keywords' => 'array',
        'discovery_sources' => 'array',
        'custom_interval_hours' => 'integer',
        'auto_cluster' => 'boolean',
        'auto_monitor_rank' => 'boolean',
        'auto_content_recommendations' => 'boolean',
        'notify_on_opportunities' => 'boolean',
        'min_opportunity_score' => 'integer',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
        'total_keywords_discovered' => 'integer',
        'total_opportunities_found' => 'integer',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(KiAutomationRun::class, 'workflow_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(KiAlert::class, 'workflow_id');
    }

    public function computeNextRunAt(?\Carbon\CarbonInterface $from = null): \Carbon\Carbon
    {
        $base = $from ? \Carbon\Carbon::instance($from) : now();

        return match ($this->schedule_frequency) {
            self::FREQ_DAILY => $base->copy()->addDay(),
            self::FREQ_MONTHLY => $base->copy()->addMonth(),
            self::FREQ_CUSTOM => $base->copy()->addHours(max(1, min(720, (int) ($this->custom_interval_hours ?: 24)))),
            default => $base->copy()->addWeek(),
        };
    }
}
