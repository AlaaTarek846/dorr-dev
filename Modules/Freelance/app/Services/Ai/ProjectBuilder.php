<?php

namespace Modules\Freelance\Services\Ai;

use Illuminate\Support\Str;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Services\Catalog\FreelanceSkills;
use Modules\Freelance\Services\Policy\FreelanceMoney;
use Modules\Freelance\Services\Projects\ClientProjects;
use Modules\User\Models\User;

/**
 * AI Project Builder (FS-401, light): the client says the idea in their own words — formal Arabic, Egyptian, Gulf or English — and
 * DORR AI writes it as a professional project: a title, a structured description (goal, audience, what's in, what's delivered, how it's
 * accepted, what's out, the assumptions), the specialty and the skills from DORR's own catalog, a budget and a duration **as an estimate**
 * inside the country's limits, and the questions every freelancer answers with an offer. What is still unclear comes back as short
 * questions for the client; answered, the project is written again with the answers.
 *
 * Nothing is saved here: the app fills the project form with it, and the client checks and changes everything before saving a draft.
 */
class ProjectBuilder
{
    private const MAX_SPECIALTIES = 250;

    private const MAX_LIST = 6;

    public function __construct(
        private readonly FreelanceAi $ai,
        private readonly ClientProjects $projects,
        private readonly FreelanceMoney $money,
    ) {}

    /**
     * @param  list<array{question: string, answer: string}>  $answers  the client's answers to the last questions
     * @return array{draft: array<string, mixed>, brief: array<string, mixed>, questions: list<string>}
     */
    public function build(User $client, int $countryId, string $idea, array $answers = []): array
    {
        $this->ai->refuseUnlessEntitled($client);

        $specialties = FreelanceSpecialty::query()->where('status', true)->with(['translations', 'parent.translations'])
            ->orderBy('parent_id')->orderBy('sort_order')->limit(self::MAX_SPECIALTIES)->get();
        $rules = $this->projects->budgetRules($countryId, null);
        $currency = $rules['currency'];
        $decimals = $this->money->decimals();
        $locale = app()->getLocale() === 'ar' ? 'Arabic' : 'English';

        $catalog = $specialties->map(fn (FreelanceSpecialty $s) => $s->id.' | '.$s->translatedName().($s->parent ? ' ('.$s->parent->translatedName().')' : ''))->implode("\n");
        $limits = trim(($rules['min_minor'] !== null ? 'min '.($rules['min_minor'] / 10 ** $decimals) : '').' '.($rules['max_minor'] !== null ? 'max '.($rules['max_minor'] / 10 ** $decimals) : ''));
        $maxDays = (int) config('freelance.projects.max_duration_days', 365);

        $system = <<<PROMPT
You are DORR AI's project builder on DORR, a freelance marketplace. A client who may not be technical describes an idea; write it as a
clear, professional project that freelancers can price. The client may write formal Arabic, Egyptian or Gulf dialect, or English.
Write every text in {$locale}, plain and professional (no dialect, no emojis, no markdown). Never invent facts the client did not
imply: anything you had to assume goes in "assumptions"; anything important that is really unclear becomes a short question in
"client_questions" (at most 3, none when the idea is clear enough). Never put names, phone numbers, e-mails or links in the project.
The budget and the duration are only an estimate for a typical freelancer of this specialty, in {$currency}{$this->limitsText($limits)}.
The duration is in days (1 to {$maxDays}).
Pick "specialty_id" ONLY from this catalog (id | name (parent)); the most specific one that fits, or null:
{$catalog}

Answer with JSON only:
{"title": "under 90 characters", "summary": "2-3 sentences", "goal": "one sentence", "audience": "one sentence or null",
 "features": ["what is in scope", "..."], "deliverables": ["what is handed over", "..."], "acceptance": ["how the client checks it is done", "..."],
 "out_of_scope": ["..."], "assumptions": ["..."], "specialty_id": 12, "skills": ["short skill names, the usual English or Arabic name", "..."],
 "budget": {"type": "fixed or range", "min": 500, "max": 900}, "duration_days": 21,
 "freelancer_questions": ["a question each freelancer answers with the offer", "..."], "client_questions": ["..."]}
Lists: at most 6 items each, short lines; skills at most 8; freelancer_questions at most 3.
PROMPT;

        $input = 'Idea: '.FreelanceAi::scrub(Str::limit($idea, 2000, ''));
        foreach (array_slice($answers, 0, 5) as $a) {
            $input .= "\nQ: ".FreelanceAi::scrub(Str::limit((string) ($a['question'] ?? ''), 300, ''))."\nA: ".FreelanceAi::scrub(Str::limit((string) ($a['answer'] ?? ''), 500, ''));
        }

        $out = $this->ai->ask($client, $system, $input, 'freelance.project_builder');

        return $this->shape($out, $specialties->keyBy('id'), $rules, $decimals, $maxDays);
    }

    private function limitsText(string $limits): string
    {
        return $limits === '' ? '' : ' (the platform allows '.$limits.')';
    }

