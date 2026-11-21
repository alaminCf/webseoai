<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchPerformanceRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'website_id',
        'property_id',
        'date',
        'page',
        'query',
        'country',
        'device',
        'clicks',
        'impressions',
        'ctr',
        'position',
    ];

    protected $casts = [
        'date' => 'date',
        'clicks' => 'integer',
        'impressions' => 'integer',
        'ctr' => 'float',
        'position' => 'float',
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
