<?php

namespace Modules\AI\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\AI\Exceptions\AiAssistantException;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiConversationContext;
use Modules\AI\Models\AiMessage;
use Modules\AI\Repositories\AiConversationRepository;

/**
 * "Continue in a new chat" — ContextContinuationService (spec 121–132, 329).
 *
 * The person picks one or more of their own conversations; DORR AI builds a Context Capsule from
 * them — the goal, the decisions, the important facts, the files used, the open tasks and what was
 * done — and starts a new conversation that carries it (as an included conversation context, so
 * every answer there sees it). Only the conversations they picked: unrelated ones are never mixed
 * in (spec 132). They can see the capsule and delete it at any time (spec 130).
 */
class AiContextContinuationService
{
    public const MAX_SOURCES = 5;

    /** How much of the source conversations the capsule is built from. */
    private const MAX_CHARS = 14000;

    private const PARTS = ['goal', 'decisions', 'facts', 'open_tasks', 'done'];

    public function __construct(
        protected AiConversationRepository $conversations,
        protected AiTaskRunner $runner,
    ) {}

    /**
     * @param  list<int>  $sourceIds
     */
    public function continueFrom(Authenticatable $owner, array $sourceIds): AiConversation
    {
        $sourceIds = array_values(array_unique(array_map('intval', $sourceIds)));
        if ($sourceIds === [] || count($sourceIds) > self::MAX_SOURCES) {
            throw new AiAssistantException('capsule_sources');
        }

        // Only the person's own conversations — anything else is simply "not found".
        $sources = collect($sourceIds)->map(fn (int $id) => $this->conversations->findForOwner($owner, $id));
        if ($sources->every(fn (AiConversation $c) => $c->messages->isEmpty())) {
            throw new AiAssistantException('capsule_empty');
        }

        $result = $this->runner->chat([
            ['role' => 'system', 'content' => 'You prepare a "context capsule" so a person can continue their work in a new conversation without starting over. '
                .'Read the conversations below and reply with JSON only, in the language the person wrote in: '
                .'{"goal": "<what they are trying to achieve, one sentence>", "decisions": [<decisions taken>], "facts": [<important facts, numbers, names, dates>], '
                .'"open_tasks": [<what is still left to do>], "done": [<what was already completed>]}. '
                .'Short items. Only what the conversations really say — never invent anything; an empty list when there is nothing.'],
            ['role' => 'user', 'content' => $this->transcript($sources)],
        ], 'ai.capsule', $owner);

        if (! $result['success']) {
            throw match ($result['reason']) {
                AiTaskRunner::REASON_PLAN, AiTaskRunner::REASON_OWNER_BLOCKED => new AiAssistantException('plan_required', 402),
                AiTaskRunner::REASON_UNAVAILABLE => new AiAssistantException('unavailable', 502),
                default => new AiAssistantException('capsule_failed', 502),
            };
        }

        $capsule = $this->parse((string) $result['content']) + [
            'files' => $this->files($sources),
            'sources' => $sources->map(fn (AiConversation $c) => ['id' => $c->id, 'title' => $c->title])->values()->all(),
        ];

        $conversation = $this->conversations->createForOwner($owner);
        $conversation->title = Str::limit(__('ai.assistant_capsule_title', ['title' => $sources->first()->title ?: __('ai.assistant_capsule_untitled')]), 120);
        $conversation->save();

        $conversation->contexts()->create([
            'context_type' => AiConversationContext::TYPE_CAPSULE,
            'content' => json_encode($capsule, JSON_UNESCAPED_UNICODE),
            'included' => true,
        ]);

        return $conversation;
    }

    /** @return array<string, mixed>|null */
    public function show(Authenticatable $owner, int $conversationId): ?array
    {
        $context = $this->capsuleOf($this->conversations->findForOwner($owner, $conversationId));

        return $context ? $this->decode($context->content) : null;
    }

    public function delete(Authenticatable $owner, int $conversationId): void
    {
        $this->conversations->findForOwner($owner, $conversationId)
            ->contexts()->where('context_type', AiConversationContext::TYPE_CAPSULE)->delete();
    }

    /**
     * The capsule as the instruction the model reads in the new conversation.
     */
    public function render(string $json): string
    {
        $capsule = $this->decode($json);
        $lines = ['Context carried over by the user from their earlier conversation(s) '
            .collect($capsule['sources'] ?? [])->map(fn ($s) => '"'.($s['title'] ?? '').'"')->implode(', ')
            .'. Treat it as what you already know about this work; the user can see and delete it.'];

        if (filled($capsule['goal'] ?? null)) {
            $lines[] = 'Goal: '.$capsule['goal'];
        }
        foreach (['decisions' => 'Decisions', 'facts' => 'Important facts', 'files' => 'Files used', 'open_tasks' => 'Open tasks', 'done' => 'Already done'] as $key => $label) {
            if (! empty($capsule[$key])) {
                $lines[] = $label.":\n- ".implode("\n- ", array_map('strval', $capsule[$key]));
            }
        }

        return implode("\n", $lines);
    }

    public function capsuleOf(AiConversation $conversation): ?AiConversationContext
    {
        return $conversation->contexts()->where('context_type', AiConversationContext::TYPE_CAPSULE)->latest('id')->first();
    }

    /** @param  Collection<int, AiConversation>  $sources */
    protected function transcript(Collection $sources): string
    {
        $budget = intdiv(self::MAX_CHARS, $sources->count());

        return $sources->map(function (AiConversation $conversation) use ($budget) {
            $lines = collect();
            $chars = 0;
            // Newest first while it fits, then back in order.
            foreach ($conversation->messages->whereIn('role', [AiMessage::ROLE_USER, AiMessage::ROLE_ASSISTANT])->sortByDesc('id') as $message) {
                $line = ($message->role === AiMessage::ROLE_USER ? 'User' : 'DORR AI').': '.Str::limit((string) $message->content, 800);
                $chars += mb_strlen($line);
                if ($chars > $budget) {
                    break;
                }
                $lines->prepend($line);
            }

            return '### Conversation "'.($conversation->title ?: '-')."\"\n".$lines->implode("\n");
        })->implode("\n\n");
    }

    /**
     * File names used in the source conversations (attachments and attached files) — read from the
     * records, not from the model.
     *
     * @param  Collection<int, AiConversation>  $sources
     * @return list<string>
     */
    protected function files(Collection $sources): array
    {
        return $sources->flatMap(fn (AiConversation $c) => $c->messages->flatMap->attachments->pluck('file_name')
            ->merge($c->conversationFiles()->whereNull('detached_at')->with('file')->get()->pluck('file.file_name')))
            ->filter()->unique()->take(30)->values()->all();
    }

    /** @return array<string, mixed> */
    protected function parse(string $raw): array
    {
        $clean = trim((string) preg_replace('/^```(?:json)?|```$/m', '', $raw));
        $json = json_decode($clean, true);
        if (! is_array($json)) {
            $json = json_decode(substr($clean, strcspn($clean, '{')), true);
        }
        if (! is_array($json)) {
            throw new AiAssistantException('capsule_failed', 502);
        }

        $out = ['goal' => is_string($json['goal'] ?? null) ? Str::limit(trim($json['goal']), 300) : null];
        foreach (array_diff(self::PARTS, ['goal']) as $part) {
            $out[$part] = collect((array) ($json[$part] ?? []))->filter(fn ($v) => is_string($v) && trim($v) !== '')
                ->map(fn (string $v) => Str::limit(trim($v), 300))->take(15)->values()->all();
        }

        return $out;
    }

    /** @return array<string, mixed> */
    protected function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
