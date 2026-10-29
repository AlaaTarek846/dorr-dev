<?php

namespace Modules\Freelance\Services\Account;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerCertificate;
use Modules\Freelance\Models\FreelancerExperience;
use Modules\Freelance\Services\Catalog\FreelanceSkills;

/**
 * What a freelancer lists on their profile (FL-104): specialties (one primary), skills (with a level), work history and
 * certificates. Each list is replaced or edited here, within limits from `freelance.profile`. A skill a freelancer
 * suggested may be listed while it waits for the admin; if the admin rejects it, it leaves the profile.
 */
class FreelancerProfileLists
{
    public const LEVELS = ['beginner', 'intermediate', 'advanced', 'expert'];

    public function __construct(private readonly FreelanceSkills $catalog) {}

    // -------------------------------------------------------------- specialties

    /**
     * Replaces the freelancer's specialties: a few active ones from the catalog, exactly one primary among them.
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    public function replaceSpecialties(Freelancer $freelancer, array $ids, int $primaryId): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $this->assertWithin($ids, (int) config('freelance.profile.max_specialties', 5));
        if ($ids === [] || ! in_array($primaryId, $ids, true)) {
            throw new FreelanceException('profile_primary_invalid', 422);
        }

        $valid = FreelanceSpecialty::query()->whereIn('id', $ids)->where('status', true)->pluck('id')->all();
        // A specialty already on the profile stays even if the admin switched it off since; a new one must be active.
        $kept = DB::table('freelancer_specialties')->where('freelancer_id', $freelancer->id)->pluck('freelance_specialty_id')->all();
        $bad = array_values(array_diff($ids, $valid, $kept));
        if ($bad !== []) {
            throw new FreelanceException('profile_specialty_invalid', 422, ['ids' => implode(', ', $bad)], ['ids' => $bad]);
        }

        DB::transaction(function () use ($freelancer, $ids, $primaryId) {
            DB::table('freelancer_specialties')->where('freelancer_id', $freelancer->id)->delete();
            DB::table('freelancer_specialties')->insert(array_map(fn (int $id) => ['freelancer_id' => $freelancer->id, 'freelance_specialty_id' => $id, 'is_primary' => $id === $primaryId], $ids));
        });

        return $this->specialties($freelancer);
    }

    /** @return list<array<string, mixed>> the primary first */
    public function specialties(Freelancer $freelancer): array
    {
        return FreelanceSpecialty::query()->with(['translations', 'parent.translations'])
            ->join('freelancer_specialties', 'freelancer_specialties.freelance_specialty_id', '=', 'freelance_specialties.id')
            ->where('freelancer_specialties.freelancer_id', $freelancer->id)
            ->orderByDesc('freelancer_specialties.is_primary')->orderBy('freelance_specialties.id')
            ->get(['freelance_specialties.*', 'freelancer_specialties.is_primary as pivot_primary'])
            ->map(fn (FreelanceSpecialty $s) => [
                'id' => $s->id,
                'name' => $s->translatedName(),
                'parent' => $s->parent?->translatedName(),
                'is_primary' => (bool) $s->pivot_primary,
                'is_active' => (bool) $s->status,
            ])->values()->all();
    }

    // -------------------------------------------------------------- skills

