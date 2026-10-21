<?php

namespace Modules\Freelance\Services\Catalog;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Freelance\Events\FreelanceSkillsMerged;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSkillTranslation;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Account\FreelancerProfileLists;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;

/**
 * FL-102: the skill catalog — named in every language, tied to specialties, with a freelancer's suggestions waiting
 * for the admin and a merge for duplicates. One place for the rules so the admin screen, the apps and the later
 * profile / project tasks read the same skills.
 */
class FreelanceSkills
{
    /** The languages every skill must be named in before it shows to everyone. */
    public const REQUIRED_LOCALES = ['ar', 'en'];

    public function __construct(private readonly FreelanceNotifier $notifier) {}

    /** The name as compared for duplicates: lower case, no Arabic diacritics / tatweel, one alef, single spaces, no edge punctuation. */
    public static function normalize(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);
        $s = (string) preg_replace('/[أإآٱ]/u', 'ا', $s);
        $s = (string) preg_replace('/\s+/u', ' ', $s);

        return trim($s, " \t\n\r\0\x0B.,;:!?-_/\\|()[]{}\"'");
    }

    // -------------------------------------------------------------- admin

    /** @param  array<string, mixed>  $data  translations[{locale, name}], specialty_ids?[], status? (active / inactive) */
    public function create(array $data): FreelanceSkill
    {
        $rows = $data['translations'] ?? [];
        $this->assertNoDuplicate($rows, null);

        return DB::transaction(function () use ($data, $rows) {
            $skill = FreelanceSkill::query()->create([
                'slug' => $this->slugFor($rows),
                'status' => in_array($data['status'] ?? null, [FreelanceSkill::ACTIVE, FreelanceSkill::INACTIVE], true) ? $data['status'] : FreelanceSkill::ACTIVE,
            ]);
            $this->saveTranslations($skill, $rows);
            $skill->specialties()->sync($data['specialty_ids'] ?? []);

            return $skill->load(['translations', 'specialties.translations']);
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(FreelanceSkill $skill, array $data): FreelanceSkill
    {
        $this->assertEditable($skill);
        $rows = $data['translations'] ?? [];
        $this->assertNoDuplicate($rows, $skill->id);

        return DB::transaction(function () use ($skill, $data, $rows) {
            $this->saveTranslations($skill, $rows);
            if (array_key_exists('specialty_ids', $data)) {
                $skill->specialties()->sync($data['specialty_ids'] ?? []);
            }

            return $skill->load(['translations', 'specialties.translations']);
        });
    }

    /** The admin's switch: only a skill that is on or off (a suggestion is approved or rejected, a merged one is gone). */
    public function setActive(FreelanceSkill $skill, bool $active): FreelanceSkill
    {
        $this->assertEditable($skill);
        $skill->update(['status' => $active ? FreelanceSkill::ACTIVE : FreelanceSkill::INACTIVE]);

        return $skill;
    }

    /**
     * Takes a suggestion into the catalog. The admin may complete its names (the freelancer typed one language) and
     * its specialties; it must end up named in every required language.
     *
     * @param  list<array{locale: string, name: string}>  $translations
     * @param  list<int>|null  $specialtyIds
     */
    public function approve(FreelanceSkill $skill, array $translations = [], ?array $specialtyIds = null): FreelanceSkill
    {
        if ($skill->status !== FreelanceSkill::PENDING) {
            throw new FreelanceException('skill_invalid_state', 422);
        }
        $this->assertNoDuplicate($translations, $skill->id);

        DB::transaction(function () use ($skill, $translations, $specialtyIds) {
            $this->saveTranslations($skill, $translations);
            $have = $skill->translations()->pluck('locale')->all();
            $missing = array_values(array_diff(self::REQUIRED_LOCALES, $have));
            if ($missing !== []) {
                throw new FreelanceException('skill_translations_missing', 422, ['locales' => implode(', ', $missing)], ['locales' => $missing]);
            }
            if ($specialtyIds !== null) {
                $skill->specialties()->sync($specialtyIds);
            }
            $skill->update(['status' => FreelanceSkill::ACTIVE, 'reject_reason' => null]);
        });

        $this->tellSuggester($skill, 'skill.approved', ['name' => $this->nameOf($skill)]);

        return $skill->load(['translations', 'specialties.translations']);
    }

    public function reject(FreelanceSkill $skill, ?string $reason = null): FreelanceSkill
    {
        if ($skill->status !== FreelanceSkill::PENDING) {
            throw new FreelanceException('skill_invalid_state', 422);
        }
        $skill->update(['status' => FreelanceSkill::REJECTED, 'reject_reason' => $reason !== null ? mb_substr($reason, 0, 255) : null]);
        // It may sit on its author's profile while it waits; turned down, it leaves.
        app(FreelancerProfileLists::class)->dropSkill($skill->id);
        $this->tellSuggester($skill, 'skill.rejected', ['name' => $this->nameOf($skill), 'reason' => (string) $reason]);

        return $skill;
    }

    /**
     * Folds a duplicate into the skill that stays: its specialties and usage move over, the duplicate stays as an alias
     * (so its name still resolves, and can't be suggested again), and FreelanceSkillsMerged tells whoever uses skills to
     * move their own rows.
     */
    public function merge(FreelanceSkill $source, FreelanceSkill $target): FreelanceSkill
    {
        if ($source->id === $target->id || $source->status === FreelanceSkill::MERGED || $target->status !== FreelanceSkill::ACTIVE) {
            throw new FreelanceException('skill_merge_invalid', 422);
        }

        DB::transaction(function () use ($source, $target) {
            $target->specialties()->syncWithoutDetaching($source->specialties()->pluck('freelance_specialties.id')->all());
            // Whatever was already merged into the source now points at the survivor too.
            FreelanceSkill::query()->where('merged_into_id', $source->id)->update(['merged_into_id' => $target->id]);
            $target->increment('usage_count', (int) $source->usage_count);
            $source->forceFill(['status' => FreelanceSkill::MERGED, 'merged_into_id' => $target->id, 'usage_count' => 0])->save();
        });

        event(new FreelanceSkillsMerged($source->id, $target->id));

        return $target->load(['translations', 'specialties.translations']);
    }

    /** A skill in use (or one others were merged into) can't go: switch it off instead. */
    public function delete(FreelanceSkill $skill): void
    {
        if ($skill->usage_count > 0 || FreelanceSkill::query()->where('merged_into_id', $skill->id)->exists()) {
            throw new FreelanceException('skill_in_use', 409);
        }
        $skill->delete();
    }

    /** @param  list<int>  $ids  @return array{deleted: int, skipped: int} */
    public function deleteMany(array $ids): array
    {
        $deleted = 0;
        $skipped = 0;
        foreach (FreelanceSkill::query()->whereIn('id', $ids)->get() as $skill) {
            try {
                $this->delete($skill);
                $deleted++;
            } catch (FreelanceException) {
                $skipped++;
            }
        }

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }

    // -------------------------------------------------------------- the apps

    /**
     * The skills an app lists: the active ones, plus the viewer's own pending suggestions; by specialty (a main
     * specialty counts its sub-specialties too) and/or a search.
     *
     * @param  array{specialty_id?: ?int, q?: ?string}  $filters
     * @return LengthAwarePaginator<int, FreelanceSkill>
     */
    public function catalog(Model $viewer, array $filters, int $perPage = 30): LengthAwarePaginator
    {
        $specialtyId = $filters['specialty_id'] ?? null;
        $term = isset($filters['q']) ? self::normalize((string) $filters['q']) : '';

        return FreelanceSkill::query()->with(['translations', 'specialties'])
            ->where(function ($w) use ($viewer) {
                $w->where('status', FreelanceSkill::ACTIVE);
                if ($viewer instanceof Freelancer) {
                    $w->orWhere(fn ($mine) => $mine->where('status', FreelanceSkill::PENDING)->where('suggested_by_type', 'freelancer')->where('suggested_by_id', $viewer->id));
                }
            })
            ->when($specialtyId !== null, function ($q) use ($specialtyId) {
                $ids = FreelanceSpecialty::query()->where('id', $specialtyId)->orWhere('parent_id', $specialtyId)->pluck('id');
                $q->whereHas('specialties', fn ($s) => $s->whereIn('freelance_specialties.id', $ids));
            })
            ->when($term !== '', fn ($q) => $q->whereHas('translations', fn ($t) => $t->where('normalized', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->orderByDesc('usage_count')->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * A freelancer suggests a skill by the name they typed. An existing one (in any language, or an alias of one) is
     * returned instead of a copy; a new one waits for the admin as `pending`, shown only to them.
     *
     * @return array{skill: FreelanceSkill, created: bool}
     */
    public function suggest(Freelancer $by, string $name, string $locale, ?int $specialtyId = null): array
    {
        $normalized = self::normalize($name);
        $existing = FreelanceSkillTranslation::query()->where('normalized', $normalized)->with('skill')->first()?->skill;

        if ($existing !== null) {
            $skill = $this->resolve($existing);
            if (in_array($skill->status, [FreelanceSkill::INACTIVE, FreelanceSkill::REJECTED], true)) {
                throw new FreelanceException('skill_unavailable', 422);
            }

            return ['skill' => $skill->load('translations'), 'created' => false];
        }

        $today = FreelanceSkill::query()->where('suggested_by_type', 'freelancer')->where('suggested_by_id', $by->id)->where('created_at', '>=', now()->startOfDay())->count();
        if ($today >= (int) config('freelance.skills.suggest_daily_limit', 5)) {
            throw new FreelanceException('skill_suggest_limit', 429);
        }

        $skill = DB::transaction(function () use ($by, $name, $locale, $specialtyId) {
            $skill = FreelanceSkill::query()->create([
                'slug' => $this->slugFor([['locale' => $locale, 'name' => $name]]),
                'status' => FreelanceSkill::PENDING,
                'suggested_by_type' => 'freelancer',
                'suggested_by_id' => $by->id,
            ]);
            $this->saveTranslations($skill, [['locale' => $locale, 'name' => $name]]);
            if ($specialtyId !== null && FreelanceSpecialty::query()->whereKey($specialtyId)->exists()) {
                $skill->specialties()->attach($specialtyId);
            }

            return $skill;
        });

        return ['skill' => $skill->load('translations'), 'created' => true];
    }

    /** A merged skill is its target (an old reference keeps working). */
    public function resolve(FreelanceSkill $skill): FreelanceSkill
    {
        for ($i = 0; $i < 5 && $skill->status === FreelanceSkill::MERGED && $skill->merged_into_id !== null; $i++) {
            $skill = FreelanceSkill::query()->findOrFail($skill->merged_into_id);
        }

        return $skill;
    }

    // -------------------------------------------------------------- internals

    private function assertEditable(FreelanceSkill $skill): void
    {
        if (! in_array($skill->status, [FreelanceSkill::ACTIVE, FreelanceSkill::INACTIVE], true)) {
            throw new FreelanceException('skill_invalid_state', 422);
        }
    }

    /** @param  list<array{locale: string, name: string}>  $rows */
    private function assertNoDuplicate(array $rows, ?int $ignoreId): void
    {
        foreach ($rows as $row) {
            $clash = FreelanceSkillTranslation::query()
                ->where('locale', $row['locale'])->where('normalized', self::normalize($row['name']))
                ->when($ignoreId !== null, fn ($q) => $q->where('freelance_skill_id', '!=', $ignoreId))
                ->first();
            if ($clash !== null) {
                throw new FreelanceException('skill_duplicate', 422, [], ['skill_id' => $this->resolve($clash->skill)->id]);
            }
        }
    }

    /** @param  list<array{locale: string, name: string}>  $rows */
    private function saveTranslations(FreelanceSkill $skill, array $rows): void
    {
        foreach ($rows as $row) {
            $skill->translations()->updateOrCreate(['locale' => $row['locale']], ['name' => trim($row['name']), 'normalized' => self::normalize($row['name'])]);
        }
        $skill->unsetRelation('translations');
    }

    /** @param  list<array{locale: string, name: string}>  $rows */
    private function slugFor(array $rows): string
    {
        $english = collect($rows)->firstWhere('locale', 'en')['name'] ?? ($rows[0]['name'] ?? '');
        $base = Str::slug($english) ?: 'skill';
        $slug = $base;
        for ($i = 2; FreelanceSkill::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    private function nameOf(FreelanceSkill $skill): string
    {
        return (string) ($skill->load('translations')->translatedName() ?? $skill->slug);
    }

    /** @param  array<string, scalar|null>  $variables */
    private function tellSuggester(FreelanceSkill $skill, string $event, array $variables): void
    {
        if ($skill->suggested_by_type !== 'freelancer' || $skill->suggested_by_id === null) {
            return;
        }
        $freelancer = Freelancer::query()->find($skill->suggested_by_id);
        if ($freelancer !== null) {
            $this->notifier->notify($freelancer, $event, $variables, ['skill_id' => $skill->id]);
        }
    }
}
