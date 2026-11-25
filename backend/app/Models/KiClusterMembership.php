<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KiClusterMembership extends Model
{
    protected $fillable = [
        'cluster_id',
        'keyword_id',
        'is_primary',
        'similarity_score',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'similarity_score' => 'float',
    ];

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(KiCluster::class, 'cluster_id');
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(KiKeyword::class, 'keyword_id');
    }
}
