<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class PlatformApiKey extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXHAUSTED = 'exhausted';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'provider',
        'label',
        'encrypted_key',
        'key_hint',
        'priority',
        'status',
        'monthly_limit',
        'credits_used',
        'total_requests',
        'last_used_at',
        'exhausted_at',
        'last_error',
        'created_by_user_id',
    ];

    protected $hidden = [
        'encrypted_key',
    ];

    protected $casts = [
        'priority' => 'integer',
        'monthly_limit' => 'integer',
        'credits_used' => 'integer',
        'total_requests' => 'integer',
        'last_used_at' => 'datetime',
        'exhausted_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public static function encryptKey(string $rawKey): string
    {
        return Crypt::encryptString(trim($rawKey));
    }

    public function getDecryptedKey(): string
    {
        if (empty($this->encrypted_key)) {
            return '';
        }

        try {
            return trim(Crypt::decryptString($this->encrypted_key));
        } catch (Throwable) {
            return '';
        }
    }

    public function markExhausted(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_EXHAUSTED,
            'exhausted_at' => now(),
            'last_error' => mb_substr($reason, 0, 500),
        ]);
    }

    public function markError(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_ERROR,
            'last_error' => mb_substr($reason, 0, 500),
        ]);
    }

    public function recordUsage(int $credits): void
    {
        $this->increment('credits_used', max(0, $credits));
        $this->increment('total_requests', 1);
        $this->update([
            'last_used_at' => now(),
            'last_error' => null,
        ]);
    }

    public function toAdminArray(): array
    {
        $remaining = max(0, $this->monthly_limit - $this->credits_used);
        $usagePct = $this->monthly_limit > 0
            ? min(100, round(($this->credits_used / $this->monthly_limit) * 100, 1))
            : 0;

        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'label' => $this->label,
            'key_hint' => $this->key_hint,
            'priority' => $this->priority,
            'status' => $this->status,
            'monthly_limit' => $this->monthly_limit,
            'credits_used' => $this->credits_used,
            'credits_remaining' => $remaining,
            'usage_pct' => $usagePct,
            'total_requests' => $this->total_requests,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'exhausted_at' => $this->exhausted_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
