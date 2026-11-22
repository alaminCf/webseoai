<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitorBacklink extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'website_id',
        'competitor_domain_id',
        'target_domain',
        'is_target_site',
        'referring_domain',
        'referring_url',
        'destination_url',
        'anchor_text',
        'link_type',
        'referring_authority',
        'link_status',
        'first_seen_at',
        'last_seen_at',
        'data_source',
    ];

    protected $casts = [
        'is_target_site' => 'boolean',
        'referring_authority' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
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
