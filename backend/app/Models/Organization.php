<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'billing_plan',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function websites(): HasMany
    {
        return $this->hasMany(Website::class);
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(WebsiteIntegration::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function clientWorkspaces(): HasMany
    {
        return $this->hasMany(ClientWorkspace::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function subscription(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function clientApprovalRequests(): HasMany
    {
        return $this->hasMany(ClientApprovalRequest::class);
    }

    public function getEffectivePlan(): array
    {
        $planId = $this->subscription?->plan_id ?? $this->billing_plan ?? 'starter';
        $plans = config('saas.plans', []);
        return $plans[$planId] ?? $plans['starter'] ?? [];
    }

    public function getWhiteLabelSettings(): array
    {
        $settings = $this->settings ?? [];
        return $settings['white_label'] ?? [
            'brand_name' => $this->name,
            'logo_url' => null,
            'primary_color' => '#10b981', // emerald
            'contact_email' => null,
            'footer_text' => 'Confidential SEO Strategy & Technical Audit Report',
            'remove_platform_branding' => false,
        ];
    }
}
