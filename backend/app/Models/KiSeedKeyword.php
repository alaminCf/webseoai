<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KiSeedKeyword extends Model
{
    protected $fillable = [
        'organization_id',
        'website_id',
        'project_id',
        'seed_text',
        'seed_type',
        'country',
        'language',
        'sources_used',
        'discovered_count',
        'last_researched_at',
    ];

    protected $casts = [
        'sources_used' => 'array',
        'discovered_count' => 'integer',
        'last_researched_at' => 'datetime',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(KiKeyword::class, 'seed_keyword_id');
    }
}
