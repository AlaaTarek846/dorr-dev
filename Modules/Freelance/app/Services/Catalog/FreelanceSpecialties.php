<?php

namespace Modules\Freelance\Services\Catalog;

use Illuminate\Support\Facades\DB;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceSpecialty;

/**
 * FL-101: the specialty catalog — a specialty and its sub-specialties (two levels). The rules live here so the admin
 * screen, the apps (FL-104) and the policies (FL-008) all read the same tree.
 */
class FreelanceSpecialties
{
    /** @param  array<string, mixed>  $data  parent_id?, status?, sort_order?, translations[{locale, name, description?}] */
    public function create(array $data): FreelanceSpecialty
    {
        $parentId = $this->checkedParent($data['parent_id'] ?? null, null);
        $data['sort_order'] ??= (int) FreelanceSpecialty::query()->where('parent_id', $parentId)->max('sort_order') + 1;

        return DB::transaction(function () use ($data, $parentId) {
            $specialty = FreelanceSpecialty::query()->create([
                'parent_id' => $parentId,
                'status' => $data['status'] ?? true,
                'sort_order' => $data['sort_order'],
            ]);
            $this->saveTranslations($specialty, $data['translations'] ?? []);

            return $specialty->load('translations');
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(FreelanceSpecialty $specialty, array $data): FreelanceSpecialty
    {
        return DB::transaction(function () use ($specialty, $data) {
            if (array_key_exists('parent_id', $data)) {
                $specialty->parent_id = $this->checkedParent($data['parent_id'], $specialty);
            }
            foreach (['status', 'sort_order'] as $key) {
                if (array_key_exists($key, $data)) {
                    $specialty->{$key} = $data[$key];
                }
            }
            $specialty->save();
            $this->saveTranslations($specialty, $data['translations'] ?? []);

            return $specialty->load('translations');
        });
    }

    public function delete(FreelanceSpecialty $specialty): void
    {
        if ($specialty->children()->exists()) {
            throw new FreelanceException('specialty_has_children', 422);
        }
        if (DB::table('freelancer_specialties')->where('freelance_specialty_id', $specialty->id)->exists()) {
            throw new FreelanceException('specialty_in_use', 409);
        }
        $specialty->delete();
    }

    /**
     * Deletes the ones that can go; the ones that still have sub-specialties are skipped.
     *
     * @param  list<int>  $ids
     * @return array{deleted: int, skipped: int}
     */
    public function deleteMany(array $ids): array
    {
        $deleted = 0;
        $skipped = 0;
        // Children first, so a parent picked together with its children goes too.
        foreach (FreelanceSpecialty::query()->whereIn('id', $ids)->orderByDesc('parent_id')->get() as $specialty) {
            if ($specialty->children()->exists() || DB::table('freelancer_specialties')->where('freelance_specialty_id', $specialty->id)->exists()) {
                $skipped++;

                continue;
            }
            $specialty->delete();
            $deleted++;
        }

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }

    /** @param  list<int>  $orderedIds  the ids of one level, in the new order */
    public function reorder(array $orderedIds): void
    {
        DB::transaction(function () use ($orderedIds) {
            foreach (array_values($orderedIds) as $index => $id) {
                FreelanceSpecialty::query()->whereKey($id)->update(['sort_order' => $index + 1]);
            }
        });
    }

    /** The parent must be a top-level specialty, and never the specialty itself or one of its children. */
    private function checkedParent(mixed $parentId, ?FreelanceSpecialty $current): ?int
    {
        if (empty($parentId)) {
            return null;
        }
        $parent = FreelanceSpecialty::query()->find((int) $parentId) ?? throw new FreelanceException('specialty_not_found', 404);
        if ($parent->parent_id !== null) {
            throw new FreelanceException('specialty_two_levels', 422);
        }
        if ($current !== null && ((int) $parent->id === (int) $current->id || $current->children()->exists())) {
            throw new FreelanceException('specialty_two_levels', 422);
        }

        return (int) $parent->id;
    }

    /** @param  list<array{locale: string, name: string, description?: ?string}>  $rows */
    private function saveTranslations(FreelanceSpecialty $specialty, array $rows): void
    {
        foreach ($rows as $row) {
            $specialty->translations()->updateOrCreate(['locale' => $row['locale']], ['name' => $row['name'], 'description' => $row['description'] ?? null]);
        }
    }
}
