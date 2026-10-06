<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One paid period: a portal's listing or a channel's verification, from `starts_at` to `ends_at`.
 * A renewal while one runs starts where it ends.
 */
class ChatSubscription extends Model
{
    protected $fillable = [
        'kind', 'subject_type', 'subject_id', 'chat_package_id', 'checkout_id',
        'owner_type', 'owner_id', 'amount_minor', 'currency_id', 'starts_at', 'ends_at',
    ];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(ChatPackage::class, 'chat_package_id');
    }
}
