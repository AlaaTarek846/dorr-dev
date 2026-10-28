<?php

namespace Modules\Freelance\Services\Projects;

use App\Models\Country;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceProjectStatus as Status;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Models\FreelanceProjectEvent;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Services\Features\FreelanceCountry;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A client's projects (FL-201 / FL-202): a draft is saved as it is typed (only its shape is checked), edited before it is sent or
 * when the reviewer asks for changes, and sent for review only when complete — the budget within the country's (and the specialty's)
 * minimum and maximum from the policies (FL-008). A project lives in the client's country, in its currency.
 */
class ClientProjects
{
    public const FIELDS = ['title', 'description', 'specialty', 'skills', 'budget', 'duration'];

    public function __construct(
        private readonly ProjectStateMachine $machine,
        private readonly FreelancePolicies $policies,
        private readonly ProjectOffersWindow $window,
    ) {}

    /** @return LengthAwarePaginator<Project> newest first, optionally one status */
    public function mine(User $client, ?string $status, int $perPage): LengthAwarePaginator
    {
        return Project::query()->where('client_id', $client->id)
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->with(['specialty.translations', 'skills.translations', 'media'])
            ->latest('id')->paginate($perPage);
    }

    public function find(User $client, string $uuid): Project
    {
        return Project::query()->where('client_id', $client->id)->where('uuid', $uuid)
            ->with(['specialty.translations', 'skills.translations', 'media', 'events'])->firstOrFail();
    }

    /** @param  array<string, mixed>  $data */
    public function create(User $client, array $data): Project
    {
        $countryId = FreelanceCountry::of($client) ?? throw new FreelanceException('not_available', 403);
        $max = (int) config('freelance.projects.max_open', 20);
        $open = Project::query()->where('client_id', $client->id)->whereNotIn('status', [Status::Closed->value, Status::Cancelled->value])->count();
        if ($open >= $max) {
            throw new FreelanceException('project_limit', 422, ['max' => $max], ['max' => $max]);
        }

        return DB::transaction(function () use ($client, $countryId, $data) {
            $project = Project::query()->create($this->fields($data) + [
                'client_id' => $client->id,
                'country_id' => $countryId,
                'currency_code' => (string) Country::query()->with('currency')->find($countryId)?->currency?->code,
                'status' => Status::Draft,
            ]);
            if (array_key_exists('skill_ids', $data)) {
                $this->syncSkills($project, $data['skill_ids'] ?? []);
            }
            $this->machine->record($project, 'project.created', 'client', $client->id, [], null, Status::Draft);

            return $project->load(['specialty.translations', 'skills.translations', 'media', 'events']);
        });
    }

    /**
     * @param  array<string, mixed>  $data  only what is sent changes
     * @param  bool  $asReviewer  an admin may also fix a project that is waiting in the review queue
     */
    public function update(Project $project, array $data, bool $asReviewer = false): Project
    {
        if (! ($asReviewer && $project->status === Status::PendingReview)) {
            $this->assertEditable($project);
        }

        return DB::transaction(function () use ($project, $data) {
            $project->update($this->fields($data));
            if (array_key_exists('skill_ids', $data)) {
                $this->syncSkills($project, $data['skill_ids'] ?? []);
            }

            return $project->load(['specialty.translations', 'skills.translations', 'media', 'events']);
        });
    }

    /** Only a draft is deleted (anything sent is cancelled instead, and stays on record). */
    public function delete(Project $project): void
    {
        if ($project->status !== Status::Draft) {
            throw new FreelanceException('project_invalid_state', 409, ['status' => __('freelance.project_statuses.'.$project->status->value)], ['status' => $project->status->value]);
        }
        $project->clearMediaCollection('attachments');
        $project->delete();
    }

    public function addAttachment(Project $project, UploadedFile $file): Media
    {
        $this->assertEditable($project);
        $max = (int) config('freelance.projects.max_attachments', 10);
        if ($project->getMedia('attachments')->count() >= $max) {
            throw new FreelanceException('project_limit', 422, ['max' => $max], ['max' => $max]);
        }

        return $project->addAttachment($file);
    }

