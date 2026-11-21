<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentArticle extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_READY_FOR_PUBLISH = 'ready_for_publish';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'organization_id',
        'website_id',
        'content_brief_id',
        'created_by',
        'title',
        'slug',
        'meta_description',
        'canonical_url',
        'content_body',
        'word_count',
        'search_intent',
        'quality_score',
        'quality_notes',
        'status',
        'version',
        'reviewed_at',
        'reviewed_by',
    ];

    protected $casts = [
        'quality_notes' => 'array',
        'quality_score' => 'integer',
        'word_count' => 'integer',
        'version' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function brief(): BelongsTo
    {
        return $this->belongsTo(ContentBrief::class, 'content_brief_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ContentRevision::class)->orderBy('version', 'desc');
    }

    /**
     * Create a historical snapshot in revisions table.
     */
    public function recordRevision(?int $userId = null, ?string $changeSummary = null): ContentRevision
    {
        return ContentRevision::create([
            'content_article_id' => $this->id,
            'user_id' => $userId,
            'version' => $this->version,
            'title' => $this->title,
            'content_body' => $this->content_body,
            'change_summary' => $changeSummary ?? "Revision v{$this->version}",
        ]);
    }
}
