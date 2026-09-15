<?php

namespace App\Models\Concerns;

use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasSocialAccounts
{
    public function socialAccounts(): MorphMany
    {
        return $this->morphMany(SocialAccount::class, 'authenticatable');
    }
}
