<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KiCannibalizationFinding extends Model
{
    use HasFactory;

    protected $table = 'ki_cannibalization_findings';

    protected $fillable = [
        'organization_id',
        'website_id',
        'keyword',
        'competing_urls',
        'total_impressions',
        'total_clicks',
        'best_position',
        'position_spread',
        'confidence',
        'evidence_summary',
        'suggested_resolution',
        'status',
        'detected_at',
    ];

    protected $casts = [
        'competing_urls' => 'array',
        'total_impressions' => 'integer',
        'total_clicks' => 'integer',
        'best_position' => 'float',
        'position_spread' => 'float',
        'detected_at' => 'datetime',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
