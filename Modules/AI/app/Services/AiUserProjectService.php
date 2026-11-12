<?php

namespace Modules\AI\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AI\Exceptions\AiAssistantException;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiFile;
use Modules\AI\Models\AiProject;
use Modules\AI\Models\AiProjectContext;
use Modules\AI\Repositories\AiConversationRepository;

/**
 * The person's own projects in DORR AI (spec 151, 315: "projects and files when invoked").
 *
 * A project groups conversations and files around one piece of work, with its own description and
 * instructions. Every answer in a conversation that belongs to a project reads them (one system
 * instruction — see systemMessage()), so the person doesn't repeat them in each chat. Nothing is
 * shared between people: a project, its conversations and files are all the owner's own.
 */
class AiUserProjectService
{
    public const MAX_PROJECTS = 50;

    /** How many decisions a project keeps in ai_project_context (the newest). */
    public const MAX_CONTEXT_DECISIONS = 30;

    public function __construct(protected AiConversationRepository $conversations) {}

    /** @return Collection<int, AiProject> */
    public function list(Authenticatable $owner): Collection
    {
        return $this->query($owner)
            ->with(['instructions' => fn ($q) => $q->where('status', true)])
            ->withCount(['projectConversations', 'projectFiles'])
            ->latest('updated_at')->get();
    }

    public function show(Authenticatable $owner, int $id): AiProject
    {
        return $this->find($owner, $id)->load([
            'instructions' => fn ($q) => $q->where('status', true),
            'projectConversations.conversation:id,title,updated_at',
            'projectFiles.file:id,file_name,mime_type,file_size',
        ]);
    }

    /** @param  array{name: string, description?: ?string, instructions?: ?string}  $data */
    public function create(Authenticatable $owner, array $data): AiProject
    {
        if ($this->query($owner)->count() >= self::MAX_PROJECTS) {
            throw new AiAssistantException('project_limit');
        }

        return DB::transaction(function () use ($owner, $data) {
            $project = AiProject::query()->create([
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getAuthIdentifier(),
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
                'status' => 'active',
            ]);
            $this->setInstructions($project, $data['instructions'] ?? null);

            return $project;
        });
    }

    /** @param  array{name?: string, description?: ?string, instructions?: ?string}  $data */
    public function update(Authenticatable $owner, int $id, array $data): AiProject
    {
        $project = $this->find($owner, $id);

        DB::transaction(function () use ($project, $data) {
            $project->fill(array_intersect_key($data, array_flip(['name', 'description'])))->save();
            if (array_key_exists('instructions', $data)) {
                $this->setInstructions($project, $data['instructions']);
            }
        });

        return $project;
    }

    public function delete(Authenticatable $owner, int $id): void
    {
        // Its conversations and files stay the person's; only the grouping goes.
        $this->find($owner, $id)->delete();
    }

    public function addConversation(Authenticatable $owner, int $projectId, int $conversationId): void
    {
        $project = $this->find($owner, $projectId);
        $conversation = $this->conversations->findForOwner($owner, $conversationId);

        $project->projectConversations()->firstOrCreate(['conversation_id' => $conversation->id]);
        $project->touch();
    }

    public function removeConversation(Authenticatable $owner, int $projectId, int $conversationId): void
    {
        $this->find($owner, $projectId)->projectConversations()->where('conversation_id', $conversationId)->delete();
    }

    public function addFile(Authenticatable $owner, int $projectId, int $fileId): void
    {
        $project = $this->find($owner, $projectId);
        $file = AiFile::query()->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->getAuthIdentifier())->find($fileId)
            ?? throw new AiAssistantException('project_file_not_found', 404);

