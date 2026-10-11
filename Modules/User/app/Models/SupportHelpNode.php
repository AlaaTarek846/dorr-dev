<?php

namespace Modules\User\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One topic of the app's guided help menu. A topic with sub-topics is a menu (its answer, when it has one, is
 * said before the options); a topic without any is an answer — the customer then has to pick "solved" or
 * "I need an agent". Title and answer are translated.
 */
class SupportHelpNode extends Model
{
    use HasTranslations;

    /** The deepest level a topic can sit at (the main menu is level 1). */
    public const MAX_DEPTH = 6;

    protected $fillable = ['parent_id', 'sort_order', 'status'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'status' => 'boolean',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(SupportHelpNodeTranslation::class);
    }

    protected function translationModel(): string
    {
        return SupportHelpNodeTranslation::class;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }

    /** 1 for a main-menu topic, 2 for its sub-topic… */
    public function depth(): int
    {
        $depth = 1;
        $node = $this;

        while ($node->parent_id !== null) {
            $node = $node->parent()->first();
            $depth++;
        }

        return $depth;
    }
}
