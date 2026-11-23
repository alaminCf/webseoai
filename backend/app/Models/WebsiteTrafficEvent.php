<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteTrafficEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'website_id',
        'visitor_hash',
        'session_id',
        'event_type',
        'is_test',
        'path',
        'page_title',
        'referrer_domain',
        'referrer_url',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'country_code',
        'country_name',
        'region',
        'city',
        'device_type',
        'browser',
        'os',
        'occurred_at',
        'created_at',
    ];

    protected $casts = [
        'is_test' => 'boolean',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
