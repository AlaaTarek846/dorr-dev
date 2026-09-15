<?php

namespace App\Models;

use App\Enums\VerificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class VerificationCode extends Model
{
    protected $fillable = [
        'authenticatable_type',
        'authenticatable_id',
        'type',
        'code',
        'expires_at',
        'verified_at',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'type' => VerificationType::class,
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
