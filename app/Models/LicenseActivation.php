<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseActivation extends Model
{
    protected $fillable = [
        'user_id',
        'organization_id',
        'provider',
        'exchange_code_hash',
        'provider_purchase_id',
        'provider_customer_email',
        'plan_code',
        'status',
        'activated_at',
        'expires_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'activated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
