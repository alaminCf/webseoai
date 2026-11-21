<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuditRun extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'website_id',
        'crawl_job_id',
        'overall_score',
        'technical_score',
        'content_score',
        'link_score',
        'indexing_score',
        'critical_count',
        'warning_count',
        'info_count',
        'summary_stats',
        'created_at',
    ];

    protected $casts = [
        'summary_stats' => 'array',
        'overall_score' => 'integer',
        'technical_score' => 'integer',
        'content_score' => 'integer',
        'link_score' => 'integer',
        'indexing_score' => 'integer',
        'critical_count' => 'integer',
        'warning_count' => 'integer',
        'info_count' => 'integer',
        'created_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function crawlJob(): BelongsTo
    {
        return $this->belongsTo(CrawlJob::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(AuditIssue::class);
    }
}
