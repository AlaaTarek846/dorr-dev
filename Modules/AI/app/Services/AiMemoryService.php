<?php

namespace Modules\AI\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Modules\AI\Exceptions\AiAssistantException;
use Modules\AI\Models\AiMemory;
use Modules\AI\Models\AiOwnerSetting;

/**
 * DORR AI memory — MemoryService (spec 140–144, 318, 328).
 *
 * Only what the person explicitly asked to keep: from the memory screen, or a message that starts
 * with "remember that…" / "افتكر إن…". Nothing is ever saved on its own. The person sees every
 * memory, edits or deletes it, or switches memory off — then nothing is saved and nothing is sent
 * to the model. Secrets (passwords, keys, card numbers) are refused even when asked (spec 144).
 */
class AiMemoryService
{
    /** How many memories one person keeps, and how long one may be. */
    public const MAX_MEMORIES = 100;

    public const MAX_LENGTH = 500;

    /** How many go into one answer's instructions (the newest). */
    private const IN_PROMPT = 40;

    /** A message that asks to be remembered: the fact is what follows. */
    private const EXPLICIT = '/^\s*(?:من فضلك\s+|لو سمحت\s+)?(?:افتكر|إفتكر|تذكر|تذكّر|اتذكر|احفظ(?:\s+في\s+(?:ذاكرتك|الذاكرة))?|خلي\s+في\s+بالك)\s+(?:إن|ان|أن|اني|إني|أني)?\s*[:،,]?\s*(.+)$/isu';

    private const EXPLICIT_EN = '/^\s*(?:please\s+)?(?:remember|keep\s+in\s+mind|note)\s+(?:that\s+)?[:,]?\s*(.+)$/isu';

    public function __construct(protected AiPiiSanitizer $sanitizer) {}

    public function enabled(Authenticatable $owner): bool
    {
        return AiOwnerSetting::for($owner)->memory_enabled;
    }

    /** @return Collection<int, AiMemory> newest first */
    public function list(Authenticatable $owner): Collection
    {
        return $this->query($owner)->latest('id')->get();
    }

    public function setEnabled(Authenticatable $owner, bool $enabled): void
    {
        $setting = AiOwnerSetting::for($owner);
        $setting->memory_enabled = $enabled;
        $setting->save();
    }

    public function remember(Authenticatable $owner, string $content, ?int $conversationId = null): AiMemory
    {
        if (! $this->enabled($owner)) {
            throw new AiAssistantException('memory_disabled');
        }
        if ($this->query($owner)->count() >= self::MAX_MEMORIES) {
            throw new AiAssistantException('memory_full');
        }

        return AiMemory::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getAuthIdentifier(),
            'content' => $this->clean($content),
            'source_conversation_id' => $conversationId,
        ]);
    }

    public function update(Authenticatable $owner, int $id, string $content): AiMemory
    {
        $memory = $this->find($owner, $id);
        $memory->update(['content' => $this->clean($content)]);

        return $memory;
    }

    public function delete(Authenticatable $owner, int $id): void
    {
        $this->find($owner, $id)->delete();
    }

    public function deleteAll(Authenticatable $owner): int
    {
        return $this->query($owner)->delete();
    }

    /**
     * The fact a message asks to keep ("افتكر إن عيد ميلادي 5 مارس" → "عيد ميلادي 5 مارس"), or null.
     */
    public function explicitRequest(string $message): ?string
    {
        foreach ([self::EXPLICIT, self::EXPLICIT_EN] as $pattern) {
            if (preg_match($pattern, $message, $m) === 1) {
                // Not trim(): it works on bytes, and "؟" shares its first byte with Arabic letters.
                $fact = preg_replace('/^[\s.!?؟]+|[\s.!?؟]+$/u', '', $m[1]) ?? '';

                return mb_strlen($fact) >= 3 ? $fact : null;
            }
        }

        return null;
    }

    /**
     * The memories as one instruction for the model, or null (memory off, or nothing kept).
     */
    public function systemMessage(Authenticatable $owner): ?string
    {
        if (! $this->enabled($owner)) {
            return null;
        }

        $memories = $this->query($owner)->latest('id')->limit(self::IN_PROMPT)->pluck('content');
        if ($memories->isEmpty()) {
            return null;
        }

        return "What this user explicitly asked you to remember (use it only when it is relevant to their request; do not list it unprompted):\n- "
            .$memories->implode("\n- ");
    }

    /** Trimmed and checked: never empty, never too long, never a secret (spec 144). */
    protected function clean(string $content): string
    {
        $content = trim(preg_replace('/\s+/u', ' ', $content) ?? '');

        if ($content === '') {
            throw new AiAssistantException('memory_empty');
        }
        if (mb_strlen($content) > self::MAX_LENGTH) {
            throw new AiAssistantException('memory_too_long');
        }
        if ($this->isSecret($content)) {
            throw new AiAssistantException('memory_secret_refused');
        }

        return $content;
    }

    protected function isSecret(string $content): bool
    {
        return $this->sanitizer->redactSecrets($content) !== $content
            // A card number (13–19 digits, spaces or dashes allowed).
            || preg_match('/(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)/', $content) === 1
            || preg_match('/(كلمة\s*(?:السر|المرور|سر)|الباسورد|باسورد|الرقم\s*السري|رمز\s*(?:الدخول|التحقق)|\bpin\b|\bcvv\b|\botp\b|password)/iu', $content) === 1;
    }

    protected function find(Authenticatable $owner, int $id): AiMemory
    {
        return $this->query($owner)->whereKey($id)->first() ?? throw new AiAssistantException('memory_not_found', 404);
    }

    protected function query(Authenticatable $owner)
    {
        return AiMemory::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getAuthIdentifier());
    }
}