        $project->projectFiles()->firstOrCreate(['file_id' => $file->id]);
        $project->touch();
    }

    public function removeFile(Authenticatable $owner, int $projectId, int $fileId): void
    {
        $this->find($owner, $projectId)->projectFiles()->where('file_id', $fileId)->delete();
    }

    /**
     * What the model reads in a conversation that belongs to a project: its name, description,
     * instructions and file names. Null when the conversation is in no project.
     */
    public function systemMessage(AiConversation $conversation): ?string
    {
        $projects = AiProject::query()
            ->where('owner_type', $conversation->owner_type)->where('owner_id', $conversation->owner_id)
            ->whereHas('projectConversations', fn ($q) => $q->where('conversation_id', $conversation->id))
            ->with([
                'instructions' => fn ($q) => $q->where('status', true)->orderByDesc('priority'),
                'projectFiles.file:id,file_name',
                'contextItems' => fn ($q) => $q->latest('id')->limit(self::MAX_CONTEXT_DECISIONS + 1),
            ])
            ->get();

        if ($projects->isEmpty()) {
            return null;
        }

        return $projects->map(function (AiProject $project) {
            $lines = ['This conversation belongs to the user\'s project "'.$project->name.'".'];
            if (filled($project->description)) {
                $lines[] = 'About the project: '.Str::limit((string) $project->description, 1000);
            }
            foreach ($project->instructions as $instruction) {
                $lines[] = 'Project instruction from the user: '.Str::limit((string) $instruction->instruction, 2000);
            }
            // The project's long-term memory (ai_project_context), kept from its conversations' context capsules.
            $summary = $project->contextItems->firstWhere('context_type', AiProjectContext::TYPE_SUMMARY);
            if ($summary) {
                $lines[] = 'What this project is about so far: '.Str::limit((string) $summary->content, 1500);
            }
            $decisions = $project->contextItems->where('context_type', AiProjectContext::TYPE_DECISION)->take(self::MAX_CONTEXT_DECISIONS);
            if ($decisions->isNotEmpty()) {
                $lines[] = "Decisions already taken in this project:
- ".$decisions->pluck('content')->map(fn ($d) => Str::limit((string) $d, 300))->implode("
- ");
            }
            $files = $project->projectFiles->pluck('file.file_name')->filter();
            if ($files->isNotEmpty()) {
                $lines[] = 'Files in this project: '.$files->take(30)->implode(', ').'.';
            }

            return implode("\n", $lines);
        })->implode("\n\n");
    }

    /**
     * Writes ai_project_context when a project conversation produces context: a context capsule
     * ("continue in a new chat", AiContextContinuationService) built from conversations that belong
     * to the person's projects. Each such project keeps one summary row (the capsule's goal + what
     * was done, updated in place) and its decisions (one row each, no duplicates, newest
     * MAX_CONTEXT_DECISIONS kept). Read back by systemMessage(). Failure-safe: the capsule itself
     * never fails because of this.
     *
     * @param  Collection<int, AiConversation>  $sources
     * @param  array<string, mixed>  $capsule
     */
    public function rememberCapsule(Authenticatable $owner, Collection $sources, array $capsule): void
    {
        try {
            $projects = $this->query($owner)
                ->whereHas('projectConversations', fn ($q) => $q->whereIn('conversation_id', $sources->pluck('id')->all()))
                ->get();

            foreach ($projects as $project) {
                $summary = trim(implode("
", array_filter([
                    is_string($capsule['goal'] ?? null) ? $capsule['goal'] : null,
                    ! empty($capsule['done']) ? 'Done: '.implode('; ', array_map('strval', (array) $capsule['done'])) : null,
                ])));

                if ($summary !== '') {
                    $project->contextItems()->updateOrCreate(
                        ['context_type' => AiProjectContext::TYPE_SUMMARY],
                        ['content' => Str::limit($summary, 2000)],
                    );
                }

                $known = $project->contextItems()->where('context_type', AiProjectContext::TYPE_DECISION)->pluck('content')->all();
                foreach ((array) ($capsule['decisions'] ?? []) as $decision) {
                    $decision = Str::limit(trim((string) $decision), 300);
                    if ($decision !== '' && ! in_array($decision, $known, true)) {
                        $project->contextItems()->create(['context_type' => AiProjectContext::TYPE_DECISION, 'content' => $decision]);
                        $known[] = $decision;
                    }
                }

                $keep = $project->contextItems()->where('context_type', AiProjectContext::TYPE_DECISION)
                    ->latest('id')->limit(self::MAX_CONTEXT_DECISIONS)->pluck('id');
                $project->contextItems()->where('context_type', AiProjectContext::TYPE_DECISION)->whereNotIn('id', $keep)->delete();
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** The project's one instruction text from the person (null or empty removes it). */
    protected function setInstructions(AiProject $project, ?string $text): void
    {
        // The person's text replaces the project's instructions for good (as before the admin trash existed), so
        // re-saving a project never piles copies into the admin screen's trash.
        $project->instructions()->withTrashed()->forceDelete();
        if (filled($text)) {
            $project->instructions()->create(['instruction' => trim((string) $text), 'priority' => 0, 'status' => true]);
        }
    }

    protected function find(Authenticatable $owner, int $id): AiProject
    {
        return $this->query($owner)->find($id) ?? throw new AiAssistantException('project_not_found', 404);
    }

    protected function query(Authenticatable $owner)
    {
        return AiProject::query()->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->getAuthIdentifier());
    }
}
