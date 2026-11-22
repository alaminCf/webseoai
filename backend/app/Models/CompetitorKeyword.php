<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitorKeyword extends Model
{
    use HasFactory;

    public const GAP_MISSING = 'missing';
    public const GAP_WEAK = 'weak';
    public const GAP_SHARED = 'shared';
    public const GAP_STRONG = 'strong';
    public const GAP_UNTAPPED = 'untapped';

    public const GAP_TYPES = [
        self::GAP_MISSING,
        self::GAP_WEAK,
        self::GAP_SHARED,
        self::GAP_STRONG,
        self::GAP_UNTAPPED,
    ];

    protected $fillable = [
        'organization_id',
        'website_id',
        'keyword',
        'country',
        'language',
        'search_volume',
        'keyword_difficulty',
        'cpc',
        'search_intent',
        'target_position',
        'target_url',
        'competitor_positions',
        'gap_type',
        'serp_features',
        'opportunity_score',
        'data_source',
        'data_classification',
        'last_updated_at',
        'saved_recommendation_id',
    ];

    protected $casts = [
        'search_volume' => 'integer',
        'keyword_difficulty' => 'integer',
        'cpc' => 'float',
        'target_position' => 'integer',
        'competitor_positions' => 'array',
        'serp_features' => 'array',
        'opportunity_score' => 'float',
        'last_updated_at' => 'datetime',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
