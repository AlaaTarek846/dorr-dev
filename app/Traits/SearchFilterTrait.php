<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait SearchFilterTrait
{
    /**
     * Default translatable columns (flag_translations, country_translations, ...).
     *
     * @var list<string>
     */
    protected array $translationSearchColumns = ['name'];

    /**
     * Translation relation name.
     */
    protected string $translationRelation = 'translations';

    public function scopeSearchAndFilter(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $q) {
                $search = $this->decodeSearchPayload();

                $q->when($searchKey = $search?->searchKey ?? null, function (Builder $q) use ($search, $searchKey) {
                    $this->applyColumnSearch($q, $search?->columns ?? [], $searchKey);

                    if ($search?->searchInTranslations ?? false) {
                        $q->orWhereHas($this->translationRelation, function (Builder $q) use ($searchKey, $search) {
                            $this->applyTranslationSearch($q, $searchKey, $search);
                        });
                    }

                    if ($search?->searchInRelations ?? false) {
                        foreach ($search->searchInRelations ?? [] as $relation) {
                            $q->orWhereHas($relation->relation, function (Builder $q) use ($searchKey, $relation) {
                                $this->applyColumnSearch($q, $relation->columns ?? [], $searchKey);

                                if ($relation->searchInRelationTranslations ?? false) {
                                    $q->orWhereHas('translations', function (Builder $q) use ($searchKey, $relation) {
                                        $this->applyTranslationSearch($q, $searchKey, $relation);
                                    });
                                }
                            });
                        }
                    }
                });

                if (request()->filled('service_type')) {
                    $q->where('service_type', request('service_type'));
                }
            })
            ->where(function (Builder $q) {
                $q->when(request()->filterColumns, function (Builder $q) {
                    foreach (request()->filterColumns['columns'] ?? [] as $row) {
                        if (! isset($row['value'])) {
                            continue;
                        }

                        match ($row['searchType'] ?? 'where') {
                            'whereIn' => $q->whereIn($row['column'], $row['value']),
                            'date' => $q->whereDate($row['column'], $row['opreator'], $row['value']),
                            'whereRelation' => $q->whereRelation(
                                $row['relation_name'],
                                $row['column'],
                                $row['opreator'],
                                $row['value'],
                            ),
                            default => $q->where($row['column'], $row['opreator'], $row['value']),
                        };
                    }
                });
            });
    }

    protected function decodeSearchPayload(): ?object
    {
        $search = request()->search;

        if (is_array($search)) {
            return (object) $search;
        }

        if (is_string($search) && $search !== '') {
            return json_decode($search);
        }

        return null;
    }

    /**
     * @param  list<string>  $columns
     */
    protected function applyColumnSearch(Builder $query, array $columns, string $searchKey): void
    {
        foreach ($columns as $index => $column) {
            if ($index === 0) {
                $query->where($column, 'LIKE', "%{$searchKey}%");
            } else {
                $query->orWhere($column, 'LIKE', "%{$searchKey}%");
            }
        }
    }

    /**
     * Search inside *_translations tables: locale + name.
     */
    protected function applyTranslationSearch(Builder $query, string $searchKey, ?object $config = null): void
    {
        $columns = $config->translationColumns ?? $this->translationSearchColumns;
        $locale = $config->locale ?? null;
        $useAppLocale = $config->filterTranslationByLocale ?? true;

        if ($locale === null && $useAppLocale) {
            $locale = app()->getLocale();
        }

        if ($locale) {
            $query->where('locale', $locale);
        }

        $query->where(function (Builder $q) use ($columns, $searchKey) {
            foreach ($columns as $index => $column) {
                if ($index === 0) {
                    $q->where($column, 'LIKE', "%{$searchKey}%");
                } else {
                    $q->orWhere($column, 'LIKE', "%{$searchKey}%");
                }
            }
        });
    }
}
