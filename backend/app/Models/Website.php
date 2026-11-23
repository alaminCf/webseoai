<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'client_workspace_id',
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
        'tracking_token',
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
            if (empty($website->tracking_token)) {
                $website->tracking_token = 'wsa_' . Str::random(28);
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

    public function contextProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(WebsiteContextProfile::class);
    }

    public function aiRecommendations(): HasMany
    {
        return $this->hasMany(AiRecommendation::class);
    }

    public function contentBriefs(): HasMany
    {
        return $this->hasMany(ContentBrief::class);
    }

    public function contentArticles(): HasMany
    {
        return $this->hasMany(ContentArticle::class);
    }

    public function searchSyncLogs(): HasMany
    {
        return $this->hasMany(SearchConsoleSyncLog::class);
    }

    public function searchPerformanceRecords(): HasMany
    {
        return $this->hasMany(SearchPerformanceRecord::class);
    }

    public function searchOpportunities(): HasMany
    {
        return $this->hasMany(SearchOpportunity::class);
    }

    public function keywordRecords(): HasMany
    {
        return $this->hasMany(KeywordResearchRecord::class);
    }

    public function gscIntegration(): HasOne
    {
        return $this->hasOne(WebsiteIntegration::class)->where('provider', WebsiteIntegration::PROVIDER_GSC);
    }

    public function cmsSnapshots(): HasMany
    {
        return $this->hasMany(CmsSnapshot::class);
    }

    public function cmsChangeRequests(): HasMany
    {
        return $this->hasMany(CmsChangeRequest::class);
    }

    public function automationRules(): HasMany
    {
        return $this->hasMany(AutomationRule::class);
    }

    public function workflowRuns(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }

    public function workflowApprovals(): HasMany
    {
        return $this->hasMany(WorkflowApproval::class);
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
     * Guarantee a persistent verification token exists on the website record.
     */
    public function ensureVerificationToken(): string
    {
        if (empty($this->verification_token)) {
            $this->verification_token = 'web-seo-ai-' . Str::random(32);
            $this->save();
        }

        return $this->verification_token;
    }

    public function ensureTrackingToken(): string
    {
        if (empty($this->tracking_token)) {
            $this->tracking_token = 'wsa_' . Str::random(28);
            $this->save();
        }

        return $this->tracking_token;
    }

    public function trafficEvents(): HasMany
    {
        return $this->hasMany(WebsiteTrafficEvent::class);
    }

    /**
     * Check whether a Google Search Console siteUrl corresponds to this website's domain.
     */
    public function matchesGscProperty(string $siteUrl): bool
    {
        $siteUrl = strtolower(trim($siteUrl));
        if ($siteUrl === '') {
            return false;
        }

        $targetDomain = preg_replace('/^www\./i', '', strtolower(trim($this->domain)));
        if ($targetDomain === '') {
            return false;
        }

        if (str_starts_with($siteUrl, 'sc-domain:')) {
            $propDomain = preg_replace('/^www\./i', '', substr($siteUrl, strlen('sc-domain:')));
            return $propDomain === $targetDomain;
        }

        $host = parse_url($siteUrl, PHP_URL_HOST);
        if (!$host) {
            return false;
        }

        $propDomain = preg_replace('/^www\./i', '', strtolower($host));
        return $propDomain === $targetDomain;
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

    public function clientWorkspace(): BelongsTo
    {
        return $this->belongsTo(ClientWorkspace::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(WebsitePermission::class);
    }

    public function clientApprovalRequests(): HasMany
    {
        return $this->hasMany(ClientApprovalRequest::class);
    }

    public function userCan(User $user, string $action): bool
    {
        // 1. Owners and Admins of the organization always have full access
        $membership = OrganizationMember::where('organization_id', $this->organization_id)
            ->where('user_id', $user->id)
            ->first();

        if (!$membership) {
            return false;
        }

        if ($membership->isAdmin()) {
            return true;
        }

        // 2. Check granular website permission if defined
        $perm = $this->permissions()->where('user_id', $user->id)->first();
        if ($perm) {
            return match ($action) {
                'view' => $perm->can_view,
                'edit' => $perm->can_edit,
                'publish' => $perm->can_publish,
                'approve' => $perm->can_approve,
                default => false,
            };
        }

        // 3. Fallback to organization role defaults
        return match ($action) {
            'view' => true,
            'edit' => in_array($membership->role, [OrganizationMember::ROLE_SEO_MANAGER, OrganizationMember::ROLE_EDITOR], true),
            'publish', 'approve' => $membership->role === OrganizationMember::ROLE_SEO_MANAGER,
            default => false,
        };
    }
}
