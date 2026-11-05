<?php

namespace Modules\Freelance\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** FS-105: a ready-made checklist for a kind of project; its checks are one per line in each language. */
class FreelanceChecklistTemplate extends Model
{
    use HasTranslations, SoftDeletes;

    protected $fillable = ['code', 'specialty_id', 'status', 'sort_order'];

    protected function casts(): array
    {
        return ['status' => 'boolean', 'sort_order' => 'integer'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(FreelanceChecklistTemplateTranslation::class);
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(FreelanceSpecialty::class, 'specialty_id');
    }

    protected function translationModel(): string
    {
        return FreelanceChecklistTemplateTranslation::class;
    }

    /** @return list<string> the checks in the current language (the other one when this one is missing) */
    public function checks(?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $row = $this->translations->firstWhere('locale', $locale) ?? $this->translations->firstWhere('locale', 'en') ?? $this->translations->first();

        return $row === null ? [] : self::split((string) $row->items);
    }

    /** @return list<string> */
    public static function split(string $items): array
    {
        return collect(preg_split('/\R/u', $items) ?: [])->map(fn ($t) => trim((string) $t))->filter()->values()->all();
    }
}