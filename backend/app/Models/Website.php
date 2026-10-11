<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Website extends Model
{
    use HasFactory;

    public const PLATFORM_WORDPRESS = 'wordpress';
    public const PLATFORM_SHOPIFY = 'shopify';
    public const PLATFORM_CUSTOM = 'custom';

    public const PLATFORMS = [
        self::PLATFORM_WORDPRESS,
        self::PLATFORM_SHOPIFY,
        self::PLATFORM_CUSTOM,
    ];

    public const STATUS_CONNECTED = 'connected';
    public const STATUS_DISCONNECTED = 'disconnected';
    public const STATUS_PENDING = 'pending';
    public const STATUS_FAILED = 'failed';

    public const VERIFICATION_UNVERIFIED = 'unverified';
    public const VERIFICATION_PENDING = 'pending';
    public const VERIFICATION_VERIFIED = 'verified';
    public const VERIFICATION_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'name',
        'domain',
        'target_url',
        'platform',
        'timezone',
        'preferred_language',
        'target_country',
        'connection_status',
        'verification_status',
        'verification_token',
        'verification_method',
        'verified_at',
        'last_crawled_at',
        'last_audited_at',
        'health_score',
        'settings',
        'is_archived',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_archived' => 'boolean',
        'verified_at' => 'datetime',
        'last_crawled_at' => 'datetime',
        'last_audited_at' => 'datetime',
        'health_score' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Website $website) {
            if (empty($website->verification_token)) {
                $website->verification_token = 'web-seo-ai-' . Str::random(32);
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(WebsiteIntegration::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('is_archived', true);
    }

    public function isVerified(): bool
    {
        return $this->verification_status === self::VERIFICATION_VERIFIED;
    }

    /**
     * Ownership verification verification snippet helpers
     */
    public function getMetaTagSnippetAttribute(): string
    {
        return sprintf('<meta name="web-seo-ai-verification" content="%s" />', $this->verification_token);
    }

    public function getDnsTxtSnippetAttribute(): string
    {
        return sprintf('web-seo-ai-site-verification=%s', $this->verification_token);
    }

    public function getFileTokenSnippetAttribute(): array
    {
        return [
            'filename' => sprintf('web-seo-ai-%s.txt', substr($this->verification_token, -12)),
            'content' => $this->verification_token,
        ];
    }
}
