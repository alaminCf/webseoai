<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KiProviderUsageLog extends Model
{
    use HasFactory;

    protected $table = 'ki_provider_usage_logs';

    protected $fillable = [
        'organization_id',
        'website_id',
        'user_id',
        'provider',
        'operation',
        'query_summary',
        'results_count',
        'credits_used',
        'estimated_cost_usd',
        'cache_hit',
        'status',
        'duration_ms',
        'error_message',
    ];

    protected $casts = [
        'results_count' => 'integer',
        'credits_used' => 'integer',
        'estimated_cost_usd' => 'float',
        'cache_hit' => 'boolean',
        'duration_ms' => 'integer',
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
