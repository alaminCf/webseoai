<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationMember extends Model
{
    use HasFactory;

    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_SEO_MANAGER = 'seo_manager';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_VIEWER = 'viewer';

    public const ROLES = [
        self::ROLE_OWNER,
        self::ROLE_ADMIN,
        self::ROLE_SEO_MANAGER,
        self::ROLE_EDITOR,
        self::ROLE_VIEWER,
    ];

    /**
     * Numeric privilege hierarchy:
     * Owner: 50, Admin: 40, SEO Manager: 30, Editor: 20, Viewer: 10
     */
    public const ROLE_HIERARCHY = [
        self::ROLE_OWNER => 50,
        self::ROLE_ADMIN => 40,
        self::ROLE_SEO_MANAGER => 30,
        self::ROLE_EDITOR => 20,
        self::ROLE_VIEWER => 10,
    ];

    protected $fillable = [
        'organization_id',
        'user_id',
        'role',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if member has at least the required role privilege.
     */
    public function hasMinimumRole(string $minimumRole): bool
    {
        $currentWeight = self::ROLE_HIERARCHY[$this->role] ?? 0;
        $requiredWeight = self::ROLE_HIERARCHY[$minimumRole] ?? 999;
        return $currentWeight >= $requiredWeight;
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, [self::ROLE_OWNER, self::ROLE_ADMIN], true);
    }

    public function canManageWebsites(): bool
    {
        return $this->hasMinimumRole(self::ROLE_EDITOR);
    }

    public function canDeleteWebsites(): bool
    {
        return $this->hasMinimumRole(self::ROLE_ADMIN);
    }

    public function canManageIntegrations(): bool
    {
        return $this->hasMinimumRole(self::ROLE_SEO_MANAGER);
    }
}
