<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditIssue extends Model
{
    use HasFactory;

    public $timestamps = false;

    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_INFO = 'info';

    public const CATEGORY_TECHNICAL = 'technical';
    public const CATEGORY_CONTENT = 'content';
    public const CATEGORY_LINKS = 'links';
    public const CATEGORY_INDEXING = 'indexing';

    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_IGNORED = 'ignored';

    protected $fillable = [
        'audit_run_id',
        'website_id',
        'crawled_page_id',
        'issue_type',
        'category',
        'severity',
        'affected_url',
        'evidence',
        'explanation',
        'recommended_fix',
        'first_seen_at',
        'last_seen_at',
        'current_status',
        'verification_status',
        'is_fully_determined',
        'created_at',
    ];

    protected $casts = [
        'evidence' => 'array',
        'is_fully_determined' => 'boolean',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function auditRun(): BelongsTo
    {
        return $this->belongsTo(AuditRun::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function crawledPage(): BelongsTo
    {
        return $this->belongsTo(CrawledPage::class);
    }
}
