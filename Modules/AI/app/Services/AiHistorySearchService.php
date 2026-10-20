<?php

namespace Modules\AI\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Modules\AI\Exceptions\AiAssistantException;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiConversationAttachment;
use Modules\AI\Models\AiMessage;
use Modules\AI\Models\AiProject;

/**
 * Search in the DORR AI history — SemanticHistorySearch (spec 133–139, 150, 314, 330).
 *
 * Lexical: the words themselves in conversation titles, messages, file names and projects, with
 * Arabic spelling variants (أ/إ/آ/ا, ة/ه, ى/ي) treated as the same letter. Semantic: DORR AI widens
 * the query into what it means — synonyms, dialect and the other language — so "الكورة" finds a
 * chat about football that never says the word (spec 139). That call runs on the DORR AI system
 * (AiTaskRunner); when no AI is available the lexical search still answers.
 *
 * Each hit: where it is (conversation, message), a snippet with the matched word's position for the
 * highlight (spec 136–137), its date, and the message id the app jumps to (spec 138).
 */
class AiHistorySearchService
{
    public const MAX_RESULTS = 50;

    private const PER_TERM = 30;

    private const SNIPPET_AROUND = 60;

    public function __construct(protected AiTaskRunner $runner) {}

    /**
     * @return array{query: string, terms: list<string>, results: list<array<string, mixed>>}
     */
    public function search(Authenticatable $owner, string $query): array
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
        if (mb_strlen($query) < 2 || mb_strlen($query) > 100) {
            throw new AiAssistantException('search_query');
        }

        $related = $this->related($owner, $query);
        $hits = collect();

        foreach ([[$query, 'lexical'], ...array_map(fn ($t) => [$t, 'semantic'], $related)] as [$term, $match]) {
            foreach ($this->find($owner, $term) as $hit) {
                $key = $hit['type'].':'.($hit['message_id'] ?? $hit['conversation']['id']).':'.($hit['file'] ?? '');
                if (! $hits->has($key)) {
                    $hits->put($key, $hit + ['match' => $match, 'term' => $term]);
                }
            }
            if ($hits->count() >= self::MAX_RESULTS) {
                break;
            }
        }