    /**
     * The model's answer, checked and turned into what the project form takes.
     *
     * @param  array<string, mixed>  $out
     * @param  \Illuminate\Support\Collection<int, FreelanceSpecialty>  $specialties
     * @param  array{min_minor: ?int, max_minor: ?int, currency: string}  $rules
     * @return array{draft: array<string, mixed>, brief: array<string, mixed>, questions: list<string>}
     */
    private function shape(array $out, $specialties, array $rules, int $decimals, int $maxDays): array
    {
        $text = fn ($v, int $max) => is_string($v) && trim($v) !== '' ? Str::limit(trim(strip_tags($v)), $max, '') : null;
        $list = fn ($v, int $max = self::MAX_LIST) => collect(is_array($v) ? $v : [])->map(fn ($i) => $text($i, 300))->filter()->take($max)->values()->all();

        $brief = [
            'summary' => $text($out['summary'] ?? null, 1200),
            'goal' => $text($out['goal'] ?? null, 400),
            'audience' => $text($out['audience'] ?? null, 400),
            'features' => $list($out['features'] ?? null),
            'deliverables' => $list($out['deliverables'] ?? null),
            'acceptance' => $list($out['acceptance'] ?? null),
            'out_of_scope' => $list($out['out_of_scope'] ?? null),
            'assumptions' => $list($out['assumptions'] ?? null),
        ];

        $specialty = $specialties->get((int) ($out['specialty_id'] ?? 0));
        $skills = $this->skills($list($out['skills'] ?? null, 8), $specialty?->id);

        // The estimate, inside the country's limits.
        $unit = 10 ** $decimals;
        $budget = is_array($out['budget'] ?? null) ? $out['budget'] : [];
        $min = is_numeric($budget['min'] ?? null) ? (int) round((float) $budget['min'] * $unit) : null;
        $max = is_numeric($budget['max'] ?? null) ? (int) round((float) $budget['max'] * $unit) : null;
        $clamp = function (?int $v) use ($rules): ?int {
            if ($v === null || $v <= 0) {
                return null;
            }
            if ($rules['min_minor'] !== null) {
                $v = max($v, (int) $rules['min_minor']);
            }

            return $rules['max_minor'] !== null ? min($v, (int) $rules['max_minor']) : $v;
        };
        $min = $clamp($min);
        $max = $clamp($max);
        $type = ($budget['type'] ?? 'fixed') === 'range' && $min !== null && $max !== null && $max > $min ? 'range' : 'fixed';
        $days = is_numeric($out['duration_days'] ?? null) ? max(1, min($maxDays, (int) $out['duration_days'])) : null;

        $title = $text($out['title'] ?? null, 120);
        $draft = [
            'title' => $title,
            'description' => $this->description($brief),
            'specialty' => $specialty ? ['id' => $specialty->id, 'name' => $specialty->translatedName()] : null,
            'skills' => $skills,
            'budget' => ['type' => $type, 'min_minor' => $min, 'max_minor' => $type === 'range' ? $max : null, 'currency' => $rules['currency']],
            'duration_days' => $days,
            'visibility' => 'public',
            'questions' => $list($out['freelancer_questions'] ?? null, min(3, (int) config('freelance.projects.max_questions', 5))),
        ];

        return [
            'draft' => $draft,
            'brief' => $brief,
            'questions' => $list($out['client_questions'] ?? null, 3),
        ];
    }

    /** The project's description: the brief in plain text, with headings in the client's language. */
    private function description(array $brief): ?string
    {
        $parts = [];
        if ($brief['summary']) {
            $parts[] = $brief['summary'];
        }
        if ($brief['goal']) {
            $parts[] = __('freelance.ai.brief.goal').': '.$brief['goal'];
        }
        if ($brief['audience']) {
            $parts[] = __('freelance.ai.brief.audience').': '.$brief['audience'];
        }
        foreach (['features', 'deliverables', 'acceptance', 'out_of_scope', 'assumptions'] as $key) {
            if ($brief[$key] !== []) {
                $parts[] = __('freelance.ai.brief.'.$key).":\n".collect($brief[$key])->map(fn ($l) => '• '.$l)->implode("\n");
            }
        }
        if ($parts === []) {
            return null;
        }

        return Str::limit(implode("\n\n", $parts), (int) config('freelance.projects.description_max', 5000), '');
    }

    /**
     * The skills named by the model that DORR's catalog has (active ones), the specialty's own first — a name the catalog lacks is
     * left out rather than made up.
     *
     * @param  list<string>  $names
     * @return list<array{id: int, name: string}>
     */
    private function skills(array $names, ?int $specialtyId): array
    {
        $found = [];
        foreach ($names as $name) {
            $normalized = FreelanceSkills::normalize($name);
            if ($normalized === '') {
                continue;
            }
            $match = fn ($q) => $q->where('status', FreelanceSkill::ACTIVE)
                ->when($specialtyId !== null, fn ($b) => $b->orderByRaw('exists (select 1 from freelance_skill_specialty ss where ss.freelance_skill_id = freelance_skills.id and ss.freelance_specialty_id = ?) desc', [$specialtyId]));
            $skill = FreelanceSkill::query()->whereHas('translations', fn ($t) => $t->where('normalized', $normalized))->tap($match)->first()
                ?? (mb_strlen($normalized) >= 3 ? FreelanceSkill::query()->whereHas('translations', fn ($t) => $t->where('normalized', 'like', addcslashes($normalized, '%_\\').'%'))->tap($match)->first() : null);
            if ($skill !== null && ! isset($found[$skill->id])) {
                $found[$skill->id] = ['id' => $skill->id, 'name' => (string) $skill->load('translations')->translatedName()];
            }
        }

        return array_values(array_slice($found, 0, (int) config('freelance.projects.max_skills', 10), true));
    }
}
