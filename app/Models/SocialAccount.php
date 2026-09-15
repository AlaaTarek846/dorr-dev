<?php

namespace App\Models;

use App\Enums\SocialProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SocialAccount extends Model
{
    protected $fillable = [
        'authenticatable_type',
        'authenticatable_id',
        'provider',
        'provider_id',
        'email',
    ];

    protected function casts(): array
    {
        return [
            'provider' => SocialProvider::class,
        ];
    }

    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }
}
