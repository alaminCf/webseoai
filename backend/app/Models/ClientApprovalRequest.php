<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientApprovalRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'organization_id',
        'website_id',
        'client_workspace_id',
        'requester_id',
        'approver_email',
        'title',
        'description',
        'request_type',
        'status',
        'token',
        'metadata',
        'client_comment',
        'decided_at',
        'expires_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'decided_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function clientWorkspace(): BelongsTo
    {
        return $this->belongsTo(ClientWorkspace::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function isPending(): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
