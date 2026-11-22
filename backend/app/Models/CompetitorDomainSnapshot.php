<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitorDomainSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'website_id',
        'competitor_domain_id',
        'domain',
        'is_target',
        'country',
        'language',
        'organic_traffic_estimate',
        'organic_keywords_count',
        'traffic_value_estimate',
        'referring_domains_count',
        'backlinks_count',
        'indexed_pages_count',
        'authority_score',
        'authority_metric_label',
        'serp_visibility_score',
        'ranking_distribution',
        'top_keywords',
        'top_pages',
        'crawled_signals',
        'data_source',
        'data_classification',
        'captured_at',
    ];

    protected $casts = [
        'is_target' => 'boolean',
        'organic_traffic_estimate' => 'integer',
        'organic_keywords_count' => 'integer',
        'traffic_value_estimate' => 'float',
        'referring_domains_count' => 'integer',
        'backlinks_count' => 'integer',
        'indexed_pages_count' => 'integer',
        'authority_score' => 'integer',
        'serp_visibility_score' => 'float',
        'ranking_distribution' => 'array',
        'top_keywords' => 'array',
        'top_pages' => 'array',
        'crawled_signals' => 'array',
        'captured_at' => 'datetime',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function competitorDomain(): BelongsTo
    {
        return $this->belongsTo(CompetitorDomain::class);
    }
}
