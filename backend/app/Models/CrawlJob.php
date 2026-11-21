<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CrawlJob extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_CRAWLING = 'crawling';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'organization_id',
        'website_id',
        'initiated_by',
        'status',
        'max_pages',
        'max_depth',
        'concurrency',
        'pages_discovered',
        'pages_crawled',
        'pages_failed',
        'started_at',
        'finished_at',
        'error_message',
        'config',
        'site_checks',
        'execution_logs',
    ];

    protected $casts = [
        'config' => 'array',
        'site_checks' => 'array',
        'execution_logs' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'max_pages' => 'integer',
        'max_depth' => 'integer',
        'concurrency' => 'integer',
        'pages_discovered' => 'integer',
        'pages_crawled' => 'integer',
        'pages_failed' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(CrawledPage::class);
    }

    public function auditRun(): HasOne
    {
        return $this->hasOne(AuditRun::class);
    }

    public function logStep(string $message, array $context = []): void
    {
        $logs = $this->execution_logs ?? [];
        $logs[] = [
            'timestamp' => now()->toIso8601String(),
            'step' => $message,
            'message' => $message,
            'context' => $context,
        ];
        $this->update(['execution_logs' => $logs]);
    }
}
