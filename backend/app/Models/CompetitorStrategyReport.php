<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitorStrategyReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'website_id',
        'generated_by',
        'analysis_job_id',
        'competitor_ids',
        'executive_summary',
        'strength_analysis',
        'weakness_analysis',
        'keyword_opportunities',
        'content_opportunities',
        'backlink_opportunities',
        'serp_opportunities',
        'strategic_recommendations',
        'provider_model',
        'data_sources_used',
        'generated_at',
    ];

    protected $casts = [
        'competitor_ids' => 'array',
        'strength_analysis' => 'array',
        'weakness_analysis' => 'array',
        'keyword_opportunities' => 'array',
        'content_opportunities' => 'array',
        'backlink_opportunities' => 'array',
        'serp_opportunities' => 'array',
        'strategic_recommendations' => 'array',
        'data_sources_used' => 'array',
        'generated_at' => 'datetime',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
