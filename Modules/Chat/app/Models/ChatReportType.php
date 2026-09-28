<?php

namespace Modules\Chat\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reason someone can pick when reporting a chat (spam, harassment…), named per language.
 */
class ChatReportType extends Model
{
    use HasTranslations;

    protected $fillable = ['sort_order', 'status'];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ChatReportTypeTranslation::class);
    }

    protected function translationModel(): string
    {
        return ChatReportTypeTranslation::class;
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ChatReport::class, 'report_type_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }
}
