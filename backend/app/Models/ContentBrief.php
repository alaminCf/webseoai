<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentBrief extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'organization_id',
        'website_id',
        'created_by',
        'target_keyword',
        'secondary_keywords',
        'search_intent',
        'target_audience',
        'content_type',
        'suggested_title',
        'suggested_meta_description',
        'word_count_target',
        'outline',
        'internal_link_suggestions',
        'faq_items',
        'status',
        'approved_at',
        'approved_by',
    ];

    protected $casts = [
        'secondary_keywords' => 'array',
        'outline' => 'array',
        'internal_link_suggestions' => 'array',
        'faq_items' => 'array',
        'word_count_target' => 'integer',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function articles(): HasMany
    {
        return $this->hasMany(ContentArticle::class);
    }
}
