<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SerpCreditUsage extends Model
{
    protected $fillable = [
        'organization_id',
        'user_id',
        'website_id',
        'platform_api_key_id',
        'provider',
        'keyword',
        'country',
        'depth',
        'credits_used',
        'cache_hit',
        'key_source',
    ];

    protected $casts = [
        'depth' => 'integer',
        'credits_used' => 'integer',
        'cache_hit' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function platformApiKey(): BelongsTo
    {
        return $this->belongsTo(PlatformApiKey::class);
    }
}