    /**
     * Replaces the freelancer's skills. A merged skill counts as the one it became; the same skill twice keeps the higher
     * level. `usage_count` of each skill follows what was added and removed.
     *
     * @param  list<array{skill_id: int, level?: ?string}>  $rows
     * @return list<array<string, mixed>>
     */
    public function replaceSkills(Freelancer $freelancer, array $rows): array
    {
        $wanted = [];
        foreach ($rows as $row) {
            $skill = FreelanceSkill::query()->find((int) $row['skill_id']);
            $skill = $skill === null ? null : $this->catalog->resolve($skill);
            $mine = $skill !== null && $skill->status === FreelanceSkill::PENDING && $skill->suggested_by_type === 'freelancer' && (int) $skill->suggested_by_id === $freelancer->id;
            if ($skill === null || ($skill->status !== FreelanceSkill::ACTIVE && ! $mine)) {
                throw new FreelanceException('profile_skill_invalid', 422, ['ids' => (string) $row['skill_id']], ['ids' => [(int) $row['skill_id']]]);
            }
            $level = $row['level'] ?? null;
            $current = $wanted[$skill->id] ?? null;
            $wanted[$skill->id] = $current === null || $this->rank($level) > $this->rank($current) ? $level : $current;
        }
        $this->assertWithin(array_keys($wanted), (int) config('freelance.profile.max_skills', 30));

        DB::transaction(function () use ($freelancer, $wanted) {
            $before = DB::table('freelancer_skills')->where('freelancer_id', $freelancer->id)->pluck('freelance_skill_id')->all();
            $after = array_keys($wanted);

            DB::table('freelancer_skills')->where('freelancer_id', $freelancer->id)->delete();
            DB::table('freelancer_skills')->insert(array_map(fn (int $id) => ['freelancer_id' => $freelancer->id, 'freelance_skill_id' => $id, 'level' => $wanted[$id], 'created_at' => now()], $after));

            $this->adjustUsage(array_diff($after, $before), 1);
            $this->adjustUsage(array_diff($before, $after), -1);
        });

        return $this->skills($freelancer);
    }

    /** @return list<array<string, mixed>> */
    public function skills(Freelancer $freelancer): array
    {
        return FreelanceSkill::query()->with('translations')
            ->join('freelancer_skills', 'freelancer_skills.freelance_skill_id', '=', 'freelance_skills.id')
            ->where('freelancer_skills.freelancer_id', $freelancer->id)
            ->orderBy('freelancer_skills.created_at')->orderBy('freelance_skills.id')
            ->get(['freelance_skills.*', 'freelancer_skills.level as pivot_level'])
            ->map(fn (FreelanceSkill $s) => ['id' => $s->id, 'name' => $s->translatedName(), 'level' => $s->pivot_level, 'pending' => $s->status === FreelanceSkill::PENDING])->values()->all();
    }

    /** The catalog merged `$source` into `$target`: whoever listed the duplicate now lists the survivor, once, at the higher level. */
    public function moveMergedSkill(int $sourceId, int $targetId): void
    {
        DB::transaction(function () use ($sourceId, $targetId) {
            $rows = DB::table('freelancer_skills')->where('freelance_skill_id', $sourceId)->get();
            $duplicates = 0;
            foreach ($rows as $row) {
                $existing = DB::table('freelancer_skills')->where('freelancer_id', $row->freelancer_id)->where('freelance_skill_id', $targetId)->first();
                if ($existing === null) {
                    DB::table('freelancer_skills')->where('freelancer_id', $row->freelancer_id)->where('freelance_skill_id', $sourceId)->update(['freelance_skill_id' => $targetId]);

                    continue;
                }
                if ($this->rank($row->level) > $this->rank($existing->level)) {
                    DB::table('freelancer_skills')->where('freelancer_id', $row->freelancer_id)->where('freelance_skill_id', $targetId)->update(['level' => $row->level]);
                }
                DB::table('freelancer_skills')->where('freelancer_id', $row->freelancer_id)->where('freelance_skill_id', $sourceId)->delete();
                $duplicates++;
            }
            // FreelanceSkills::merge already added the source's usage to the target; a freelancer who had both counts once.
            $this->adjustUsage([$targetId], -$duplicates);
        });
    }

    /** The admin turned a suggestion down: it leaves every profile that listed it. */
    public function dropSkill(int $skillId): void
    {
        DB::table('freelancer_skills')->where('freelance_skill_id', $skillId)->delete();
        FreelanceSkill::query()->whereKey($skillId)->update(['usage_count' => 0]);
    }

    // -------------------------------------------------------------- work history

    /** @param  array<string, mixed>  $data */
    public function addExperience(Freelancer $freelancer, array $data): FreelancerExperience
    {
        $this->assertWithin(range(0, $freelancer->experiences()->count()), (int) config('freelance.profile.max_experiences', 20));

        return $freelancer->experiences()->create($this->experienceData($data));
    }

