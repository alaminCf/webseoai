<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_TRIALING = 'trialing';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_INCOMPLETE = 'incomplete';

    protected $fillable = [
        'organization_id',
        'stripe_customer_id',
        'stripe_subscription_id',
        'plan_id',
        'status',
        'billing_cycle',
        'current_period_start',
        'current_period_end',
        'cancel_at_period_end',
        'canceled_at',
        'trial_ends_at',
        'card_brand',
        'card_last_four',
        'extra_data',
    ];

    protected $casts = [
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'cancel_at_period_end' => 'boolean',
        'canceled_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'extra_data' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_TRIALING], true);
    }

    public function isPastDue(): bool
    {
        return $this->status === self::STATUS_PAST_DUE;
    }

    public function getPlanDetails(): array
    {
        $plans = config('saas.plans', []);
        return $plans[$this->plan_id] ?? $plans['starter'] ?? [];
    }
}
