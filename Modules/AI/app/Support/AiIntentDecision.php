<?php

namespace Modules\AI\Support;

/**
 * What the chat layer should do differently for ONE message, on top of
 * what the static lexicon (AiChatLexicon) already decides on its own.
 *
 * `source` records where the decision came from, so logs and tests can
 * tell the free paths from the paid one:
 *  - lexicon: the built-in dictionary already understood the message, so
 *    no extra flags are needed (the existing gates handle it);
 *  - learned: a phrase the system previously learned (ai_learned_intents);
 *  - model:   the default model was asked just now;
 *  - none:    nothing actionable (plain conversation, or router skipped).
 */
final class AiIntentDecision
{
    /**
     * @param  list<string>  $intents  Subset of AiChatIntent::ACTIONS.
     */
    public function __construct(
        public readonly array $intents = [],
        public readonly ?string $fileFormat = null,
        public readonly string $source = 'none',
        public readonly float $confidence = 0.0,
    ) {}

    public static function none(string $source = 'none'): self
    {
        return new self([], null, $source, 0.0);
    }

    public function has(string $intent): bool
    {
        return in_array($intent, $this->intents, true);
    }

    public function isEmpty(): bool
    {
        return $this->intents === [];
    }

    public function wantsVoiceReply(): bool
    {
        return $this->has(AiChatIntent::VOICE_REPLY);
    }

    public function wantsFileOutput(): bool
    {
        return $this->has(AiChatIntent::FILE_OUTPUT);
    }

    public function wantsImageEdit(): bool
    {
        return $this->has(AiChatIntent::IMAGE_EDIT);
    }

    /**
     * Extra required ai_provider_models capabilities this decision adds
     * to what AiRequiredCapabilityResolver found on its own.
     *
     * @return list<string>
     */
    public function capabilities(): array
    {
        $capabilities = [];

        foreach ($this->intents as $intent) {
            if (isset(AiChatIntent::CAPABILITY_BY_INTENT[$intent])) {
                $capabilities[] = AiChatIntent::CAPABILITY_BY_INTENT[$intent];
            }
        }

        return array_values(array_unique($capabilities));
    }
}
