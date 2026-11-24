<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlobalSerpCache extends Model
{
    protected $fillable = [
        'cache_hash',
        'provider',
        'keyword',
        'country',
        'language',
        'device',
        'location',
        'depth',
        'payload',
        'pages_fetched',
        'hit_count',
        'credits_saved',
        'expires_at',
    ];

    protected $casts = [
        'depth' => 'integer',
        'pages_fetched' => 'integer',
        'hit_count' => 'integer',
        'credits_saved' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function getDecodedPayload(): array
    {
        $decoded = json_decode((string) $this->payload, true);
        return is_array($decoded) ? $decoded : [];
    }
}
