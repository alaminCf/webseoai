<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CmsSnapshot extends Model
{
    use HasFactory;

    public const TYPE_PRE_CHANGE = 'pre_change';
    public const TYPE_POST_CHANGE = 'post_change';
    public const TYPE_MANUAL_BACKUP = 'manual_backup';
    public const TYPE_ROLLBACK_POINT = 'rollback_point';

    protected $fillable = [
        'organization_id',
        'website_id',
        'cms_provider',
        'target_type',
        'target_identifier',
        'snapshot_type',
        'state_payload',
        'checksum',
        'remote_modified_at',
        'created_by',
    ];

    protected $casts = [
        'state_payload' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Compute SHA-256 checksum of payload for conflict detection and integrity verification.
     */
    public static function computeChecksum(array $payload): string
    {
        ksort($payload);
        return hash('sha256', json_encode($payload));
    }
}
