<?php

namespace App\Models\Concerns;

use App\Models\VerificationCode;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasVerificationCodes
{
    public function verificationCodes(): MorphMany
    {
        return $this->morphMany(VerificationCode::class, 'authenticatable');
    }
}