    public function removeAttachment(Project $project, int $mediaId): void
    {
        $this->assertEditable($project);
        ($project->getMedia('attachments')->firstWhere('id', $mediaId) ?? abort(404))->delete();
    }

    /** Sends a complete project for review (a draft, or one the reviewer sent back for changes). */
    public function submit(Project $project, User $client): Project
    {
        $missing = $this->missing($project);
        if ($missing !== []) {
            throw new FreelanceException('project_incomplete', 422, ['fields' => implode(', ', array_map(fn ($f) => __('freelance.project_fields.'.$f), $missing))], ['fields' => $missing]);
        }
        $this->assertReadyToPublish($project);

        $resubmit = in_array($project->status, [Status::ChangesRequested, Status::Rejected], true);

        return $this->machine->move($project, Status::PendingReview, $resubmit ? 'project.resubmitted' : 'project.submitted', 'client', $client->id, ['submitted_at' => now(), 'status_reason' => null])
            ->load(['specialty.translations', 'skills.translations', 'media', 'events']);
    }

    /** Complete, with usable skills and specialty, and a budget within the limits — what a project needs to be sent and to be published. */
    public function assertReadyToPublish(Project $project): void
    {
        $missing = $this->missing($project);
        if ($missing !== []) {
            throw new FreelanceException('project_incomplete', 422, ['fields' => implode(', ', array_map(fn ($f) => __('freelance.project_fields.'.$f), $missing))], ['fields' => $missing]);
        }
        $this->assertSkillsUsable($project);
        $this->assertBudget($project);
    }

    /** Takes a project out of the review queue to edit it again. */
    public function withdraw(Project $project, User $client): Project
    {
        return $this->machine->move($project, Status::Draft, 'project.withdrawn', 'client', $client->id, ['submitted_at' => null])
            ->load(['specialty.translations', 'skills.translations', 'media', 'events']);
    }

    /** A part started, delivered or in dispute: its money can't simply go back (FL-506). */
    private function hasWorkOn(Project $project): bool
    {
        return \Modules\Freelance\Models\FreelanceMilestone::query()->where('project_id', $project->id)->whereIn('status', ['in_progress', 'revision', 'delivered', 'disputed'])->exists();
    }

    public function cancel(Project $project, User $client, ?string $reason): Project
    {
        // FL-501: money held for work can't be left behind — giving it back is FL-506.
        // FL-506: parts nobody started go back at once; one with work on it (started, delivered, in dispute) must be settled first.
        // All or nothing: the money goes back only if the project is really cancelled.
        $cancelled = \Illuminate\Support\Facades\DB::transaction(function () use ($project, $client, $reason) {
            if ($this->hasWorkOn($project)) {
                throw new FreelanceException('project_has_funded_work', 409);
            }
            app(MilestoneCancellation::class)->refundFunded($project, 'client', $client->id);
            $cancelled = $this->machine->move($project, Status::Cancelled, 'project.cancelled', 'client', $client->id, ['closed_at' => now()], $reason ? ['reason' => $reason] : []);
            app(\Modules\Freelance\Services\Chat\OfferChat::class)->freeze($cancelled);
            app(Agreements::class)->projectCancelled($cancelled);

            return $cancelled;
        });

        return $cancelled
            ->load(['specialty.translations', 'skills.translations', 'media', 'events']);
    }

    /**
     * The smallest and biggest budget a project may have in the client's country, for one specialty if given (its own rule wins
     * over the country's), in minor units of the country's currency. Null = no limit.
     *
     * @return array{min_minor: ?int, max_minor: ?int, currency: string}
     */
    public function budgetRules(int $countryId, ?int $specialtyId): array
    {
        $policy = $this->policies->for($countryId, $specialtyId);
        $min = $policy->get('project.min_budget_minor');
        $max = $policy->get('project.max_budget_minor');

        return [
            'min_minor' => $min === null ? null : (int) $min,
            'max_minor' => $max === null ? null : (int) $max,
            'currency' => (string) Country::query()->with('currency')->find($countryId)?->currency?->code,
        ];
    }

