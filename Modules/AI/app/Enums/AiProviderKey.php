<?php

namespace Modules\AI\Enums;

enum AiProviderKey: string
{
    case OpenAi = 'openai';
    case Anthropic = 'anthropic';
    case Google = 'google';
    case Groq = 'groq';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::OpenAi => 'OpenAI (ChatGPT)',
            self::Anthropic => 'Anthropic (Claude)',
            self::Google => 'Google (Gemini)',
            self::Groq => 'Groq',
        };
    }
}
