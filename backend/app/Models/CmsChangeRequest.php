<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CmsChangeRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXECUTING = 'executing';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ROLLED_BACK = 'rolled_back';
    public const STATUS_UNCERTAIN = 'uncertain';

    public const RISK_LOW = 'low';
    public const RISK_MEDIUM = 'medium';
    public const RISK_HIGH = 'high';

    public const ACTION_UPDATE_METADATA = 'update_metadata';
    public const ACTION_UPDATE_CONTENT = 'update_content';
    public const ACTION_CREATE_DRAFT = 'create_draft';
    public const ACTION_RESTORE_SNAPSHOT = 'restore_snapshot';

    protected $fillable = [
        'organization_id',
        'website_id',
        'user_id',
        'approved_by',
        'idempotency_key',
        'cms_provider',
        'action_type',
        'target_type',
        'target_identifier',
        'target_url',
        'proposed_payload',
        'status',
        'risk_level',
        'rejection_reason',
        'pre_change_snapshot_id',
        'post_change_snapshot_id',
        'diff_summary',
        'execution_error',
        'verification_details',
        'approved_at',
        'executed_at',
        'verified_at',
        'rolled_back_at',
    ];

    protected $casts = [
        'proposed_payload' => 'array',
        'diff_summary' => 'array',
        'verification_details' => 'array',
        'approved_at' => 'datetime',
        'executed_at' => 'datetime',
        'verified_at' => 'datetime',
        'rolled_back_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function preChangeSnapshot(): BelongsTo
    {
        return $this->belongsTo(CmsSnapshot::class, 'pre_change_snapshot_id');
    }

    public function postChangeSnapshot(): BelongsTo
    {
        return $this->belongsTo(CmsSnapshot::class, 'post_change_snapshot_id');
    }
}
