<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentRevision extends Model
{
    use HasFactory;

    protected $fillable = [
        'content_article_id',
        'user_id',
        'version',
        'title',
        'content_body',
        'change_summary',
    ];

    protected $casts = [
        'version' => 'integer',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(ContentArticle::class, 'content_article_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
