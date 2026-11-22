<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompetitorProviderLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'website_id',
        'provider',
        'capability',
        'endpoint',
        'status',
        'http_status',
        'duration_ms',
        'estimated_cost_usd',
        'error_message',
        'created_at',
    ];

    protected $casts = [
        'http_status' => 'integer',
        'duration_ms' => 'integer',
        'estimated_cost_usd' => 'float',
        'created_at' => 'datetime',
    ];
}
