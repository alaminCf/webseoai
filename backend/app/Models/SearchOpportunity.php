<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchOpportunity extends Model
{
    use HasFactory;

    public const TYPE_LOW_CTR = 'low_ctr';
    public const TYPE_CLICK_DECAY = 'click_decay';
    public const TYPE_RISING_QUERY = 'rising_query';
    public const TYPE_KEYWORD_GAP = 'keyword_gap';

    public const STATUS_DETECTED = 'detected';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'organization_id',
        'website_id',
        'type',
        'target_type',
        'target_identifier',
        'headline',
        'reasoning',
        'recommended_action',
        'impact_score',
        'metrics_snapshot',
        'status',
        'detected_at',
    ];

    protected $casts = [
        'impact_score' => 'integer',
        'metrics_snapshot' => 'array',
        'detected_at' => 'datetime',
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
