<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrawledPage extends Model
{
    use HasFactory;

    protected $fillable = [
        'crawl_job_id',
        'website_id',
        'url',
        'final_url',
        'url_hash',
        'depth',
        'status_code',
        'load_time_ms',
        'ttfb_ms',
        'html_size_bytes',
        'content_type',
        'page_title',
        'meta_description',
        'canonical_url',
        'viewport',
        'html_lang',
        'charset',
        'favicon_url',
        'open_graph',
        'twitter_card',
        'structured_data',
        'hreflang_tags',
        'h1_tags',
        'headings_tree',
        'meta_robots',
        'x_robots_tag',
        'meta_refresh',
        'is_indexable',
        'in_sitemap',
        'is_js_rendered',
        'is_spa_shell',
        'is_blocked_by_waf',
        'word_count',
        'content_hash',
        'images_count',
        'images_missing_alt',
        'images_without_dimensions',
        'images_missing_alt_samples',
        'internal_links',
        'external_links',
        'in_degree',
        'redirect_url',
        'redirect_hops',
        'raw_headers',
        'security_headers',
        'mixed_content_urls',
        'error',
    ];

    protected $casts = [
        'h1_tags' => 'array',
        'headings_tree' => 'array',
        'internal_links' => 'array',
        'external_links' => 'array',
        'redirect_hops' => 'array',
        'raw_headers' => 'array',
        'open_graph' => 'array',
        'twitter_card' => 'array',
        'structured_data' => 'array',
        'hreflang_tags' => 'array',
        'security_headers' => 'array',
        'mixed_content_urls' => 'array',
        'images_missing_alt_samples' => 'array',
        'is_indexable' => 'boolean',
        'in_sitemap' => 'boolean',
        'is_js_rendered' => 'boolean',
        'is_spa_shell' => 'boolean',
        'is_blocked_by_waf' => 'boolean',
        'depth' => 'integer',
        'status_code' => 'integer',
        'load_time_ms' => 'integer',
        'ttfb_ms' => 'integer',
        'html_size_bytes' => 'integer',
        'word_count' => 'integer',
        'images_count' => 'integer',
        'images_missing_alt' => 'integer',
        'images_without_dimensions' => 'integer',
        'in_degree' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (CrawledPage $page) {
            if (empty($page->url_hash) && !empty($page->url)) {
                $page->url_hash = hash('sha256', $page->url);
            }
        });
    }

    public function crawlJob(): BelongsTo
    {
        return $this->belongsTo(CrawlJob::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(AuditIssue::class);
    }
}
