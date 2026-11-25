<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KiKeyword extends Model
{
    public const INTENT_INFORMATIONAL = 'informational';
    public const INTENT_NAVIGATIONAL = 'navigational';
    public const INTENT_COMMERCIAL = 'commercial';
    public const INTENT_TRANSACTIONAL = 'transactional';

    public const CAT_HIGH_OPPORTUNITY = 'high_opportunity';
    public const CAT_MEDIUM_OPPORTUNITY = 'medium_opportunity';
    public const CAT_LOW_OPPORTUNITY = 'low_opportunity';
    public const CAT_HIGH_COMPETITION = 'high_competition';
    public const CAT_EXISTING_STRENGTH = 'existing_strength';
    public const CAT_CONTENT_GAP = 'content_gap';

    protected $fillable = [
        'organization_id',
        'website_id',
        'project_id',
        'seed_keyword_id',
        'keyword',
        'normalized_keyword',
        'country',
        'language',
        'search_engine',
        'device',
        'location',
        'discovery_source',
        'question_category',
        'is_long_tail',
        'word_count',
        'search_volume',
        'keyword_difficulty',
        'cpc',
        'paid_competition',
        'competition_level',
        'search_intent',
        'intent_source',
        'intent_confidence',
        'trend_data',
        'trend_direction',
        'serp_features',
        'serp_top_urls',
        'current_position',
        'previous_position',
        'ranking_url',
        'gsc_clicks',
        'gsc_impressions',
        'gsc_ctr',
        'competitor_coverage_count',
        'opportunity_score',
        'relevance_score',
        'ranking_potential',
        'opportunity_category',
        'recommended_priority',
        'opportunity_reason',
        'data_confidence',
        'data_source',
        'metric_classification',
        'is_saved',
        'is_monitored',
        'saved_recommendation_id',
        'saved_brief_id',
        'last_updated_at',
    ];

    protected $casts = [
        'is_long_tail' => 'boolean',
        'word_count' => 'integer',
        'search_volume' => 'integer',
        'keyword_difficulty' => 'float',
        'cpc' => 'float',
        'paid_competition' => 'float',
        'intent_confidence' => 'float',
        'trend_data' => 'array',
        'serp_features' => 'array',
        'serp_top_urls' => 'array',
        'current_position' => 'float',
        'previous_position' => 'float',
        'gsc_clicks' => 'integer',
        'gsc_impressions' => 'integer',
        'gsc_ctr' => 'float',
        'competitor_coverage_count' => 'integer',
        'opportunity_score' => 'float',
        'relevance_score' => 'float',
        'is_saved' => 'boolean',
        'is_monitored' => 'boolean',
        'last_updated_at' => 'datetime',
    ];

    public static function normalize(string $keyword): string
    {
        $clean = strtolower(trim(preg_replace('/\s+/', ' ', $keyword) ?? $keyword));
        return $clean;
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(KiKeywordHistory::class, 'keyword_id')->orderByDesc('captured_at');
    }

    public function clusters(): BelongsToMany
    {
        return $this->belongsToMany(KiCluster::class, 'ki_cluster_memberships', 'keyword_id', 'cluster_id')
            ->withPivot(['is_primary', 'similarity_score'])
            ->withTimestamps();
    }
}