        // The words themselves first, then what they mean; newest first within each.
        $results = $hits->values()
            ->sortBy([fn ($a, $b) => ($a['match'] === 'lexical' ? 0 : 1) <=> ($b['match'] === 'lexical' ? 0 : 1), fn ($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at'])])
            ->take(self::MAX_RESULTS)->values()->all();

        return ['query' => $query, 'terms' => $related, 'results' => $results];
    }

    /**
     * What the query means, as other words to look for (cached a day per query and language).
     *
     * @return list<string>
     */
    protected function related(Authenticatable $owner, string $query): array
    {
        return Cache::remember('ai:search:related:'.md5(mb_strtolower($query)), now()->addDay(), function () use ($owner, $query) {
            $result = $this->runner->chat([
                ['role' => 'system', 'content' => 'You help search a person\'s past conversations by meaning. For the search query, give up to 8 short search terms that '
                    .'a conversation about the same thing would contain: synonyms, the formal and the colloquial/dialect forms, related key words, and the English or Arabic equivalent. '
                    .'One to three words each. Reply with a JSON array of strings only.'],
                ['role' => 'user', 'content' => $query],
            ], 'ai.search', $owner);

            if (! $result['success']) {
                return [];
            }

            $decoded = json_decode(trim((string) preg_replace('/^```(?:json)?|```$/m', '', (string) $result['content'])), true);

            return collect(is_array($decoded) ? $decoded : [])
                ->filter(fn ($t) => is_string($t) && mb_strlen(trim($t)) >= 2 && mb_strlen(trim($t)) <= 40)
                ->map(fn (string $t) => trim($t))
                ->reject(fn (string $t) => $this->normalize($t)[0] === $this->normalize($query)[0])
                ->unique(fn (string $t) => $this->normalize($t)[0])->take(8)->values()->all();
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function find(Authenticatable $owner, string $term): array
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->normalize($term)[0]).'%';
        $matches = fn (string $column) => fn ($q) => $q->whereRaw($this->normalizedSql($column)." like ? escape '\\'", [$like]);
        $mine = fn ($q) => $q->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->getAuthIdentifier());
        $hits = [];

        foreach (AiConversation::query()->where($mine)->where($matches('title'))->latest('updated_at')->limit(self::PER_TERM)->get() as $conversation) {
            if ($hit = $this->hit('title', (string) $conversation->title, $term, $conversation, $conversation->updated_at)) {
                $hits[] = $hit;
            }
        }

        $messages = AiMessage::query()
            ->whereIn('role', [AiMessage::ROLE_USER, AiMessage::ROLE_ASSISTANT])
            ->whereHas('conversation', $mine)
            ->where($matches('content'))
            ->with('conversation:id,title')
            ->latest('id')->limit(self::PER_TERM)->get();
        foreach ($messages as $message) {
            if ($hit = $this->hit('message', (string) $message->content, $term, $message->conversation, $message->created_at)) {
                $hits[] = $hit + ['message_id' => $message->id, 'role' => $message->role];
            }
        }

        $attachments = AiConversationAttachment::query()
            ->whereHas('conversation', $mine)
            ->where($matches('file_name'))
            ->with('conversation:id,title')
            ->latest('id')->limit(self::PER_TERM)->get();
        foreach ($attachments as $attachment) {
            if ($hit = $this->hit('file', (string) $attachment->file_name, $term, $attachment->conversation, $attachment->created_at)) {
                $hits[] = $hit + ['message_id' => $attachment->message_id, 'file' => $attachment->file_name];
            }
        }

        $projects = AiProject::query()->where($mine)
            ->where(fn ($q) => $q->where($matches('name'))->orWhere($matches('description')))
            ->with('projectConversations.conversation:id,title,updated_at')
            ->limit(self::PER_TERM)->get();
        foreach ($projects as $project) {
            $text = $this->locate((string) $project->name, $term) !== null ? (string) $project->name : (string) $project->description;
            foreach ($project->projectConversations->pluck('conversation')->filter() as $conversation) {
                if ($hit = $this->hit('project', $text, $term, $conversation, $conversation->updated_at)) {
                    $hits[] = $hit + ['project' => ['id' => $project->id, 'name' => $project->name]];
                }
            }
        }

        return $hits;
    }

    /**
     * One hit with its snippet and the highlight inside it, or null when the match was only a
     * loose SQL one (the PHP check is the exact, spelling-aware one).
     *
     * @return array<string, mixed>|null
     */
    protected function hit(string $type, string $text, string $term, ?AiConversation $conversation, ?Carbon $date): ?array
    {
        if ($conversation === null) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        $found = $this->locate($text, $term);
        if ($found === null) {
            return null;
        }

        [$at, $length] = $found;
        $start = max(0, $at - self::SNIPPET_AROUND);
        $snippet = mb_substr($text, $start, $length + 2 * self::SNIPPET_AROUND);
        $prefix = $start > 0 ? '…' : '';
        $suffix = $start + mb_strlen($snippet) < mb_strlen($text) ? '…' : '';

        return [
            'type' => $type,
            'conversation' => ['id' => $conversation->id, 'title' => $conversation->title],
            'snippet' => $prefix.$snippet.$suffix,
            'highlights' => [['start' => mb_strlen($prefix) + ($at - $start), 'length' => $length]],
            'created_at' => $date?->toIso8601String(),
        ];
    }

    /** Arabic letters spelled interchangeably, made one. */
    private const SAME_LETTER = ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي'];

    /** Diacritics and tatweel: ignored when matching. */
    private const IGNORED = ["\u{064B}", "\u{064C}", "\u{064D}", "\u{064E}", "\u{064F}", "\u{0650}", "\u{0651}", "\u{0652}", 'ـ'];

    /**
     * The text lower-cased, with the interchangeable letters made one and the ignored marks removed,
     * plus where each of its characters came from in the original (for the highlight).
     *
     * @return array{0: string, 1: list<int>}
     */
    public function normalize(string $text): array
    {
        $out = '';
        $map = [];
        foreach (mb_str_split(mb_strtolower($text)) as $i => $char) {
            if (in_array($char, self::IGNORED, true)) {
                continue;
            }
            $out .= self::SAME_LETTER[$char] ?? $char;
            $map[] = $i;
        }

        return [$out, $map];
    }

    /**
     * Where the term is in the text (start and length in the original's characters), or null.
     *
     * @return array{0: int, 1: int}|null
     */
    protected function locate(string $text, string $term): ?array
    {
        [$haystack, $map] = $this->normalize($text);
        $needle = $this->normalize($term)[0];
        $at = $needle === '' ? false : mb_strpos($haystack, $needle);
        if ($at === false) {
            return null;
        }

        $first = $map[$at];
        $last = $map[$at + mb_strlen($needle) - 1];

        return [$first, $last - $first + 1];
    }

    /** The same normalization in SQL (MySQL and SQLite both have REPLACE). */
    protected function normalizedSql(string $column): string
    {
        $sql = 'lower('.$column.')';
        foreach (self::SAME_LETTER as $from => $to) {
            $sql = "replace({$sql}, '{$from}', '{$to}')";
        }
        foreach (self::IGNORED as $mark) {
            $sql = "replace({$sql}, '{$mark}', '')";
        }

        return $sql;
    }
}
