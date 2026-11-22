<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitorDiscoveryRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'website_id',
        'initiated_by',
        'country',
        'countries',
        'language',
        'search_engine',
        'industry_context',
        'provider',
        'methodology',
        'status',
        'candidates',
        'error_message',
        'completed_at',
    ];

    protected $casts = [
        'countries' => 'array',
        'candidates' => 'array',
        'completed_at' => 'datetime',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
