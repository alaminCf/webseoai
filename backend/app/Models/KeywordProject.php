<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KeywordProject extends Model
{
    protected $fillable = [
        'organization_id',
        'website_id',
        'created_by_user_id',
        'name',
        'target_country',
        'target_language',
        'search_engine',
        'search_location',
        'device',
        'search_network',
        'business_name',
        'business_category',
        'scoring_weights',
        'status',
    ];

    protected $casts = [
        'scoring_weights' => 'array',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function seeds(): HasMany
    {
        return $this->hasMany(KiSeedKeyword::class, 'project_id');
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(KiKeyword::class, 'project_id');
    }
}
