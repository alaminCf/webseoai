<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteContextProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'website_id',
        'organization_id',
        'business_name',
        'business_description',
        'target_audience',
        'products_and_services',
        'target_countries',
        'target_languages',
        'brand_voice',
        'business_goals',
        'competitors',
        'approved_at',
        'approved_by',
    ];

    protected $casts = [
        'target_countries' => 'array',
        'target_languages' => 'array',
        'business_goals' => 'array',
        'competitors' => 'array',
        'approved_at' => 'datetime',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
