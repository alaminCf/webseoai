<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRecommendation extends Model
{
    use HasFactory;

    public const STATUS_PROPOSED = 'proposed';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'organization_id',
        'website_id',
        'audit_run_id',
        'audit_issue_id',
        'crawled_page_id',
        'title',
        'category',
        'affected_url',
        'evidence',
        'recommended_action',
        'reasoning_summary',
        'expected_benefit',
        'confidence_level',
        'effort',
        'risk_level',
        'required_permissions',
        'verification_procedure',
        'impact_score',
        'confidence_score',
        'effort_score',
        'risk_score',
        'priority_score',
        'status',
        'assigned_to',
    ];

    protected $casts = [
        'evidence' => 'array',
        'impact_score' => 'integer',
        'confidence_score' => 'integer',
        'effort_score' => 'integer',
        'risk_score' => 'integer',
        'priority_score' => 'float',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function auditRun(): BelongsTo
    {
        return $this->belongsTo(AuditRun::class);
    }

    public function auditIssue(): BelongsTo
    {
        return $this->belongsTo(AuditIssue::class);
    }

    public function crawledPage(): BelongsTo
    {
        return $this->belongsTo(CrawledPage::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Calculate priority score using normalized ICE + Risk formula.
     * Score = (Impact * Confidence) / (Effort + Risk * 0.5)
     */
    public static function calculatePriority(int $impact, int $confidence, int $effort, int $risk): float
    {
        $impact = max(1, min(10, $impact));
        $confidence = max(1, min(10, $confidence));
        $effort = max(1, min(10, $effort));
        $risk = max(1, min(10, $risk));

        $denominator = $effort + ($risk * 0.5);
        $rawScore = ($impact * $confidence) / max(1.0, $denominator);

        // Normalize to 0 - 100 scale (max possible raw is 100 / 1.5 = 66.67)
        $normalized = ($rawScore / 66.67) * 100.0;
        return round(min(100.0, max(1.0, $normalized)), 2);
    }
}
