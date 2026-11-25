<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KiCluster extends Model
{
    protected $fillable = [
        'organization_id',
        'website_id',
        'project_id',
        'name',
        'primary_keyword',
        'clustering_method',
        'search_intent',
        'keyword_count',
        'combined_search_volume',
        'avg_difficulty',
        'opportunity_score',
        'suggested_pillar_page',
        'suggested_supporting_content',
        'is_saved',
        'sent_to_content_studio_brief_id',
    ];

    protected $casts = [
        'keyword_count' => 'integer',
        'combined_search_volume' => 'integer',
        'avg_difficulty' => 'float',
        'opportunity_score' => 'float',
        'suggested_supporting_content' => 'array',
        'is_saved' => 'boolean',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(KiClusterMembership::class, 'cluster_id');
    }

    public function keywords(): BelongsToMany
    {
        return $this->belongsToMany(KiKeyword::class, 'ki_cluster_memberships', 'cluster_id', 'keyword_id')
            ->withPivot(['is_primary', 'similarity_score'])
            ->withTimestamps();
    }
}
