<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsitePermission extends Model
{
    use HasFactory;

    protected $fillable = [
        'website_id',
        'user_id',
        'can_view',
        'can_edit',
        'can_publish',
        'can_approve',
    ];

    protected $casts = [
        'can_view' => 'boolean',
        'can_edit' => 'boolean',
        'can_publish' => 'boolean',
        'can_approve' => 'boolean',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
