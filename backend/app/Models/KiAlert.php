<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KiAlert extends Model
{
    use HasFactory;

    protected $table = 'ki_alerts';

    protected $fillable = [
        'organization_id',
        'website_id',
        'workflow_id',
        'keyword_id',
        'alert_type',
        'severity',
        'title',
        'message',
        'evidence',
        'is_read',
        'read_at',
    ];

    protected $casts = [
        'evidence' => 'array',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(KiAutomationWorkflow::class, 'workflow_id');
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(KiKeyword::class, 'keyword_id');
    }
}
