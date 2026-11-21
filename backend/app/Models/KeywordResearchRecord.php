<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KeywordResearchRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'website_id',
        'keyword',
        'country',
        'language',
        'search_volume',
        'difficulty',
        'cpc',
        'intent',
        'competition_level',
        'provider',
        'provenance',
        'related_clusters',
    ];

    protected $casts = [
        'search_volume' => 'integer',
        'difficulty' => 'float',
        'cpc' => 'float',
        'provenance' => 'array',
        'related_clusters' => 'array',
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