    /** @param  array<string, mixed>  $data */
    public function updateExperience(FreelancerExperience $experience, array $data): FreelancerExperience
    {
        $experience->update($this->experienceData($data + $experience->only(['title', 'company', 'started_on', 'ended_on', 'is_current', 'description'])));

        return $experience->refresh();
    }

    // -------------------------------------------------------------- certificates

    /** @param  array<string, mixed>  $data */
    public function addCertificate(Freelancer $freelancer, array $data, ?UploadedFile $proof): FreelancerCertificate
    {
        $this->assertWithin(range(0, $freelancer->certificates()->count()), (int) config('freelance.profile.max_certificates', 20));
        $certificate = $freelancer->certificates()->create($this->certificateData($data));
        if ($proof !== null) {
            $certificate->setProof($proof);
        }

        return $certificate->refresh();
    }

    /** @param  array<string, mixed>  $data */
    public function updateCertificate(FreelancerCertificate $certificate, array $data, ?UploadedFile $proof, bool $removeProof): FreelancerCertificate
    {
        $certificate->update($this->certificateData($data + $certificate->only(['title', 'issuer', 'issued_on', 'expires_on', 'credential_url'])));
        if ($proof !== null) {
            $certificate->setProof($proof);
        } elseif ($removeProof) {
            $certificate->clearMediaCollection('proof');
        }

        return $certificate->refresh();
    }

    public function deleteCertificate(FreelancerCertificate $certificate): void
    {
        $certificate->clearMediaCollection('proof');
        $certificate->delete();
    }

    /** Everything goes with the account (the profile is personal); skills give their usage back. */
    public function clearAll(Freelancer $freelancer): void
    {
        $skills = DB::table('freelancer_skills')->where('freelancer_id', $freelancer->id)->pluck('freelance_skill_id')->all();
        DB::table('freelancer_skills')->where('freelancer_id', $freelancer->id)->delete();
        $this->adjustUsage($skills, -1);
        DB::table('freelancer_specialties')->where('freelancer_id', $freelancer->id)->delete();
        $freelancer->experiences()->delete();
        app(FreelancerPortfolio::class)->clearAll($freelancer);
        $freelancer->certificates()->get()->each(fn (FreelancerCertificate $c) => $this->deleteCertificate($c));
    }

    // -------------------------------------------------------------- internals

    /** @param  array<string, mixed>  $data  @return array<string, mixed> a current job has no end date */
    private function experienceData(array $data): array
    {
        $data = array_intersect_key($data, array_flip(['title', 'company', 'started_on', 'ended_on', 'is_current', 'description']));
        if (! empty($data['is_current'])) {
            $data['ended_on'] = null;
        }

        return $data;
    }

    /** @param  array<string, mixed>  $data  @return array<string, mixed> */
    private function certificateData(array $data): array
    {
        return array_intersect_key($data, array_flip(['title', 'issuer', 'issued_on', 'expires_on', 'credential_url']));
    }

    /** @param  array<int, mixed>  $items */
    private function assertWithin(array $items, int $max): void
    {
        if (count($items) > $max) {
            throw new FreelanceException('profile_limit', 422, ['max' => $max], ['max' => $max]);
        }
    }

    private function rank(?string $level): int
    {
        $i = array_search($level, self::LEVELS, true);

        return $i === false ? 0 : $i + 1;
    }

    /** @param  iterable<int>  $skillIds */
    private function adjustUsage(iterable $skillIds, int $by): void
    {
        $ids = collect($skillIds)->all();
        if ($ids === [] || $by === 0) {
            return;
        }
        if ($by > 0) {
            FreelanceSkill::query()->whereIn('id', $ids)->increment('usage_count', $by);
        } else {
            FreelanceSkill::query()->whereIn('id', $ids)->decrement('usage_count', -$by);
            FreelanceSkill::query()->whereIn('id', $ids)->where('usage_count', '<', 0)->update(['usage_count' => 0]);
        }
    }
}
