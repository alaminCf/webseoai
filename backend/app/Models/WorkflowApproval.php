<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowApproval extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'organization_id',
        'website_id',
        'workflow_run_id',
        'cms_change_request_id',
        'title',
        'description',
        'risk_level',
        'proposed_diff',
        'status',
        'reviewed_by',
        'review_notes',
        'reviewed_at',
        'expires_at',
    ];

    protected $casts = [
        'proposed_diff' => 'array',
        'reviewed_at' => 'datetime',
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

    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class);
    }

    public function cmsChangeRequest(): BelongsTo
    {
        return $this->belongsTo(CmsChangeRequest::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