    /** @return list<string> what still has to be filled in before the project can be sent */
    public function missing(Project $project): array
    {
        $missing = [];
        if (mb_strlen(trim((string) $project->title)) < (int) config('freelance.projects.title_min', 10)) {
            $missing[] = 'title';
        }
        if (mb_strlen(trim((string) $project->description)) < (int) config('freelance.projects.description_min', 30)) {
            $missing[] = 'description';
        }
        if ($project->freelance_specialty_id === null) {
            $missing[] = 'specialty';
        }
        if ($project->skills()->count() === 0) {
            $missing[] = 'skills';
        }
        if ($project->budget_min_minor === null || ($project->budget_type === 'range' && $project->budget_max_minor === null)) {
            $missing[] = 'budget';
        }
        if ($project->duration_days === null) {
            $missing[] = 'duration';
        }

        return $missing;
    }

    /** @return array<string, mixed> */
    public function present(Project $project): array
    {
        $status = $project->status;

        return [
            'uuid' => $project->uuid,
            'status' => $status->value,
            'status_label' => __('freelance.project_statuses.'.$status->value),
            'status_reason' => $project->status_reason,
            'title' => $project->title,
            'description' => $project->description,
            'questions' => array_values((array) $project->questions),
            'specialty' => $project->specialty === null ? null : ['id' => $project->specialty->id, 'name' => $project->specialty->translatedName()],
            'skills' => $project->skills->map(fn (FreelanceSkill $s) => ['id' => $s->id, 'name' => $s->translatedName()])->values()->all(),
            'budget' => [
                'type' => $project->budget_type,
                'min_minor' => $project->budget_min_minor,
                'max_minor' => $project->budget_type === 'range' ? $project->budget_max_minor : null,
                'currency' => $project->currency_code,
            ],
            'duration_days' => $project->duration_days,
            'visibility' => $project->visibility,
            'attachments' => $project->getMedia('attachments')->map(fn (Media $m) => [
                'id' => $m->id,
                'name' => $m->file_name,
                'mime' => $m->mime_type,
                'size' => $m->size,
                'path' => "projects/{$project->uuid}/attachments/{$m->id}",
            ])->values()->all(),
            'missing' => $status->editable() ? $this->missing($project) : [],
            'can' => [
                'edit' => $status->editable(),
                'submit' => $status->editable(),
                'withdraw' => $status === Status::PendingReview,
                'cancel' => in_array($status, [Status::PendingReview, Status::ChangesRequested, Status::Rejected, Status::Published, Status::Awarded], true)
                    // In progress: only while no part has work on it (the funded ones go back).
                    || ($status === Status::InProgress && ! $this->hasWorkOn($project)),
                'delete' => $status === Status::Draft,
            ],
            // FL-205: invitations to a private project.
            'invitations' => $project->visibility === 'private' ? app(ProjectInvitations::class)->summary($project) : null,
            // FL-401: the agreement with the chosen freelancer, when there is one.
            'agreement' => ($a = app(Agreements::class)->current($project)) === null ? null : ['uuid' => $a->uuid, 'status' => $a->status],
            'offers' => $this->window->present($project, true) + [
                'count' => \Modules\Freelance\Models\FreelanceOffer::query()->where('project_id', $project->id)->where('status', '!=', 'withdrawn')->count(),
                // The offer the client chose (FL-309), for the project page.
                'chosen' => \Modules\Freelance\Models\FreelanceOffer::query()->where('project_id', $project->id)->where('status', 'accepted')->value('uuid'),
            ],
            'timeline' => $project->relationLoaded('events')
                ? $project->events->sortBy('id')->map(fn (FreelanceProjectEvent $e) => [
                    'type' => $e->type,
                    'from' => $e->from_status,
                    'to' => $e->to_status,
                    'at' => $e->created_at?->toIso8601String(),
                ])->values()->all()
                : null,
            'created_at' => $project->created_at?->toIso8601String(),
            'submitted_at' => $project->submitted_at?->toIso8601String(),
            'published_at' => $project->published_at?->toIso8601String(),
        ];
    }

    // -------------------------------------------------------------- helpers

