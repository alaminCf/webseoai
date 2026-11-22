<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CompetitorDomain extends Model
{
    use HasFactory;

    public const CATEGORY_DIRECT = 'direct';
    public const CATEGORY_ORGANIC = 'organic_search';
    public const CATEGORY_CONTENT = 'content';
    public const CATEGORY_INDIRECT = 'indirect';

    public const CATEGORIES = [
        self::CATEGORY_DIRECT,
        self::CATEGORY_ORGANIC,
        self::CATEGORY_CONTENT,
        self::CATEGORY_INDIRECT,
    ];

    public const TIER_PRIMARY = 'primary';
    public const TIER_SECONDARY = 'secondary';

    protected $fillable = [
        'organization_id',
        'website_id',
        'domain',
        'display_name',
        'category',
        'tier',
        'country',
        'language',
        'discovery_source',
        'discovery_confidence',
        'discovery_rationale',
        'is_active',
        'last_analyzed_at',
    ];

    protected $casts = [
        'discovery_confidence' => 'integer',
        'is_active' => 'boolean',
        'last_analyzed_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(CompetitorDomainSnapshot::class)->orderByDesc('captured_at');
    }

    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(CompetitorDomainSnapshot::class)->latestOfMany('captured_at');
    }

    public function backlinks(): HasMany
    {
        return $this->hasMany(CompetitorBacklink::class);
    }

    public static function normalizeDomain(string $raw): string
    {
        $raw = trim(strtolower($raw));
        if ($raw === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $raw)) {
            $raw = 'https://' . ltrim($raw, '/');
        }
        $host = parse_url($raw, PHP_URL_HOST) ?: '';
        $host = preg_replace('/^www\./i', '', strtolower(trim($host)));
        return rtrim($host, '.');
    }
}
