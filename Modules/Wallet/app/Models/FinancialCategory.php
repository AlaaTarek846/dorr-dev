<?php

namespace Modules\Wallet\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use Modules\Wallet\Enums\FinancialEntryType;

/**
 * `slug` (not the translated name) is what code refers to — see
 * Modules\Wallet\Services\FinancialLedgerService. Seeded categories
 * (`is_system = true`) can't be deleted or have their slug changed; both are
 * enforced below, not just by convention.
 */
class FinancialCategory extends Model
{
    use HasTranslations, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = ['slug', 'type', 'is_system', 'status'];

    protected function casts(): array
    {
        return [
            'type' => FinancialEntryType::class,
            'is_system' => 'boolean',
            'status' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $category) {
            if ($category->isDirty('slug') && $category->getOriginal('is_system')) {
                throw new LogicException("financial_categories #{$category->id}: is_system categories can't have their slug changed.");
            }
        });

        static::deleting(function (self $category) {
            if ($category->is_system) {
                throw new LogicException("financial_categories #{$category->id} ('{$category->slug}') is a system category and can't be deleted.");
            }
        });
    }

    public function translations(): HasMany
    {
        return $this->hasMany(FinancialCategoryTranslation::class);
    }

    protected function translationModel(): string
    {
        return FinancialCategoryTranslation::class;
    }

    public function entries(): HasMany
    {
        return $this->hasMany(FinancialEntry::class, 'category_id');
    }
}
