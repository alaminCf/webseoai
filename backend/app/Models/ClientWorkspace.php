<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientWorkspace extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'company_name',
        'client_email',
        'contact_person',
        'notes',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function websites(): HasMany
    {
        return $this->hasMany(Website::class);
    }

    public function approvalRequests(): HasMany
    {
        return $this->hasMany(ClientApprovalRequest::class);
    }
}
