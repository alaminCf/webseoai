<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitorContentGap extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'website_id',
        'topic',
        'suggested_title',
        'primary_keyword',
        'secondary_keywords',
        'search_intent',
        'suggested_content_type',
        'recommended_outline',
        'competitor_reference_urls',
        'target_coverage_status',
        'rationale',
        'priority',
        'confidence',
        'data_source',
        'status',
        'content_brief_id',
    ];

    protected $casts = [
        'secondary_keywords' => 'array',
        'recommended_outline' => 'array',
        'competitor_reference_urls' => 'array',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
