<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitorMonitoringConfig extends Model
{
    use HasFactory;

    public const FREQ_DAILY = 'daily';
    public const FREQ_WEEKLY = 'weekly';
    public const FREQ_MONTHLY = 'monthly';
    public const FREQ_MANUAL = 'manual';

    protected $fillable = [
        'organization_id',
        'website_id',
        'frequency',
        'country',
        'language',
        'notify_on_rank_change',
        'notify_on_new_keywords',
        'notify_on_backlink_change',
        'min_position_delta',
        'last_run_at',
        'next_run_at',
        'recent_alerts',
    ];

    protected $casts = [
        'notify_on_rank_change' => 'boolean',
        'notify_on_new_keywords' => 'boolean',
        'notify_on_backlink_change' => 'boolean',
        'min_position_delta' => 'integer',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
        'recent_alerts' => 'array',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
