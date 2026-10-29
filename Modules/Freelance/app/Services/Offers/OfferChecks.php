<?php

namespace Modules\Freelance\Services\Offers;

use Illuminate\Support\Collection;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceOfferTemplate as Template;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Chat\ContactGuard;

/**
 * Helping a freelancer send a good offer (FL-304): what looks weak before it is sent (short details, no files, a price far from the
 * budget, more days than the project asks) as warnings they may ignore, and contact details (a phone number, an email, a link) as the
 * one thing that is refused — in an offer as in the chat, until the agreement (FL-308). Also the texts they keep to start from.
 */
class OfferChecks
{
    /** Details shorter than this are allowed but read thin. */
    public const DETAILS_GOOD = 150;

    public const MAX_TEMPLATES = 10;

    public function __construct(private readonly ContactGuard $guard) {}

    /** @throws FreelanceException freelance_offer_contact_blocked (422) */
    public function assertNoContact(?string $text): void
    {
        if ($text !== null && ($kind = $this->guard->find($text)) !== null) {
            throw new FreelanceException('offer_contact_blocked', 422, [], ['kind' => $kind]);
        }
    }

    /**
     * @param  array{price_minor?: ?int, duration_days?: ?int, details?: ?string, files?: ?int}  $offer
     * @return list<array{code: string, level: string, message: string}> `error` stops the sending, `warning` is advice
     */
    public function check(Project $project, array $offer): array
    {
        $out = [];
        $add = function (string $code, string $level, array $replace = []) use (&$out): void {
            $out[] = ['code' => $code, 'level' => $level, 'message' => __('freelance.offer_checks.'.$code, $replace)];
        };
        $details = trim((string) ($offer['details'] ?? ''));
        if ($details !== '' && $this->guard->find($details) !== null) {
            $add('contact', 'error');
        }
        if (mb_strlen($details) < self::DETAILS_GOOD) {
            $add('details_short', 'warning', ['min' => self::DETAILS_GOOD]);
        }
        if ((int) ($offer['files'] ?? 0) === 0) {
            $add('no_files', 'warning');
        }
        $price = $offer['price_minor'] ?? null;
        if ($price !== null) {
            $top = $project->budget_type === 'range' && $project->budget_max_minor ? $project->budget_max_minor : $project->budget_min_minor;
            if ($top && $price > $top * 1.5) {
                $add('price_high', 'warning');
            } elseif ($project->budget_min_minor && $price < $project->budget_min_minor * 0.5) {
                $add('price_low', 'warning');
            }
        }
        $days = $offer['duration_days'] ?? null;
        if ($days !== null && $project->duration_days && $days > $project->duration_days) {
            $add('duration_long', 'warning', ['days' => $project->duration_days]);
        }

        return $out;
    }

    /** @return Collection<int, Template> */
    public function templates(Freelancer $freelancer): Collection
    {
        return Template::query()->where('freelancer_id', $freelancer->id)->latest('updated_at')->latest('id')->get();
    }

    /** @param  array{title: string, details: string}  $data */
    public function saveTemplate(Freelancer $freelancer, array $data, ?Template $template = null): Template
    {
        $this->assertNoContact($data['title'].' '.$data['details']);
        if ($template === null && Template::query()->where('freelancer_id', $freelancer->id)->count() >= self::MAX_TEMPLATES) {
            throw new FreelanceException('offer_templates_full', 422, ['max' => self::MAX_TEMPLATES], ['max' => self::MAX_TEMPLATES]);
        }
        $values = ['title' => trim(strip_tags($data['title'])), 'details' => trim(strip_tags($data['details']))];

        return $template === null
            ? Template::query()->create($values + ['freelancer_id' => $freelancer->id])
            : tap($template)->update($values);
    }

    public function findTemplate(Freelancer $freelancer, int $id): Template
    {
        return Template::query()->where('freelancer_id', $freelancer->id)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    public static function present(Template $t): array
    {
        return ['id' => $t->id, 'title' => $t->title, 'details' => $t->details, 'updated_at' => $t->updated_at?->toIso8601String()];
    }
}
