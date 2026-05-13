<?php

namespace App\Models;

use App\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Media extends Model
{
    use BelongsToOrganization;

    protected $table = 'media';

    protected $fillable = [
        'organization_id',
        'user_id',
        'original_name',
        'file_name',
        'file_path',
        'file_type',
        'mime_type',
        'size',
        'hash',
        'disk',
        'visibility',
        'title',
        'alternate_text',
        'caption',
        'description',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getUrlAttribute(): string
    {
        return '/file/' . $this->hash;
    }
}
