<?php

namespace Modules\User\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A ready answer for support agents: typing "/" + its shortcut in a ticket's reply box drops the text in.
 * The title and the text are translated; the agent can still edit the text before sending — it always
 * goes out in that agent's name, never as an automatic reply.
 */
class SupportQuickReply extends Model
{
    use HasTranslations;

    protected $fillable = ['shortcut', 'sort_order', 'status'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'status' => 'boolean',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(SupportQuickReplyTranslation::class);
    }

    protected function translationModel(): string
    {
        return SupportQuickReplyTranslation::class;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }
}