    /** @param  array<string, mixed>  $data */
    private function fields(array $data): array
    {
        $out = [];
        foreach (['title', 'description'] as $key) {
            if (array_key_exists($key, $data)) {
                $text = $data[$key] === null ? null : trim(strip_tags((string) $data[$key]));
                $out[$key] = $text === '' ? null : $text;
            }
        }
        if (array_key_exists('specialty_id', $data)) {
            $id = $data['specialty_id'];
            if ($id !== null && ! FreelanceSpecialty::query()->whereKey($id)->where('status', true)->exists()) {
                throw new FreelanceException('profile_specialty_invalid', 422, ['ids' => (string) $id], ['ids' => [(int) $id]]);
            }
            $out['freelance_specialty_id'] = $id === null ? null : (int) $id;
        }
        if (array_key_exists('budget_type', $data)) {
            $out['budget_type'] = $data['budget_type'];
        }
        // FL-307: the same questions for every freelancer who offers (blank ones dropped).
        if (array_key_exists('questions', $data)) {
            $q = array_values(array_filter(array_map(fn ($t) => trim(strip_tags((string) $t)), (array) ($data['questions'] ?? [])), fn ($t) => $t !== ''));
            $out['questions'] = $q === [] ? null : $q;
        }
        foreach (['budget_min_minor', 'budget_max_minor', 'duration_days'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key] === null ? null : (int) $data[$key];
            }
        }
        if (($out['budget_type'] ?? null) === 'fixed') {
            $out['budget_max_minor'] = null;
        }
        if (array_key_exists('visibility', $data)) {
            $out['visibility'] = $data['visibility'];
        }

        return $out;
    }

    /** @param  list<int>  $ids */
    private function syncSkills(Project $project, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $max = (int) config('freelance.projects.max_skills', 10);
        if (count($ids) > $max) {
            throw new FreelanceException('project_limit', 422, ['max' => $max], ['max' => $max]);
        }
        $valid = FreelanceSkill::query()->whereIn('id', $ids)->where('status', FreelanceSkill::ACTIVE)->pluck('id')->all();
        if (array_diff($ids, $valid) !== []) {
            throw new FreelanceException('profile_skill_invalid', 422, ['ids' => implode(', ', array_diff($ids, $valid))], ['ids' => array_values(array_diff($ids, $valid))]);
        }
        $project->skills()->sync($ids);
    }

    /** A skill switched off (or merged away) after it was picked can't go out to review. */
    private function assertSkillsUsable(Project $project): void
    {
        $bad = $project->skills()->where('status', '!=', FreelanceSkill::ACTIVE)->pluck('freelance_skills.id')->all();
        if ($bad !== []) {
            throw new FreelanceException('profile_skill_invalid', 422, ['ids' => implode(', ', $bad)], ['ids' => $bad]);
        }
        if (! FreelanceSpecialty::query()->whereKey($project->freelance_specialty_id)->where('status', true)->exists()) {
            throw new FreelanceException('profile_specialty_invalid', 422, ['ids' => (string) $project->freelance_specialty_id], ['ids' => [$project->freelance_specialty_id]]);
        }
    }

    private function assertBudget(Project $project): void
    {
        $rules = $this->budgetRules($project->country_id, $project->freelance_specialty_id);
        $low = (int) $project->budget_min_minor;
        $high = $project->budget_type === 'range' ? (int) $project->budget_max_minor : $low;

        if ($project->budget_type === 'range' && $high <= $low) {
            throw new FreelanceException('project_budget_range', 422);
        }
        if ($rules['min_minor'] !== null && $low < $rules['min_minor']) {
            throw new FreelanceException('project_budget_below_min', 422, ['min' => $this->money($rules['min_minor'], $rules['currency'])], ['min_minor' => $rules['min_minor'], 'currency' => $rules['currency']]);
        }
        if ($rules['max_minor'] !== null && $high > $rules['max_minor']) {
            throw new FreelanceException('project_budget_above_max', 422, ['max' => $this->money($rules['max_minor'], $rules['currency'])], ['max_minor' => $rules['max_minor'], 'currency' => $rules['currency']]);
        }
    }

    private function assertEditable(Project $project): void
    {
        if (! $project->status->editable()) {
            throw new FreelanceException('project_invalid_state', 409, ['status' => __('freelance.project_statuses.'.$project->status->value)], ['status' => $project->status->value]);
        }
    }

    private function money(int $minor, string $currency): string
    {
        return number_format($minor / 100, 2).' '.$currency;
    }
}
