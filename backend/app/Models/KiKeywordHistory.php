<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KiKeywordHistory extends Model
{
    protected $table = 'ki_keyword_history';

    protected $fillable = [
        'organization_id',
        'website_id',
        'keyword_id',
        'search_volume',
        'keyword_difficulty',
        'cpc',
        'position',
        'ranking_url',
        'gsc_clicks',
        'gsc_impressions',
        'data_source',
        'captured_at',
    ];

    protected $casts = [
        'search_volume' => 'integer',
        'keyword_difficulty' => 'float',
        'cpc' => 'float',
        'position' => 'float',
        'gsc_clicks' => 'integer',
        'gsc_impressions' => 'integer',
        'captured_at' => 'datetime',
    ];

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(KiKeyword::class, 'keyword_id');
    }
}
