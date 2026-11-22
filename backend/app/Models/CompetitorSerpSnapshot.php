<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitorSerpSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'website_id',
        'keyword',
        'country',
        'language',
        'search_engine',
        'device',
        'location',
        'is_monitored',
        'search_intent',
        'serp_features',
        'organic_results',
        'target_position',
        'competitor_presence',
        'content_patterns',
        'data_source',
        'captured_at',
    ];

    protected $casts = [
        'is_monitored' => 'boolean',
        'serp_features' => 'array',
        'organic_results' => 'array',
        'target_position' => 'integer',
        'competitor_presence' => 'array',
        'content_patterns' => 'array',
        'captured_at' => 'datetime',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
