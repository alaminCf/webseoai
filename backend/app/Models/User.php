<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'google_id',
        'avatar_url',
        'job_title',
        'phone',
        'bio',
        'auth_provider',
        'is_super_admin',
        'status',
        'email_verified_at',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * Organizations the user belongs to.
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Organization membership records.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    /**
     * Get the role for a specific organization.
     */
    public function roleInOrganization(Organization|int $organization): ?string
    {
        $orgId = $organization instanceof Organization ? $organization->id : $organization;
        $membership = $this->memberships()->where('organization_id', $orgId)->first();
        return $membership?->role;
    }

    public function isSuspended(): bool
    {
        return ($this->status ?? 'active') === 'suspended';
    }

    /**
     * Platform Super Admin: explicitly flagged OR owner/admin of an agency_internal organization.
     */
    public function isSuperAdmin(): bool
    {
        if ((bool) $this->is_super_admin) {
            return true;
        }

        return $this->memberships()
            ->whereIn('role', [OrganizationMember::ROLE_OWNER, OrganizationMember::ROLE_ADMIN])
            ->whereHas('organization', fn ($q) => $q->where('billing_plan', 'agency_internal'))
            ->exists();
    }

    /**
     * Standardized API profile payload for auth and profile endpoints.
     */
    public function toProfileArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatar_url,
            'job_title' => $this->job_title,
            'phone' => $this->phone,
            'bio' => $this->bio,
            'auth_provider' => $this->auth_provider ?: ($this->google_id ? 'google' : 'email'),
            'google_connected' => !empty($this->google_id),
            'is_super_admin' => $this->isSuperAdmin(),
            'status' => $this->status ?? 'active',
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
